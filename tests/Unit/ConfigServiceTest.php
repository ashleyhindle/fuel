<?php

use App\Services\ConfigService;
use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->configPath = $this->tempDir.'/.fuel/config.yaml';
    // Ensure .fuel directory exists
    $fuelDir = dirname($this->configPath);
    if (! is_dir($fuelDir)) {
        mkdir($fuelDir, 0755, true);
    }

    $this->configService = new ConfigService($this->configPath);
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
 * Helper to create a minimal valid config with defaults.
 */
function makeConfig(array $agents = [], array $complexity = [], ?string $primary = null): array
{
    return [
        'agents' => array_merge([
            'claude' => ['command' => 'claude'],
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

    expect($command)->toBe(['claude', '-p', 'test prompt']);
});

it('builds command array with prompt and model', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['command' => 'claude', 'model' => 'sonnet-4.5']],
        complexity: ['moderate' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('moderate', 'test prompt');

    expect($command)->toBe(['claude', '-p', 'test prompt', '--model', 'sonnet-4.5']);
});

it('builds command array with complexity model override', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['command' => 'claude', 'model' => 'default-model']],
        complexity: ['moderate' => ['agent' => 'claude', 'model' => 'sonnet-4.5']]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('moderate', 'test prompt');

    // Complexity override should take precedence over agent default
    expect($command)->toBe(['claude', '-p', 'test prompt', '--model', 'sonnet-4.5']);
});

it('builds command array with args from agent definition', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => [
                'command' => 'claude',
                'model' => 'opus-4.5',
                'args' => ['--dangerously-skip-permissions', '--mcp-server', 'github'],
            ],
        ],
        complexity: ['complex' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('complex', 'test prompt');

    expect($command)->toBe([
        'claude',
        '-p',
        'test prompt',
        '--model',
        'opus-4.5',
        '--dangerously-skip-permissions',
        '--mcp-server',
        'github',
    ]);
});

it('builds command array with complexity args override', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => [
                'command' => 'claude',
                'model' => 'opus-4.5',
                'args' => ['--default-arg'],
            ],
        ],
        complexity: ['complex' => ['agent' => 'claude', 'args' => ['--override-arg', '--another']]]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('complex', 'test prompt');

    // Complexity args override should replace agent default args
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

it('returns agent definition', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => [
                'command' => 'claude',
                'model' => 'sonnet-4.5',
                'args' => ['--verbose'],
                'prompt_args' => ['-p'],
                'max_concurrent' => 3,
            ],
        ],
        complexity: ['moderate' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $agentDef = $this->configService->getAgentDefinition('claude');

    expect($agentDef)->toBe([
        'command' => 'claude',
        'prompt_args' => ['-p'],
        'model' => 'sonnet-4.5',
        'args' => ['--verbose'],
        'env' => [],
        'resume_args' => [],
        'max_concurrent' => 3,
        'max_attempts' => 3,
        'max_retries' => 5,
    ]);
});

it('returns agent config for complexity with overrides', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => [
                'command' => 'claude',
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
        agents: ['cursor-agent' => ['command' => 'cursor-agent']],
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
            'claude' => ['command' => 'claude'],
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
    expect($config['agents'])->toHaveKeys(['cursor-composer', 'claude-sonnet', 'claude-opus']);

    // Verify agents have command field
    expect($config['agents']['cursor-composer']['command'])->toBe('cursor-agent');
    expect($config['agents']['claude-sonnet']['command'])->toBe('claude');
    expect($config['agents']['claude-opus']['command'])->toBe('claude');

    // Verify complexity references valid agents
    expect($config['complexity']['trivial'])->toBe('cursor-composer');
    expect($config['complexity']['simple'])->toBe('cursor-composer');
    expect($config['complexity']['moderate'])->toBe('claude-sonnet');
    expect($config['complexity']['complex'])->toBe('claude-opus');
});

