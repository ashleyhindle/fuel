<?php

use App\Agents\AgentDriverRegistry;
use App\Services\ConfigService;
use App\Services\FuelContext;
use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);
    $this->context = new FuelContext($this->tempDir.'/.fuel');
    $this->configPath = $this->context->getConfigPath();

    $this->configService = new ConfigService($this->context);
});

afterEach(function (): void {
    // Clean up temp files
    $fuelDir = dirname($this->configPath);
    if (file_exists($this->configPath)) {
        unlink($this->configPath);
    }

    if (is_dir($fuelDir)) {
        rmdir($fuelDir);
    }

    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

/**
 * Helper to create a minimal valid config with driver-based format.
 */
function makeConfig(array $agents = [], array $complexity = [], ?string $primary = null): array
{
    return [
        'agents' => array_merge([
            'claude' => ['driver' => 'claude'],
        ], $agents),
        'complexity' => array_merge([
            'simple' => 'claude',
        ], $complexity),
        'primary' => $primary ?? 'claude',
    ];
}

it('throws exception when config file does not exist', function (): void {
    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'Config file not found');
});

it('throws exception when config file is invalid YAML', function (): void {
    file_put_contents($this->configPath, 'invalid: yaml: content: [unclosed');

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'Failed to parse YAML config');
});

it('validates complexity values', function (): void {
    $config = makeConfig();

    file_put_contents($this->configPath, Yaml::dump($config));

    expect(fn () => $this->configService->getAgentCommand('invalid-complexity', 'test prompt'))
        ->toThrow(RuntimeException::class, 'Invalid complexity');
});

it('builds command array with prompt', function (): void {
    $config = makeConfig();

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('simple', 'test prompt');

    // Claude driver: command=claude, prompt_args=[-p], default_args=[--dangerously-skip-permissions, --output-format, stream-json, --verbose]
    expect($command[0])->toBe('claude');
    expect($command[1])->toBe('-p');
    expect($command[2])->toBe('test prompt');
});

it('builds command array with prompt and model', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['driver' => 'claude', 'model' => 'sonnet-4.5']],
        complexity: ['moderate' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('moderate', 'test prompt');

    expect($command[0])->toBe('claude');
    expect($command[1])->toBe('-p');
    expect($command[2])->toBe('test prompt');
    expect($command[3])->toBe('--model');
    expect($command[4])->toBe('sonnet-4.5');
});

it('builds command array with complexity model override', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['driver' => 'claude', 'model' => 'default-model']],
        complexity: ['moderate' => ['agent' => 'claude', 'model' => 'sonnet-4.5']]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('moderate', 'test prompt');

    // Complexity override should take precedence over agent default
    expect($command[0])->toBe('claude');
    expect($command[3])->toBe('--model');
    expect($command[4])->toBe('sonnet-4.5');
});

it('builds command array with extra_args from agent definition', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => [
                'driver' => 'claude',
                'model' => 'opus-4.5',
                'extra_args' => ['--mcp-server', 'github'],
            ],
        ],
        complexity: ['complex' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('complex', 'test prompt');

    expect($command[0])->toBe('claude');
    expect($command)->toContain('--mcp-server');
    expect($command)->toContain('github');
    // Driver defaults should also be included
    expect($command)->toContain('--dangerously-skip-permissions');
});

it('builds command array with complexity args override', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => [
                'driver' => 'claude',
                'model' => 'opus-4.5',
            ],
        ],
        complexity: ['complex' => ['agent' => 'claude', 'args' => ['--override-arg', '--another']]]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('complex', 'test prompt');

    // Complexity args override should replace merged args
    expect($command)->toBe([
        'claude',
        '-p',
        'test prompt',
        '--model',
        'opus-4.5',
        '--override-arg',
        '--another',
    ]);
});

it('returns agent definition with driver defaults merged', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => [
                'driver' => 'claude',
                'model' => 'sonnet-4.5',
                'extra_args' => ['--custom'],
                'max_concurrent' => 3,
            ],
        ],
        complexity: ['moderate' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $agentDef = $this->configService->getAgentDefinition('claude');

    expect($agentDef['command'])->toBe('claude');
    expect($agentDef['prompt_args'])->toBe(['-p']);
    expect($agentDef['model'])->toBe('sonnet-4.5');
    // Args include driver defaults + extra_args
    expect($agentDef['args'])->toContain('--dangerously-skip-permissions');
    expect($agentDef['args'])->toContain('--custom');
    expect($agentDef['env'])->toBe([]);
    expect($agentDef['max_concurrent'])->toBe(3);
    expect($agentDef['max_attempts'])->toBe(3);
    expect($agentDef['max_retries'])->toBe(5);
});

