<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class ConfigService
{
    private const VALID_AGENTS = ['cursor-agent', 'claude', 'opencode'];

    private const VALID_COMPLEXITIES = ['trivial', 'simple', 'moderate', 'complex'];

    private string $configPath;

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath ?? getcwd().'/.fuel/config.yaml';
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

        if (! file_exists($this->configPath)) {
            throw new RuntimeException("Config file not found: {$this->configPath}");
        }

        $content = file_get_contents($this->configPath);
        if ($content === false) {
            throw new RuntimeException("Failed to read config file: {$this->configPath}");
        }

        try {
            $this->config = Yaml::parse($content);
            if (! is_array($this->config)) {
                throw new RuntimeException('Invalid config format: expected array, got '.gettype($this->config));
            }
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to parse YAML config: {$e->getMessage()}");
        }

        $this->validateConfig($this->config);

        return $this->config;
    }

    /**
     * Validate configuration structure and values.
     *
     * @param  array<string, mixed>  $config
     */
    private function validateConfig(array $config): void
    {
        if (! isset($config['agents']) || ! is_array($config['agents'])) {
            throw new RuntimeException('Config must have "agents" key with array value');
        }

        if (! isset($config['complexity']) || ! is_array($config['complexity'])) {
            throw new RuntimeException('Config must have "complexity" key with array value');
        }

        // Validate agent names
        foreach ($config['agents'] as $agentName => $agentConfig) {
            if (! in_array($agentName, self::VALID_AGENTS, true)) {
                throw new RuntimeException(
                    "Invalid agent name '{$agentName}'. Must be one of: ".implode(', ', self::VALID_AGENTS)
                );
            }

            if (! is_array($agentConfig)) {
                throw new RuntimeException("Agent config for '{$agentName}' must be an array");
            }

            if (! isset($agentConfig['command']) || ! is_string($agentConfig['command'])) {
                throw new RuntimeException("Agent '{$agentName}' must have 'command' string");
            }
        }

        // Validate complexity mappings
        foreach ($config['complexity'] as $complexity => $complexityConfig) {
            if (! in_array($complexity, self::VALID_COMPLEXITIES, true)) {
                throw new RuntimeException(
                    "Invalid complexity '{$complexity}'. Must be one of: ".implode(', ', self::VALID_COMPLEXITIES)
                );
            }

            if (! is_array($complexityConfig)) {
                throw new RuntimeException("Complexity config for '{$complexity}' must be an array");
            }

            if (! isset($complexityConfig['agent']) || ! is_string($complexityConfig['agent'])) {
                throw new RuntimeException("Complexity '{$complexity}' must have 'agent' string");
            }

            $agentName = $complexityConfig['agent'];
            if (! in_array($agentName, self::VALID_AGENTS, true)) {
                throw new RuntimeException(
                    "Invalid agent '{$agentName}' for complexity '{$complexity}'. Must be one of: ".implode(', ', self::VALID_AGENTS)
                );
            }

            // Validate agent exists in agents section
            if (! isset($config['agents'][$agentName])) {
                throw new RuntimeException("Agent '{$agentName}' referenced in complexity '{$complexity}' not found in agents section");
            }
        }
    }

    /**
     * Get agent command array for given complexity and prompt.
     *
     * @return array<int, string>
     */
    public function getAgentCommand(string $complexity, string $prompt): array
    {
        $this->validateComplexity($complexity);

        $config = $this->loadConfig();

        if (! isset($config['complexity'][$complexity])) {
            throw new RuntimeException("No configuration found for complexity '{$complexity}'");
        }

        $complexityConfig = $config['complexity'][$complexity];
        $agentName = $complexityConfig['agent'];

        if (! isset($config['agents'][$agentName])) {
            throw new RuntimeException("Agent '{$agentName}' not found in agents configuration");
        }

        $agentConfig = $config['agents'][$agentName];
        $command = $agentConfig['command'];
        $promptFlag = $agentConfig['prompt_flag'] ?? '-p';

        // Build command array
        $cmd = [$command, $promptFlag, $prompt];

        // Add optional model argument if specified
        if (isset($complexityConfig['model']) && is_string($complexityConfig['model'])) {
            $modelFlag = $agentConfig['model_flag'] ?? '--model';
            $cmd[] = $modelFlag;
            $cmd[] = $complexityConfig['model'];
        }

        return $cmd;
    }

    /**
     * Get agent configuration for given complexity.
     *
     * @return array<string, mixed>
     */
    public function getAgentConfig(string $complexity): array
    {
        $this->validateComplexity($complexity);

        $config = $this->loadConfig();

        if (! isset($config['complexity'][$complexity])) {
            throw new RuntimeException("No configuration found for complexity '{$complexity}'");
        }

        $complexityConfig = $config['complexity'][$complexity];
        $agentName = $complexityConfig['agent'];

        if (! isset($config['agents'][$agentName])) {
            throw new RuntimeException("Agent '{$agentName}' not found in agents configuration");
        }

        $agentConfig = $config['agents'][$agentName];

        return [
            'agent' => $agentName,
            'command' => $agentConfig['command'],
            'prompt_flag' => $agentConfig['prompt_flag'] ?? '-p',
            'model_flag' => $agentConfig['model_flag'] ?? '--model',
            'model' => $complexityConfig['model'] ?? null,
        ];
    }

    /**
     * Validate complexity value.
     */
    private function validateComplexity(string $complexity): void
    {
        if (! in_array($complexity, self::VALID_COMPLEXITIES, true)) {
            throw new RuntimeException(
                "Invalid complexity '{$complexity}'. Must be one of: ".implode(', ', self::VALID_COMPLEXITIES)
            );
        }
    }

    /**
     * Get the config file path.
     */
    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    /**
     * Set custom config path.
     */
    public function setConfigPath(string $path): self
    {
        $this->configPath = $path;
        $this->config = null; // Reset cached config

        return $this;
    }

    /**
     * Create default config file if it doesn't exist.
     */
    public function createDefaultConfig(): void
    {
        if (file_exists($this->configPath)) {
            return; // Don't overwrite existing config
        }

        $dir = dirname($this->configPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $defaultConfig = [
            'agents' => [
                'cursor-agent' => [
                    'command' => 'cursor-agent',
                    'prompt_flag' => '-p',
                ],
                'claude' => [
                    'command' => 'claude',
                    'prompt_flag' => '-p',
                    'model_flag' => '--model',
                ],
                'opencode' => [
                    'command' => 'opencode',
                    'prompt_flag' => '-p',
                ],
            ],
            'complexity' => [
                'trivial' => [
                    'agent' => 'cursor-agent',
                ],
                'simple' => [
                    'agent' => 'cursor-agent',
                ],
                'moderate' => [
                    'agent' => 'claude',
                    'model' => 'claude-3-sonnet',
                ],
                'complex' => [
                    'agent' => 'claude',
                    'model' => 'claude-3-opus',
                ],
            ],
        ];

        $yaml = Yaml::dump($defaultConfig, 4);
        file_put_contents($this->configPath, $yaml);
    }
}
