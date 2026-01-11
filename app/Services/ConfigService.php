<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class ConfigService
{
    private const VALID_COMPLEXITIES = ['trivial', 'simple', 'moderate', 'complex'];

    private const DEFAULT_PROMPT_ARGS = ['-p'];

    private const DEFAULT_MAX_CONCURRENT = 2;

    private const DEFAULT_MAX_ATTEMPTS = 3;

    private const DEFAULT_MAX_RETRIES = 5;

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(private readonly FuelContext $context)
    {
    }

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

            if (! isset($agentConfig['command']) || ! is_string($agentConfig['command'])) {
                throw new RuntimeException(sprintf("Agent '%s' must have 'command' string", $agentName));
            }

            // Validate optional fields have correct types
            if (isset($agentConfig['prompt_args']) && ! is_array($agentConfig['prompt_args'])) {
                throw new RuntimeException(sprintf("Agent '%s' prompt_args must be an array", $agentName));
            }

            if (isset($agentConfig['args']) && ! is_array($agentConfig['args'])) {
                throw new RuntimeException(sprintf("Agent '%s' args must be an array", $agentName));
            }

            if (isset($agentConfig['env']) && ! is_array($agentConfig['env'])) {
                throw new RuntimeException(sprintf("Agent '%s' env must be an array", $agentName));
            }

            if (isset($agentConfig['resume_args']) && ! is_array($agentConfig['resume_args'])) {
                throw new RuntimeException(sprintf("Agent '%s' resume_args must be an array", $agentName));
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
    }

    /**
     * Extract agent name from complexity config.
     * Supports both string format and array with 'agent' key.
     *
     * @param  mixed  $complexityConfig
     */
    private function extractAgentName($complexityConfig): ?string
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
     *
     * @return array{command: string, prompt_args: array<string>, model: ?string, args: array<string>, env: array<string, string>, resume_args: array<string>, max_concurrent: int, max_attempts: int, max_retries: int}
     */
    public function getAgentDefinition(string $agentName): array
    {
        $config = $this->loadConfig();

        if (! isset($config['agents'][$agentName])) {
            throw new RuntimeException(sprintf("Agent '%s' is not defined", $agentName));
        }

        $agentConfig = $config['agents'][$agentName];

        return [
            'command' => $agentConfig['command'],
            'prompt_args' => $agentConfig['prompt_args'] ?? self::DEFAULT_PROMPT_ARGS,
            'model' => $agentConfig['model'] ?? null,
            'args' => $agentConfig['args'] ?? [],
            'env' => $agentConfig['env'] ?? [],
            'resume_args' => $agentConfig['resume_args'] ?? [],
            'max_concurrent' => $agentConfig['max_concurrent'] ?? self::DEFAULT_MAX_CONCURRENT,
            'max_attempts' => $agentConfig['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS,
            'max_retries' => $agentConfig['max_retries'] ?? self::DEFAULT_MAX_RETRIES,
        ];
    }

    /**
     * Get agent command array for given complexity and prompt.
     *
     * Builds: [command, ...prompt_args, prompt, --model, model, ...args]
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

        // Build command: [command, ...prompt_args, prompt, --model?, model?, ...args]
        $cmd = [$agentDef['command']];

        // Add prompt args and prompt
        foreach ($agentDef['prompt_args'] as $promptArg) {
            $cmd[] = $promptArg;
        }

        $cmd[] = $prompt;

        // Add model if specified
        if ($model !== null && is_string($model)) {
            $cmd[] = '--model';
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
# Define agents once, reference by name in complexity mappings

# Primary agent for orchestration/decision-making (required)
primary: claude-opus
review: claude-opus

# Map complexity levels to agents
complexity:
  trivial: opencode-minimax
  simple: cursor-composer
  moderate: amp-smart
  complex: claude-opus

agents:
  cursor-composer:
    model: composer-1
    command: cursor-agent
    args: ["--force", "--output-format", "stream-json"]
    prompt_args: ["-p"]
    resume_args: ["--resume="]
    max_concurrent: 3
    max_attempts: 3

  cursor-opus:
    model: opus-4.5
    command: cursor-agent
    args: ["--force", "--output-format", "stream-json"]
    prompt_args: ["-p"]
    resume_args: ["--resume="]
    max_concurrent: 2
    max_attempts: 3

  claude-sonnet:
    model: sonnet
    command: claude
    args: ["--dangerously-skip-permissions", "--output-format", "stream-json", "--verbose"]
    prompt_args: ["-p"]
    resume_args: ["--resume"]
    max_concurrent: 2
    max_attempts: 3

  claude-opus:
    model: opus
    command: claude
    args: ["--dangerously-skip-permissions", "--output-format", "stream-json", "--verbose"]
    prompt_args: ["-p"]
    resume_args: ["--resume"]
    max_concurrent: 3
    max_attempts: 5

  opencode-glm:
    model: opencode/glm-4.7-free
    command: opencode
    args: []
    prompt_args: ["run"]
    resume_args: ["--session"]
    env:
      OPENCODE_PERMISSION: '{"permission":"allow"}'
    max_concurrent: 2
    max_attempts: 3

  opencode-minimax:
    model: opencode/minimax-m2.1-free
    command: opencode
    args: []
    prompt_args: ["run"]
    resume_args: ["--session"]
    env:
      OPENCODE_PERMISSION: '{"permission":"allow"}'
    max_concurrent: 2
    max_attempts: 3

  amp-smart:
    model: null  # mode controls model
    command: amp
    args: ["--stream-json", "-m", "smart", "--dangerously-allow-all", "--no-notifications"]
    prompt_args: ["--execute"]
    resume_args: []
    max_concurrent: 3
    max_attempts: 3
YAML;

        file_put_contents($this->getConfigPath(), $yaml);
    }
}