it('returns agent config for complexity with overrides', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => [
                'driver' => 'claude',
                'model' => 'default-model',
            ],
        ],
        complexity: ['moderate' => ['agent' => 'claude', 'model' => 'sonnet-4.5']]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $agentConfig = $this->configService->getAgentConfig('moderate');

    expect($agentConfig['model'])->toBe('sonnet-4.5');
    expect($agentConfig['name'])->toBe('claude');
});

it('returns agent config without model when not specified', function (): void {
    $config = makeConfig(
        agents: ['cursor-agent' => ['driver' => 'cursor-agent']],
        complexity: ['simple' => 'cursor-agent']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $agentConfig = $this->configService->getAgentConfig('simple');

    expect($agentConfig['model'])->toBeNull();
    expect($agentConfig['name'])->toBe('cursor-agent');
});

it('validates config structure requires agents key', function (): void {
    $invalidConfig = [
        'complexity' => [
            'simple' => 'claude',
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'must have "agents" key');
});

it('validates config structure requires complexity key', function (): void {
    $invalidConfig = [
        'agents' => [
            'claude' => ['driver' => 'claude'],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'must have "complexity" key');
});

it('creates default config file', function (): void {
    $this->configService->createDefaultConfig();

    expect(file_exists($this->configPath))->toBeTrue();

    $config = Yaml::parseFile($this->configPath);

    expect($config)->toHaveKey('complexity');
    expect($config)->toHaveKey('agents');
    expect($config['complexity'])->toHaveKeys(['trivial', 'simple', 'moderate', 'complex']);
    expect($config['agents'])->toHaveKeys([
        'cursor-composer',
        'cursor-opus',
        'claude-sonnet',
        'claude-opus',
        'opencode-glm',
        'opencode-minimax',
        'amp-smart',
    ]);

    // Verify agents have driver field
    expect($config['agents']['cursor-composer']['driver'])->toBe('cursor-agent');
    expect($config['agents']['claude-sonnet']['driver'])->toBe('claude');
    expect($config['agents']['claude-opus']['driver'])->toBe('claude');

    // Verify complexity references valid agents
    expect($config['complexity']['trivial'])->toBe('opencode-minimax');
    expect($config['complexity']['simple'])->toBe('cursor-composer');
    expect($config['complexity']['moderate'])->toBe('amp-smart');
    expect($config['complexity']['complex'])->toBe('claude-opus');
});

it('creates default config with proper structure', function (): void {
    $this->configService->createDefaultConfig();

    // Verify it's valid YAML
    $parsed = Yaml::parseFile($this->configPath);

    // Verify agents have required fields (driver-based)
    expect($parsed['agents']['cursor-composer'])->toHaveKey('driver');
    expect($parsed['agents']['cursor-composer'])->toHaveKey('model');
    expect($parsed['agents']['cursor-composer'])->toHaveKey('max_concurrent');

    expect($parsed['agents']['claude-sonnet'])->toHaveKey('driver');
    expect($parsed['agents']['claude-sonnet'])->toHaveKey('model');

    // No explicit command/args in new format
    expect($parsed['agents']['cursor-composer'])->not->toHaveKey('command');
    expect($parsed['agents']['cursor-composer'])->not->toHaveKey('args');
});

it('does not overwrite existing config file', function (): void {
    $customConfig = makeConfig(
        agents: ['custom-agent' => ['driver' => 'claude']],
        complexity: ['simple' => 'custom-agent']
    );

    file_put_contents($this->configPath, Yaml::dump($customConfig));

    $this->configService->createDefaultConfig();

    $config = Yaml::parseFile($this->configPath);
    expect($config['agents'])->toHaveKey('custom-agent');
    expect($config['complexity']['simple'])->toBe('custom-agent');
});

it('returns config path from context', function (): void {
    expect($this->configService->getConfigPath())->toBe($this->configPath);
});

it('supports custom agent names with drivers', function (): void {
    $config = makeConfig(
        agents: ['my-custom-agent' => ['driver' => 'claude']],
        complexity: ['simple' => 'my-custom-agent']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('simple', 'test prompt');

    expect($command[0])->toBe('claude');
    expect($command[1])->toBe('-p');
    expect($command[2])->toBe('test prompt');
});

it('supports all valid complexities with driver-based config', function (): void {
    $config = makeConfig(
        agents: [
            'cursor-agent' => ['driver' => 'cursor-agent'],
            'opencode' => ['driver' => 'opencode'],
            'claude' => ['driver' => 'claude', 'model' => 'sonnet-4.5'],
            'claude-opus' => ['driver' => 'claude', 'model' => 'opus-4.5'],
        ],
        complexity: [
            'trivial' => 'cursor-agent',
            'simple' => 'opencode',
            'moderate' => 'claude',
            'complex' => 'claude-opus',
        ]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    // Verify commands are built correctly from drivers
    $trivial = $this->configService->getAgentCommand('trivial', 'prompt');
    expect($trivial[0])->toBe('cursor-agent');

    $simple = $this->configService->getAgentCommand('simple', 'prompt');
    expect($simple[0])->toBe('opencode');
    expect($simple)->toContain('run'); // opencode uses 'run' as prompt_arg

    $moderate = $this->configService->getAgentCommand('moderate', 'prompt');
    expect($moderate[0])->toBe('claude');
    expect($moderate)->toContain('sonnet-4.5');

    $complex = $this->configService->getAgentCommand('complex', 'prompt');
    expect($complex[0])->toBe('claude');
    expect($complex)->toContain('opus-4.5');
});

it('throws exception when complexity not found in config', function (): void {
    $config = makeConfig();

    file_put_contents($this->configPath, Yaml::dump($config));

    expect(fn () => $this->configService->getAgentCommand('moderate', 'test prompt'))
        ->toThrow(RuntimeException::class, 'No agent configured for complexity');
});

it('throws exception when agent is not defined', function (): void {
    $invalidConfig = [
        'agents' => [
            'claude' => ['driver' => 'claude'],
        ],
        'complexity' => [
            'simple' => 'undefined-agent',
        ],
        'primary' => 'claude',
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, "references undefined agent 'undefined-agent'");
});

it('throws exception when agent config is missing driver', function (): void {
    $invalidConfig = [
        'agents' => [
            'claude' => [],
        ],
        'complexity' => [
            'simple' => 'claude',
        ],
        'primary' => 'claude',
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, "must have 'driver' string");
});

it('throws exception when driver is unknown', function (): void {
    $invalidConfig = [
        'agents' => [
            'claude' => ['driver' => 'unknown-driver'],
        ],
        'complexity' => [
            'simple' => 'claude',
        ],
        'primary' => 'claude',
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, "references unknown driver 'unknown-driver'");
});

it('caches config after first load', function (): void {
    $config = makeConfig(
        agents: ['cursor-agent' => ['driver' => 'cursor-agent']],
        complexity: ['simple' => 'cursor-agent']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    // First call loads config
    $command1 = $this->configService->getAgentCommand('simple', 'test prompt');
    expect($command1[0])->toBe('cursor-agent');

    // Modify file (should not affect cached config)
    $newConfig = makeConfig(
        agents: ['claude' => ['driver' => 'claude']],
        complexity: ['simple' => 'claude']
    );
    file_put_contents($this->configPath, Yaml::dump($newConfig));

    // Second call should use cached config (still returns cursor-agent)
    $command2 = $this->configService->getAgentCommand('simple', 'test prompt');
    expect($command2[0])->toBe('cursor-agent');

    // Create new ConfigService instance to reload config
    $newConfigService = new ConfigService($this->context);

    // Now should load new config
    $command3 = $newConfigService->getAgentCommand('simple', 'test prompt');
    expect($command3[0])->toBe('claude');
});

it('throws exception when YAML parses to non-array', function (): void {
    file_put_contents($this->configPath, 'just a string');

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'Invalid config format');
});

it('filters out non-string args from extra_args', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => [
                'driver' => 'claude',
                'extra_args' => ['--valid', 123, '--also-valid', null],
            ],
        ],
        complexity: ['complex' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('complex', 'test');

    // Only string args should be included
    expect($command)->toContain('--valid');
    expect($command)->toContain('--also-valid');
    expect($command)->not->toContain(123);
});

it('returns default limit of 2 when agent does not have max_concurrent', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['driver' => 'claude']],
        complexity: ['simple' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $limit = $this->configService->getAgentLimit('claude');

    expect($limit)->toBe(2);
});

it('returns configured limit for agent', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => ['driver' => 'claude', 'max_concurrent' => 5],
            'cursor-agent' => ['driver' => 'cursor-agent', 'max_concurrent' => 3],
        ],
        complexity: ['simple' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getAgentLimit('claude'))->toBe(5);
    expect($this->configService->getAgentLimit('cursor-agent'))->toBe(3);
});

it('returns all agent limits as array', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => ['driver' => 'claude', 'max_concurrent' => 5],
            'cursor-agent' => ['driver' => 'cursor-agent', 'max_concurrent' => 3],
            'custom-agent' => ['driver' => 'amp', 'max_concurrent' => 1],
        ],
        complexity: ['simple' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $limits = $this->configService->getAgentLimits();

    expect($limits)->toBe([
        'claude' => 5,
        'cursor-agent' => 3,
        'custom-agent' => 1,
    ]);
});