it('creates default config with proper structure', function (): void {
    $this->configService->createDefaultConfig();

    // Verify it's valid YAML
    $parsed = Yaml::parseFile($this->configPath);

    // Verify agents have required fields
    expect($parsed['agents']['cursor-composer'])->toHaveKey('command');
    expect($parsed['agents']['cursor-composer'])->toHaveKey('prompt_args');
    expect($parsed['agents']['cursor-composer'])->toHaveKey('args');
    expect($parsed['agents']['cursor-composer'])->toHaveKey('max_concurrent');

    expect($parsed['agents']['claude-sonnet'])->toHaveKey('command');
    expect($parsed['agents']['claude-sonnet'])->toHaveKey('args');

    // Verify args contain expected values
    expect($parsed['agents']['cursor-composer']['args'])->toContain('--force');
    expect($parsed['agents']['claude-sonnet']['args'])->toContain('--dangerously-skip-permissions');
});

it('does not overwrite existing config file', function (): void {
    $customConfig = makeConfig(
        agents: ['custom-agent' => ['command' => 'custom-cmd']],
        complexity: ['simple' => 'custom-agent']
    );

    file_put_contents($this->configPath, Yaml::dump($customConfig));

    $this->configService->createDefaultConfig();

    $config = Yaml::parseFile($this->configPath);
    expect($config['agents'])->toHaveKey('custom-agent');
    expect($config['complexity']['simple'])->toBe('custom-agent');
});

it('allows setting custom config path', function (): void {
    $customPath = $this->tempDir.'/custom-config.yaml';
    $this->configService->setConfigPath($customPath);

    expect($this->configService->getConfigPath())->toBe($customPath);
});

