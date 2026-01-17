<?php

declare(strict_types=1);

namespace App\Services;

use App\Agents\AgentDriverRegistry;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class ConfigService
{
    private const VALID_COMPLEXITIES = ['trivial', 'simple', 'moderate', 'complex'];

    private const DEFAULT_MAX_CONCURRENT = 2;

    private const DEFAULT_MAX_ATTEMPTS = 3;

    private const DEFAULT_MAX_RETRIES = 5;

    private const DEFAULT_CONSUME_PORT = 9981;

    private const DEFAULT_GLOBAL_MAX_CONCURRENT = 50;

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(
        private readonly FuelContext $context,
        private readonly AgentDriverRegistry $driverRegistry = new AgentDriverRegistry
    ) {}

    /**
     * Reload configuration from disk.
     * Clears the cached config so the next access will re-read the file.
     */
    public function reload(): void
    {
        $this->config = null;
    }

    /**
     * Get the config file path.
     */
    public function getConfigPath(): string
    {
        return $this->context->getConfigPath();
    }

    /**
     * Load configuration from YAML file.
     *
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        if (! file_exists($this->getConfigPath())) {
            throw new RuntimeException('Config file not found: '.$this->getConfigPath());
        }

        $content = file_get_contents($this->getConfigPath());
        if ($content === false) {
            throw new RuntimeException('Failed to read config file: '.$this->getConfigPath());
        }

        try {
            $parsed = Yaml::parse($content);
        } catch (\Exception $exception) {
            throw new RuntimeException('Failed to parse YAML config: '.$exception->getMessage(), $exception->getCode(), $exception);
        }

        if (! is_array($parsed)) {
            throw new RuntimeException('Invalid config format: expected array, got '.gettype($parsed));
        }

        $this->config = $parsed;

        $this->validateConfig($this->config);

        return $this->config;
    }

    /**
     * Validate configuration by loading and validating it.
     * Throws RuntimeException if validation fails.
     */
    public function validate(): void
    {
        $this->loadConfig();
    }

    /**
     * Validate configuration structure and values.
     *
     * @param  array<string, mixed>  $config
     */
    private function validateConfig(array $config): void
    {
        // Validate agents section exists and has valid structure
        if (! isset($config['agents']) || ! is_array($config['agents'])) {
            throw new RuntimeException('Config must have "agents" key with array value');
        }

        foreach ($config['agents'] as $agentName => $agentConfig) {
            if (! is_string($agentName)) {
                throw new RuntimeException('Agent names must be strings');
            }

            if (! is_array($agentConfig)) {
                throw new RuntimeException(sprintf("Agent '%s' config must be an array", $agentName));
            }

            // Driver is required
            if (! isset($agentConfig['driver']) || ! is_string($agentConfig['driver'])) {
                $availableDrivers = implode(', ', array_keys($this->driverRegistry->all()));
                throw new RuntimeException(sprintf(
                    "Agent '%s' must have 'driver' string. Available drivers: %s. To regenerate config: rm .fuel/config.yaml && fuel init",
                    $agentName,
                    $availableDrivers
                ));
            }

            // Validate driver exists
            if (! $this->driverRegistry->has($agentConfig['driver'])) {
                $availableDrivers = implode(', ', array_keys($this->driverRegistry->all()));
                throw new RuntimeException(sprintf(
                    "Agent '%s' references unknown driver '%s'. Available drivers: %s",
                    $agentName,
                    $agentConfig['driver'],
                    $availableDrivers
                ));
            }

            // Validate optional fields have correct types
            if (isset($agentConfig['model']) && ! is_string($agentConfig['model'])) {
                throw new RuntimeException(sprintf("Agent '%s' model must be a string", $agentName));
            }

            if (isset($agentConfig['extra_args']) && ! is_array($agentConfig['extra_args'])) {
                throw new RuntimeException(sprintf("Agent '%s' extra_args must be an array", $agentName));
            }

            if (isset($agentConfig['extra_env']) && ! is_array($agentConfig['extra_env'])) {
                throw new RuntimeException(sprintf("Agent '%s' extra_env must be an array", $agentName));
            }

            if (isset($agentConfig['max_concurrent']) && ! is_int($agentConfig['max_concurrent'])) {
                throw new RuntimeException(sprintf("Agent '%s' max_concurrent must be an integer", $agentName));
            }

            if (isset($agentConfig['max_attempts']) && ! is_int($agentConfig['max_attempts'])) {
                throw new RuntimeException(sprintf("Agent '%s' max_attempts must be an integer", $agentName));
            }

            if (isset($agentConfig['max_retries']) && ! is_int($agentConfig['max_retries'])) {
                throw new RuntimeException(sprintf("Agent '%s' max_retries must be an integer", $agentName));
            }
        }

        // Validate complexity section
        if (! isset($config['complexity']) || ! is_array($config['complexity'])) {
            throw new RuntimeException('Config must have "complexity" key with array value');
        }

        foreach ($config['complexity'] as $complexity => $complexityConfig) {
            if (! in_array($complexity, self::VALID_COMPLEXITIES, true)) {
                throw new RuntimeException(
                    sprintf("Invalid complexity '%s'. Must be one of: ", $complexity).implode(', ', self::VALID_COMPLEXITIES)
                );
            }

            // Complexity can be a string (agent name) or array with 'agent' key
            $agentName = $this->extractAgentName($complexityConfig);
            if ($agentName === null) {
                throw new RuntimeException(
                    sprintf("Complexity '%s' must be a string (agent name) or array with 'agent' key", $complexity)
                );
            }

            // Validate agent reference exists
            if (! isset($config['agents'][$agentName])) {
                throw new RuntimeException(
                    sprintf("Complexity '%s' references undefined agent '%s'", $complexity, $agentName)
                );
            }
        }

        // Validate primary agent (required)
        if (! isset($config['primary'])) {
            throw new RuntimeException("Config must have 'primary' key specifying the primary agent for orchestration");
        }

        if (! is_string($config['primary'])) {
            throw new RuntimeException("Config 'primary' must be a string (agent name)");
        }

        if (! isset($config['agents'][$config['primary']])) {
            throw new RuntimeException(
                sprintf("Primary agent '%s' is not defined in agents section", $config['primary'])
            );
        }

        // Validate port if specified
        if (isset($config['port']) && (! is_int($config['port']) || $config['port'] < 1 || $config['port'] > 65535)) {
            throw new RuntimeException('Config port must be an integer between 1 and 65535');
        }

        // Validate global max_concurrent if specified
        if (isset($config['max_concurrent']) && (! is_int($config['max_concurrent']) || $config['max_concurrent'] < 1)) {
            throw new RuntimeException('Config max_concurrent must be a positive integer');
        }
    }

    /**
     * Extract agent name from complexity config.
     * Supports both string format and array with 'agent' key.
     */
    private function extractAgentName(mixed $complexityConfig): ?string
    {
        if (is_string($complexityConfig)) {
            return $complexityConfig;
        }

        if (is_array($complexityConfig) && isset($complexityConfig['agent']) && is_string($complexityConfig['agent'])) {
            return $complexityConfig['agent'];
        }

        return null;
    }

    /**
     * Get the agent name for a given complexity level.
     */
    public function getAgentForComplexity(string $complexity): string
    {
        $this->validateComplexity($complexity);

        $config = $this->loadConfig();

        if (! isset($config['complexity'][$complexity])) {
            throw new RuntimeException(sprintf("No agent configured for complexity '%s'", $complexity));
        }

        $complexityConfig = $config['complexity'][$complexity];
        $agentName = $this->extractAgentName($complexityConfig);

        if ($agentName === null) {
            throw new RuntimeException(sprintf("No agent configured for complexity '%s'", $complexity));
        }

        return $agentName;
    }

    /**
     * Get full agent definition by name.
     * Merges driver defaults with user overrides (extra_args, extra_env).
     *
     * @return array{command: string, prompt_args: array<string>, model_arg: ?string, model: ?string, args: array<string>, env: array<string, string>, resume_args: array<string>, max_concurrent: int, max_attempts: int, max_retries: int}
     */
    public function getAgentDefinition(string $agentName): array
    {
        $config = $this->loadConfig();

        if (! isset($config['agents'][$agentName])) {
            throw new RuntimeException(sprintf("Agent '%s' is not defined", $agentName));
        }

        $agentConfig = $config['agents'][$agentName];
        $driver = $this->driverRegistry->get($agentConfig['driver']);

        // Merge driver defaults with user overrides
        $args = $driver->getDefaultArgs();
        if (isset($agentConfig['extra_args']) && is_array($agentConfig['extra_args'])) {
            $args = array_merge($args, $agentConfig['extra_args']);
        }

        $env = $driver->getDefaultEnv();
        if (isset($agentConfig['extra_env']) && is_array($agentConfig['extra_env'])) {
            $env = array_merge($env, $agentConfig['extra_env']);
        }

        return [
            'command' => $driver->getCommand(),
            'prompt_args' => $driver->getPromptArgs(),
            'model' => $agentConfig['model'] ?? null,
            'model_arg' => $driver->getModelArg(),
            'args' => $args,
            'env' => $env,
            'resume_args' => [],
            'max_concurrent' => $agentConfig['max_concurrent'] ?? self::DEFAULT_MAX_CONCURRENT,
            'max_attempts' => $agentConfig['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS,
            'max_retries' => $agentConfig['max_retries'] ?? self::DEFAULT_MAX_RETRIES,
            'driver' => $driver,
        ];
    }

    /**
     * Get agent command array for given complexity and prompt.
     *
     * Builds: [command, ...prompt_args, prompt, model_arg, model, ...args]
     *
     * @return array<int, string>
     */
    public function getAgentCommand(string $complexity, string $prompt): array
    {
        $agentName = $this->getAgentForComplexity($complexity);

        return $this->buildAgentCommand($agentName, $prompt, $complexity);
    }

    /**
     * Build command array for a specific agent with a prompt.
     *
     * @return array<int, string>
     */
    public function buildAgentCommand(string $agentName, string $prompt, ?string $complexity = null): array
    {
        $agentDef = $this->getAgentDefinition($agentName);

        // Get complexity-specific overrides if provided
        $modelOverride = null;
        $argsOverride = null;

        if ($complexity !== null) {
            $config = $this->loadConfig();
            $complexityConfig = $config['complexity'][$complexity] ?? null;

            if (is_array($complexityConfig)) {
                $modelOverride = $complexityConfig['model'] ?? null;
                $argsOverride = $complexityConfig['args'] ?? null;
            }
        }

        // Use override or default from agent definition
        $model = $modelOverride ?? $agentDef['model'];
        $args = $argsOverride ?? $agentDef['args'];
        $modelArg = $agentDef['model_arg'] ?? '--model';

        // Build command: [command, ...prompt_args, prompt, model_arg?, model?, ...args]
        $cmd = [$agentDef['command']];

        // Add prompt args and prompt
        foreach ($agentDef['prompt_args'] as $promptArg) {
            $cmd[] = $promptArg;
        }

        $cmd[] = $prompt;

        // Add model if specified (using driver's model arg)
        if ($model !== null && is_string($model)) {
            $cmd[] = $modelArg;
            $cmd[] = $model;
        }

        // Add additional args
        foreach ($args as $arg) {
            if (is_string($arg)) {
                $cmd[] = $arg;
            }
        }

        return $cmd;
    }

    /**
     * Get environment variables for an agent.
     *
     * @return array<string, string>
     */
    public function getAgentEnv(string $agentName): array
    {
        $agentDef = $this->getAgentDefinition($agentName);

        return $agentDef['env'];
    }

    /**
     * Get agent configuration for given complexity.
     * Returns the full agent definition merged with any complexity overrides.
     *
     * @return array<string, mixed>
     */
    public function getAgentConfig(string $complexity): array
    {
        $agentName = $this->getAgentForComplexity($complexity);
        $agentDef = $this->getAgentDefinition($agentName);

        // Get complexity-specific overrides
        $config = $this->loadConfig();
        $complexityConfig = $config['complexity'][$complexity];

        if (is_array($complexityConfig)) {
            // Merge overrides
            if (isset($complexityConfig['model'])) {
                $agentDef['model'] = $complexityConfig['model'];
            }

            if (isset($complexityConfig['args'])) {
                $agentDef['args'] = $complexityConfig['args'];
            }
        }

        return array_merge($agentDef, ['name' => $agentName]);
    }

    /**
     * Get the primary agent name for orchestration tasks.
     */
    public function getPrimaryAgent(): string
    {
        $config = $this->loadConfig();

        return $config['primary'];
    }

    /**
     * Get the primary agent definition.
     *
     * @return array{command: string, prompt_args: array<string>, model: ?string, args: array<string>, env: array<string, string>, resume_args: array<string>, max_concurrent: int}
     */
    public function getPrimaryAgentDefinition(): array
    {
        $primaryAgent = $this->getPrimaryAgent();

        return $this->getAgentDefinition($primaryAgent);
    }

    /**
     * Validate complexity value.
     */
    private function validateComplexity(string $complexity): void
    {
        if (! in_array($complexity, self::VALID_COMPLEXITIES, true)) {
            throw new RuntimeException(
                sprintf("Invalid complexity '%s'. Must be one of: ", $complexity).implode(', ', self::VALID_COMPLEXITIES)
            );
        }
    }

    /**
     * Get the review agent name.
     * Falls back to primary agent if not configured.
     * Returns null if neither is configured.
     */
    public function getReviewAgent(): ?string
    {
        $config = $this->loadConfig();

        // Try 'review' first, then fall back to 'primary'
        return $config['review'] ?? $config['primary'] ?? null;
    }

    /**
     * Get the reality agent name.
     * Falls back to primary agent if not configured.
     * Returns null if neither is configured.
     */
    public function getRealityAgent(): ?string
    {
        $config = $this->loadConfig();

        // Try 'reality' first, then fall back to 'primary'
        return $config['reality'] ?? $config['primary'] ?? null;
    }

    /**
     * Get max_concurrent limit for a specific agent.
     * Returns default of 2 if agent is not configured.
     */
    public function getAgentLimit(string $agentName): int
    {
        $config = $this->loadConfig();

        if (! isset($config['agents'][$agentName])) {
            return self::DEFAULT_MAX_CONCURRENT;
        }

        $agentConfig = $config['agents'][$agentName];

        return $agentConfig['max_concurrent'] ?? self::DEFAULT_MAX_CONCURRENT;
    }

    /**
     * Get max_attempts for a specific agent.
     * Returns default of 3 if agent is not configured.
     */
    public function getAgentMaxAttempts(string $agentName): int
    {
        $config = $this->loadConfig();

        if (! isset($config['agents'][$agentName])) {
            return self::DEFAULT_MAX_ATTEMPTS;
        }

        $agentConfig = $config['agents'][$agentName];

        return $agentConfig['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS;
    }

    /**
     * Get max_retries for a specific agent.
     * Returns default of 5 if agent is not configured.
     */
    public function getAgentMaxRetries(string $agentName): int
    {
        $config = $this->loadConfig();

        if (! isset($config['agents'][$agentName])) {
            return self::DEFAULT_MAX_RETRIES;
        }

        $agentConfig = $config['agents'][$agentName];

        return $agentConfig['max_retries'] ?? self::DEFAULT_MAX_RETRIES;
    }

    /**
     * Get all agent -> limit mappings.
     * Returns array with agent name as key and max_concurrent as value.
     *
     * @return array<string, int>
     */
    public function getAgentLimits(): array
    {
        $config = $this->loadConfig();

        if (! isset($config['agents']) || ! is_array($config['agents'])) {
            return [];
        }

        $limits = [];
        foreach ($config['agents'] as $agentName => $agentConfig) {
            if (! is_string($agentName)) {
                continue;
            }

            if (! is_array($agentConfig)) {
                continue;
            }

            $limits[$agentName] = $agentConfig['max_concurrent'] ?? self::DEFAULT_MAX_CONCURRENT;
        }

        return $limits;
    }

    /**
     * Get all defined agent names.
     *
     * @return array<string>
     */
    public function getAgentNames(): array
    {
        $config = $this->loadConfig();

        if (! isset($config['agents']) || ! is_array($config['agents'])) {
            return [];
        }

        return array_keys($config['agents']);
    }

    /**
     * Check if an agent is defined.
     */
    public function hasAgent(string $agentName): bool
    {
        $config = $this->loadConfig();

        return isset($config['agents'][$agentName]);
    }

    /**
     * Create default config file if it doesn't exist.
     */
    public function createDefaultConfig(): void
    {
        if (file_exists($this->getConfigPath())) {
            return; // Don't overwrite existing config
        }

        $dir = dirname($this->getConfigPath());
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $yaml = <<<'YAML'
# Fuel Agent Configuration
port: 9981

# Global maximum concurrent agents (total cap regardless of per-agent limits)
max_concurrent: 5
desktop_notifications: false

# Epic isolation via mirrors - each epic gets its own directory copy (experimental)
epic_mirrors: false

# Primary agent for orchestration/decision-making (required)
primary: claude-opus
review: claude-opus
reality: claude-sonnet

# Map task complexity levels to agents
complexity:
  trivial: claude-sonnet
  simple: claude-sonnet
  moderate: claude-opus
  complex: claude-opus

agents:
  cursor-composer:
    driver: cursor-agent
    model: composer-1
    max_concurrent: 3
    max_attempts: 3

  claude-sonnet:
    driver: claude
    model: sonnet
    max_concurrent: 2
    max_attempts: 3

  claude-opus:
    driver: claude
    model: opus
    max_concurrent: 3
    max_attempts: 5

  opencode-minimax:
    driver: opencode
    model: opencode/minimax-m2.1-free
    max_concurrent: 2
    max_attempts: 3

  amp-smart:
    driver: amp
    model: smart
    max_concurrent: 3
    max_attempts: 3

  codex-complex:
    driver: codex
    model: gpt-5.2-codex
    max_concurrent: 2
    max_attempts: 3
YAML;

        file_put_contents($this->getConfigPath(), $yaml);
    }

    /**
     * Get the driver registry.
     */
    public function getDriverRegistry(): AgentDriverRegistry
    {
        return $this->driverRegistry;
    }

    /**
     * Get the TCP port for IPC communication.
     * Returns configured port or default 9981.
     */
    public function getConsumePort(): int
    {
        $config = $this->loadConfig();

        return $config['port'] ?? self::DEFAULT_CONSUME_PORT;
    }

    /**
     * Get the global maximum concurrent processes.
     * This is the total cap regardless of individual agent limits.
     * Returns configured value or default 5.
     */
    public function getGlobalMaxConcurrent(): int
    {
        $config = $this->loadConfig();

        return $config['max_concurrent'] ?? self::DEFAULT_GLOBAL_MAX_CONCURRENT;
    }

    /**
     * Get desktop notifications setting.
     * Returns true if not set (default enabled) or explicitly set to true.
     * Returns false if set to anything other than true.
     */
    public function getDesktopNotifications(): bool
    {
        $config = $this->loadConfig();

        if (! isset($config['desktop_notifications'])) {
            return true; // Default to enabled
        }

        return $config['desktop_notifications'] === true;
    }

    /**
     * Get epic mirrors enabled setting.
     * Controls whether epic:add spawns mirror creation and whether TaskSpawner routes to mirrors.
     * Returns false if not set (default disabled for safe rollout).
     */
    public function getEpicMirrorsEnabled(): bool
    {
        $config = $this->loadConfig();

        return $config['epic_mirrors'] ?? false;
    }
}