it('returns all agent names', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => ['driver' => 'claude'],
            'cursor-agent' => ['driver' => 'cursor-agent'],
            'opencode' => ['driver' => 'opencode'],
        ],
        complexity: ['simple' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $names = $this->configService->getAgentNames();

    expect($names)->toBe(['claude', 'cursor-agent', 'opencode']);
});

it('checks if agent is defined', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['driver' => 'claude']],
        complexity: ['simple' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->hasAgent('claude'))->toBeTrue();
    expect($this->configService->hasAgent('unknown'))->toBeFalse();
});

it('returns agent for complexity', function (): void {
    $config = makeConfig(
        agents: [
            'cursor-agent' => ['driver' => 'cursor-agent'],
            'claude-sonnet' => ['driver' => 'claude', 'model' => 'sonnet'],
        ],
        complexity: [
            'simple' => 'cursor-agent',
            'moderate' => 'claude-sonnet',
        ]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getAgentForComplexity('simple'))->toBe('cursor-agent');
    expect($this->configService->getAgentForComplexity('moderate'))->toBe('claude-sonnet');
});

it('returns agent for complexity with array format', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['driver' => 'claude']],
        complexity: [
            'moderate' => ['agent' => 'claude', 'model' => 'sonnet'],
        ]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getAgentForComplexity('moderate'))->toBe('claude');
});