it('supports custom agent names', function (): void {
    $config = makeConfig(
        agents: ['my-custom-agent' => ['command' => 'my-custom-agent']],
        complexity: ['simple' => 'my-custom-agent']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('simple', 'test prompt');

    expect($command)->toBe(['my-custom-agent', '-p', 'test prompt']);
});

it('supports all valid complexities', function (): void {
    $config = makeConfig(
        agents: [
            'cursor-agent' => ['command' => 'cursor-agent'],
            'opencode' => ['command' => 'opencode', 'prompt_args' => ['run']],
            'claude' => ['command' => 'claude', 'model' => 'sonnet-4.5'],
            'claude-opus' => ['command' => 'claude', 'model' => 'opus-4.5'],
        ],
        complexity: [
            'trivial' => 'cursor-agent',
            'simple' => 'opencode',
            'moderate' => 'claude',
            'complex' => 'claude-opus',
        ]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getAgentCommand('trivial', 'prompt'))->toBe(['cursor-agent', '-p', 'prompt']);
    expect($this->configService->getAgentCommand('simple', 'prompt'))->toBe(['opencode', 'run', 'prompt']);
    expect($this->configService->getAgentCommand('moderate', 'prompt'))->toBe(['claude', '-p', 'prompt', '--model', 'sonnet-4.5']);
    expect($this->configService->getAgentCommand('complex', 'prompt'))->toBe(['claude', '-p', 'prompt', '--model', 'opus-4.5']);
});

it('throws exception when complexity not found in config', function (): void {
    $config = makeConfig();

    file_put_contents($this->configPath, Yaml::dump($config));

    expect(fn () => $this->configService->getAgentCommand('moderate', 'test prompt'))
        ->toThrow(RuntimeException::class, 'No agent configured for complexity');
});

it('throws exception when agent is not defined', function (): void {
    // Create config without required agents section validation
    $invalidConfig = [
        'agents' => [
            'claude' => ['command' => 'claude'],
        ],
        'complexity' => [
            'simple' => 'undefined-agent', // references non-existent agent
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, "references undefined agent 'undefined-agent'");
});

it('throws exception when agent config is missing command', function (): void {
    $invalidConfig = [
        'agents' => [
            'claude' => [], // Missing 'command' key
        ],
        'complexity' => [
            'simple' => 'claude',
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, "must have 'command' string");
});

it('caches config after first load', function (): void {
    $config = makeConfig(
        agents: ['cursor-agent' => ['command' => 'cursor-agent']],
        complexity: ['simple' => 'cursor-agent']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    // First call loads config
    $command1 = $this->configService->getAgentCommand('simple', 'test prompt');
    expect($command1)->toBe(['cursor-agent', '-p', 'test prompt']);

    // Modify file (should not affect cached config)
    $newConfig = makeConfig(
        agents: ['claude' => ['command' => 'claude']],
        complexity: ['simple' => 'claude']
    );
    file_put_contents($this->configPath, Yaml::dump($newConfig));

    // Second call should use cached config (still returns cursor-agent)
    $command2 = $this->configService->getAgentCommand('simple', 'test prompt');
    expect($command2)->toBe(['cursor-agent', '-p', 'test prompt']);

    // Reset cache by setting new path
    $this->configService->setConfigPath($this->configPath);

    // Now should load new config
    $command3 = $this->configService->getAgentCommand('simple', 'test prompt');
    expect($command3)->toBe(['claude', '-p', 'test prompt']);
});

it('throws exception when YAML parses to non-array', function (): void {
    // YAML that parses to a string instead of array
    file_put_contents($this->configPath, 'just a string');

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'Invalid config format');
});

it('tests getAgentCommand output format for all complexities', function (): void {
    $config = makeConfig(
        agents: [
            'cursor-agent' => ['command' => 'cursor-agent'],
            'claude' => ['command' => 'claude'],
            'claude-sonnet' => ['command' => 'claude', 'model' => 'sonnet-4.5'],
            'claude-opus' => ['command' => 'claude', 'model' => 'opus-4.5'],
        ],
        complexity: [
            'trivial' => 'cursor-agent',
            'simple' => 'cursor-agent',
            'moderate' => 'claude-sonnet',
            'complex' => 'claude-opus',
        ]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    // Test output format: array of strings [command, ...prompt_args, prompt, --model?, model?]
    $trivial = $this->configService->getAgentCommand('trivial', 'do something');
    expect($trivial)->toBeArray();
    expect($trivial)->toBe(['cursor-agent', '-p', 'do something']);
    expect($trivial)->toHaveCount(3);

    $simple = $this->configService->getAgentCommand('simple', 'do something');
    expect($simple)->toBeArray();
    expect($simple)->toBe(['cursor-agent', '-p', 'do something']);
    expect($simple)->toHaveCount(3);

    $moderate = $this->configService->getAgentCommand('moderate', 'do something');
    expect($moderate)->toBeArray();
    expect($moderate)->toBe(['claude', '-p', 'do something', '--model', 'sonnet-4.5']);
    expect($moderate)->toHaveCount(5);

    $complex = $this->configService->getAgentCommand('complex', 'do something');
    expect($complex)->toBeArray();
    expect($complex)->toBe(['claude', '-p', 'do something', '--model', 'opus-4.5']);
    expect($complex)->toHaveCount(5);
});

it('filters out non-string args', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => [
                'command' => 'claude',
                'args' => ['--valid', 123, '--also-valid', null],
            ],
        ],
        complexity: ['complex' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('complex', 'test');

    // Only string args should be included
    expect($command)->toBe(['claude', '-p', 'test', '--valid', '--also-valid']);
});

it('returns default limit of 2 when agent does not have max_concurrent', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['command' => 'claude']],
        complexity: ['simple' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $limit = $this->configService->getAgentLimit('claude');

    expect($limit)->toBe(2);
});

it('returns configured limit for agent', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => ['command' => 'claude', 'max_concurrent' => 5],
            'cursor-agent' => ['command' => 'cursor-agent', 'max_concurrent' => 3],
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
            'claude' => ['command' => 'claude', 'max_concurrent' => 5],
            'cursor-agent' => ['command' => 'cursor-agent', 'max_concurrent' => 3],
            'custom-agent' => ['command' => 'custom', 'max_concurrent' => 1],
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
            'claude' => ['command' => 'claude'],
            'cursor-agent' => ['command' => 'cursor-agent'],
            'opencode' => ['command' => 'opencode'],
        ],
        complexity: ['simple' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $names = $this->configService->getAgentNames();

    expect($names)->toBe(['claude', 'cursor-agent', 'opencode']);
});

it('checks if agent is defined', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['command' => 'claude']],
        complexity: ['simple' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->hasAgent('claude'))->toBeTrue();
    expect($this->configService->hasAgent('unknown'))->toBeFalse();
});

