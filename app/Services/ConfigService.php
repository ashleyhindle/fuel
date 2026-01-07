<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class ConfigService
{
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
            $parsed = Yaml::parse($content);
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to parse YAML config: {$e->getMessage()}");
        }

        if (! is_array($parsed)) {
            throw new RuntimeException('Invalid config format: expected array, got '.gettype($parsed));
        }
        $this->config = $parsed;

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
        if (! isset($config['complexity']) || ! is_array($config['complexity'])) {
            throw new RuntimeException('Config must have "complexity" key with array value');
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
        }
    }

    /**
     * Get agent command array for given complexity and prompt.
     *
     * All agents use -p for prompt and --model for model (hardcoded).
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
        $agent = $complexityConfig['agent'];

        // Build command array: [agent, -p, prompt, --model?, model?, ...args?]
        $cmd = [$agent, '-p', $prompt];

        // Add optional model argument if specified
        if (isset($complexityConfig['model']) && is_string($complexityConfig['model'])) {
            $cmd[] = '--model';
            $cmd[] = $complexityConfig['model'];
        }

        // Add optional extra args if specified
        if (isset($complexityConfig['args']) && is_array($complexityConfig['args'])) {
            foreach ($complexityConfig['args'] as $arg) {
                if (is_string($arg)) {
                    $cmd[] = $arg;
                }
            }
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

        return [
            'agent' => $complexityConfig['agent'],
            'model' => $complexityConfig['model'] ?? null,
            'args' => $complexityConfig['args'] ?? [],
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
            'complexity' => [
                'trivial' => [
                    'agent' => 'cursor-agent',
                    'model' => 'composer-1',
                ],
                'simple' => [
                    'agent' => 'cursor-agent',
                    'model' => 'composer-1',
                ],
                'moderate' => [
                    'agent' => 'claude',
                    'model' => 'sonnet-4.5',
                ],
                'complex' => [
                    'agent' => 'claude',
                    'model' => 'opus-4.5',
                ],
            ],
        ];

        $yaml = Yaml::dump($defaultConfig, 4);
        file_put_contents($this->configPath, $yaml);
    }
}