it('returns agent env with driver defaults merged', function (): void {
    $config = makeConfig(
        agents: [
            'opencode' => [
                'driver' => 'opencode',
                'extra_env' => [
                    'FOO' => 'bar',
                ],
            ],
        ],
        complexity: ['simple' => 'opencode']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $env = $this->configService->getAgentEnv('opencode');

    // Should have driver default + extra_env
    expect($env)->toBe([
        'OPENCODE_PERMISSION' => '{"permission":"allow"}',
        'FOO' => 'bar',
    ]);
});

it('returns driver default env when no extra_env configured', function (): void {
    $config = makeConfig(
        agents: ['opencode' => ['driver' => 'opencode']],
        complexity: ['simple' => 'opencode']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $env = $this->configService->getAgentEnv('opencode');

    expect($env)->toBe([
        'OPENCODE_PERMISSION' => '{"permission":"allow"}',
    ]);
});

it('returns empty env for drivers with no default env', function (): void {
    $config = makeConfig();

    file_put_contents($this->configPath, Yaml::dump($config));

    $env = $this->configService->getAgentEnv('claude');

    expect($env)->toBe([]);
});

it('validates primary agent must be defined', function (): void {
    $config = [
        'agents' => [
            'claude' => ['driver' => 'claude'],
        ],
        'complexity' => [
            'simple' => 'claude',
        ],
        'primary' => 'undefined-agent',
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test'))
        ->toThrow(RuntimeException::class, "Primary agent 'undefined-agent' is not defined");
});

it('validates primary agent is required', function (): void {
    $config = [
        'agents' => [
            'claude' => ['driver' => 'claude'],
        ],
        'complexity' => [
            'simple' => 'claude',
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test'))
        ->toThrow(RuntimeException::class, "must have 'primary' key");
});

it('returns primary agent', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => ['driver' => 'claude'],
            'claude-opus' => ['driver' => 'claude', 'model' => 'opus'],
        ],
        complexity: ['simple' => 'claude'],
        primary: 'claude-opus'
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getPrimaryAgent())->toBe('claude-opus');
});

it('returns primary agent definition', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => ['driver' => 'claude'],
            'claude-opus' => ['driver' => 'claude', 'model' => 'opus', 'max_concurrent' => 1],
        ],
        complexity: ['simple' => 'claude'],
        primary: 'claude-opus'
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $def = $this->configService->getPrimaryAgentDefinition();

    expect($def['command'])->toBe('claude');
    expect($def['model'])->toBe('opus');
    expect($def['max_concurrent'])->toBe(1);
});