it('returns agent for complexity', function (): void {
    $config = makeConfig(
        agents: [
            'cursor-agent' => ['command' => 'cursor-agent'],
            'claude-sonnet' => ['command' => 'claude', 'model' => 'sonnet'],
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
        agents: ['claude' => ['command' => 'claude']],
        complexity: [
            'moderate' => ['agent' => 'claude', 'model' => 'sonnet'],
        ]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getAgentForComplexity('moderate'))->toBe('claude');
});

it('supports custom prompt_args', function (): void {
    $config = makeConfig(
        agents: [
            'opencode' => [
                'command' => 'opencode',
                'prompt_args' => ['run'], // opencode uses positional args
            ],
        ],
        complexity: ['simple' => 'opencode']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('simple', 'test prompt');

    expect($command)->toBe(['opencode', 'run', 'test prompt']);
});

it('returns agent env', function (): void {
    $config = makeConfig(
        agents: [
            'opencode' => [
                'command' => 'opencode',
                'env' => [
                    'OPENCODE_PERMISSION' => '{"permission":"allow"}',
                    'FOO' => 'bar',
                ],
            ],
        ],
        complexity: ['simple' => 'opencode']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $env = $this->configService->getAgentEnv('opencode');

    expect($env)->toBe([
        'OPENCODE_PERMISSION' => '{"permission":"allow"}',
        'FOO' => 'bar',
    ]);
});

it('returns empty env when not configured', function (): void {
    $config = makeConfig();

    file_put_contents($this->configPath, Yaml::dump($config));

    $env = $this->configService->getAgentEnv('claude');

    expect($env)->toBe([]);
});

it('validates primary agent must be defined', function (): void {
    $config = [
        'agents' => [
            'claude' => ['command' => 'claude'],
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
            'claude' => ['command' => 'claude'],
        ],
        'complexity' => [
            'simple' => 'claude',
        ],
        // No 'primary' key
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test'))
        ->toThrow(RuntimeException::class, "must have 'primary' key");
});

it('returns primary agent', function (): void {
    $config = makeConfig(
        agents: [
            'claude' => ['command' => 'claude'],
            'claude-opus' => ['command' => 'claude', 'model' => 'opus'],
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
            'claude' => ['command' => 'claude'],
            'claude-opus' => ['command' => 'claude', 'model' => 'opus', 'max_concurrent' => 1],
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
                'command' => 'claude',
                'model' => 'sonnet',
                'args' => ['--verbose'],
            ],
        ],
        complexity: ['simple' => 'claude']
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    // Build command directly for agent without complexity lookup
    $command = $this->configService->buildAgentCommand('claude-sonnet', 'test prompt');

    expect($command)->toBe(['claude', '-p', 'test prompt', '--model', 'sonnet', '--verbose']);
});

it('returns null when review_agent is not set', function (): void {
    $config = makeConfig();

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getReviewAgent())->toBeNull();
});

it('returns review agent name when set', function (): void {
    $config = makeConfig();
    $config['review_agent'] = 'claude-sonnet';

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
        agents: ['claude' => ['command' => 'claude']]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getAgentMaxRetries('claude'))->toBe(5);
});

it('returns configured max_retries for agent', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['command' => 'claude', 'max_retries' => 10]]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getAgentMaxRetries('claude'))->toBe(10);
});

it('validates max_retries must be an integer', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['command' => 'claude', 'max_retries' => 'invalid']]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    expect(fn () => $this->configService->getAgentMaxRetries('claude'))
        ->toThrow(RuntimeException::class, 'max_retries must be an integer');
});

it('includes max_retries in agent definition', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['command' => 'claude', 'max_retries' => 7]]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $def = $this->configService->getAgentDefinition('claude');

    expect($def['max_retries'])->toBe(7);
});

it('defaults max_retries to 5 in agent definition', function (): void {
    $config = makeConfig(
        agents: ['claude' => ['command' => 'claude']]
    );

    file_put_contents($this->configPath, Yaml::dump($config));

    $def = $this->configService->getAgentDefinition('claude');

    expect($def['max_retries'])->toBe(5);
});