it('builds agent command directly', function (): void {
    $config = makeConfig(
        agents: [
            'claude-sonnet' => [
                'driver' => 'claude',
                'model' => 'sonnet',
                'extra_args' => ['--verbose'],
            ],
        ],
        complexity: ['simple' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    // Build command directly for agent without complexity lookup
    $command = $this->configService->buildAgentCommand('claude-sonnet', 'test prompt');

    expect($command[0])->toBe('claude');
    expect($command)->toContain('--model');
    expect($command)->toContain('sonnet');
    expect($command)->toContain('--verbose');
});

it('returns primary agent when review is not set', function (): void {
    $config = makeConfig();

    file_put_contents($this->configPath, Yaml::dump($config));

    // Falls back to primary when review is not set
    expect($this->configService->getReviewAgent())->toBe('claude');
});

it('returns review agent name when set', function (): void {
    $config = makeConfig();
    $config['review'] = 'claude-sonnet';

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getReviewAgent())->toBe('claude-sonnet');
});

// =============================================================================
// getAgentMaxRetries() Tests
// =============================================================================

it('returns default max_retries when agent not configured', function (): void {
    $config = makeConfig();

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getAgentMaxRetries('unknown-agent'))->toBe(5);
});

it('returns default max_retries when agent has no max_retries set', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['driver' => 'claude']]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getAgentMaxRetries('claude'))->toBe(5);
});

it('returns configured max_retries for agent', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['driver' => 'claude', 'max_retries' => 10]]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getAgentMaxRetries('claude'))->toBe(10);
});

it('validates max_retries must be an integer', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['driver' => 'claude', 'max_retries' => 'invalid']]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    expect(fn () => $this->configService->getAgentMaxRetries('claude'))
        ->toThrow(RuntimeException::class, 'max_retries must be an integer');
});

it('includes max_retries in agent definition', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['driver' => 'claude', 'max_retries' => 7]]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $def = $this->configService->getAgentDefinition('claude');

    expect($def['max_retries'])->toBe(7);
});

it('defaults max_retries to 5 in agent definition', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['driver' => 'claude']]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $def = $this->configService->getAgentDefinition('claude');

    expect($def['max_retries'])->toBe(5);
});

// =============================================================================
// Driver-specific Tests
// =============================================================================

it('uses correct model_arg from driver', function (): void {
    $config = makeConfig(
        agents: [
            'amp-agent' => ['driver' => 'amp', 'model' => 'smart'],
        ],
        complexity: ['simple' => 'amp-agent']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('simple', 'test prompt');

    // Amp uses -m for model arg
    expect($command)->toContain('-m');
    expect($command)->toContain('smart');
});

it('provides driver instance in agent definition', function (): void {
    $config = makeConfig();

    file_put_contents($this->configPath, Yaml::dump($config));

    $def = $this->configService->getAgentDefinition('claude');

    expect($def)->toHaveKey('driver');
    expect($def['driver'])->toBeInstanceOf(\App\Agents\Drivers\AgentDriverInterface::class);
});

it('provides access to driver registry', function (): void {
    $registry = $this->configService->getDriverRegistry();

    expect($registry)->toBeInstanceOf(AgentDriverRegistry::class);
    expect($registry->has('claude'))->toBeTrue();
    expect($registry->has('cursor-agent'))->toBeTrue();
    expect($registry->has('opencode'))->toBeTrue();
    expect($registry->has('amp'))->toBeTrue();
    expect($registry->has('codex'))->toBeTrue();
});
