<?php

use App\Services\ConfigService;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
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

afterEach(function () {
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

it('throws exception when config file does not exist', function () {
    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'Config file not found');
});

it('throws exception when config file is invalid YAML', function () {
    file_put_contents($this->configPath, 'invalid: yaml: content: [unclosed');

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'Failed to parse YAML config');
});

it('validates complexity values', function () {
    $config = [
        'complexity' => [
            'simple' => [
                'agent' => 'claude',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    expect(fn () => $this->configService->getAgentCommand('invalid-complexity', 'test prompt'))
        ->toThrow(RuntimeException::class, 'Invalid complexity');
});

it('builds command array with prompt', function () {
    $config = [
        'complexity' => [
            'simple' => [
                'agent' => 'claude',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('simple', 'test prompt');

    expect($command)->toBe(['claude', '-p', 'test prompt']);
});

it('builds command array with prompt and model', function () {
    $config = [
        'complexity' => [
            'moderate' => [
                'agent' => 'claude',
                'model' => 'sonnet-4.5',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('moderate', 'test prompt');

    expect($command)->toBe(['claude', '-p', 'test prompt', '--model', 'sonnet-4.5']);
});

it('builds command array with args', function () {
    $config = [
        'complexity' => [
            'complex' => [
                'agent' => 'claude',
                'model' => 'opus-4.5',
                'args' => ['--dangerously-skip-permissions', '--mcp-server', 'github'],
            ],
        ],
    ];

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

it('returns agent config for complexity', function () {
    $config = [
        'complexity' => [
            'moderate' => [
                'agent' => 'claude',
                'model' => 'sonnet-4.5',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    $agentConfig = $this->configService->getAgentConfig('moderate');

    expect($agentConfig)->toBe([
        'agent' => 'claude',
        'model' => 'sonnet-4.5',
        'args' => [],
    ]);
});

it('returns agent config with args', function () {
    $config = [
        'complexity' => [
            'complex' => [
                'agent' => 'claude',
                'model' => 'opus-4.5',
                'args' => ['--verbose'],
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    $agentConfig = $this->configService->getAgentConfig('complex');

    expect($agentConfig)->toBe([
        'agent' => 'claude',
        'model' => 'opus-4.5',
        'args' => ['--verbose'],
    ]);
});

it('returns agent config without model when not specified', function () {
    $config = [
        'complexity' => [
            'simple' => [
                'agent' => 'cursor-agent',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    $agentConfig = $this->configService->getAgentConfig('simple');

    expect($agentConfig)->toBe([
        'agent' => 'cursor-agent',
        'model' => null,
        'args' => [],
    ]);
});

it('validates config structure has complexity key', function () {
    $invalidConfig = [
        'other' => 'stuff',
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'must have "complexity" key');
});

it('creates default config file', function () {
    $this->configService->createDefaultConfig();

    expect(file_exists($this->configPath))->toBeTrue();

    $config = Yaml::parseFile($this->configPath);

    expect($config)->toHaveKey('complexity');
    expect($config)->not->toHaveKey('agents'); // No agents section in new format
    expect($config['complexity'])->toHaveKeys(['trivial', 'simple', 'moderate', 'complex']);

    // Verify default agents and models
    expect($config['complexity']['trivial']['agent'])->toBe('cursor-agent');
    expect($config['complexity']['trivial']['model'])->toBe('composer-1');
    expect($config['complexity']['simple']['agent'])->toBe('cursor-agent');
    expect($config['complexity']['simple']['model'])->toBe('composer-1');
    expect($config['complexity']['moderate']['agent'])->toBe('claude');
    expect($config['complexity']['moderate']['model'])->toBe('sonnet-4.5');
    expect($config['complexity']['complex']['agent'])->toBe('claude');
    expect($config['complexity']['complex']['model'])->toBe('opus-4.5');
});

it('does not overwrite existing config file', function () {
    $customConfig = [
        'complexity' => [
            'simple' => [
                'agent' => 'custom-agent',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($customConfig));

    $this->configService->createDefaultConfig();

    $config = Yaml::parseFile($this->configPath);
    expect($config['complexity']['simple']['agent'])->toBe('custom-agent');
});

it('allows setting custom config path', function () {
    $customPath = $this->tempDir.'/custom-config.yaml';
    $this->configService->setConfigPath($customPath);

    expect($this->configService->getConfigPath())->toBe($customPath);
});

it('accepts any agent name without validation', function () {
    $config = [
        'complexity' => [
            'simple' => [
                'agent' => 'my-custom-agent',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('simple', 'test prompt');

    expect($command)->toBe(['my-custom-agent', '-p', 'test prompt']);
});

it('supports all valid complexities', function () {
    $config = [
        'complexity' => [
            'trivial' => ['agent' => 'cursor-agent'],
            'simple' => ['agent' => 'opencode'],
            'moderate' => ['agent' => 'claude', 'model' => 'sonnet-4.5'],
            'complex' => ['agent' => 'claude', 'model' => 'opus-4.5'],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getAgentCommand('trivial', 'prompt'))->toBe(['cursor-agent', '-p', 'prompt']);
    expect($this->configService->getAgentCommand('simple', 'prompt'))->toBe(['opencode', '-p', 'prompt']);
    expect($this->configService->getAgentCommand('moderate', 'prompt'))->toBe(['claude', '-p', 'prompt', '--model', 'sonnet-4.5']);
    expect($this->configService->getAgentCommand('complex', 'prompt'))->toBe(['claude', '-p', 'prompt', '--model', 'opus-4.5']);
});

it('throws exception when complexity not found in config', function () {
    $config = [
        'complexity' => [
            'simple' => [
                'agent' => 'cursor-agent',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    expect(fn () => $this->configService->getAgentCommand('moderate', 'test prompt'))
        ->toThrow(RuntimeException::class, 'No configuration found for complexity');
});

it('throws exception when complexity config is missing agent key', function () {
    $invalidConfig = [
        'complexity' => [
            'simple' => [
                // Missing 'agent' key
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'must have \'agent\' string');
});

it('throws exception when complexity config is not an array', function () {
    $invalidConfig = [
        'complexity' => [
            'simple' => 'not-an-array',
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'must be an array');
});

it('caches config after first load', function () {
    $config = [
        'complexity' => [
            'simple' => [
                'agent' => 'cursor-agent',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    // First call loads config
    $command1 = $this->configService->getAgentCommand('simple', 'test prompt');
    expect($command1)->toBe(['cursor-agent', '-p', 'test prompt']);

    // Modify file (should not affect cached config)
    file_put_contents($this->configPath, Yaml::dump([
        'complexity' => [
            'simple' => [
                'agent' => 'claude',
            ],
        ],
    ]));

    // Second call should use cached config (still returns cursor-agent)
    $command2 = $this->configService->getAgentCommand('simple', 'test prompt');
    expect($command2)->toBe(['cursor-agent', '-p', 'test prompt']);

    // Reset cache by setting new path
    $this->configService->setConfigPath($this->configPath);

    // Now should load new config
    $command3 = $this->configService->getAgentCommand('simple', 'test prompt');
    expect($command3)->toBe(['claude', '-p', 'test prompt']);
});

it('throws exception when YAML parses to non-array', function () {
    // YAML that parses to a string instead of array
    file_put_contents($this->configPath, 'just a string');

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'Invalid config format');
});

it('tests getAgentCommand output format for all complexities', function () {
    $config = [
        'complexity' => [
            'trivial' => ['agent' => 'cursor-agent'],
            'simple' => ['agent' => 'cursor-agent'],
            'moderate' => ['agent' => 'claude', 'model' => 'sonnet-4.5'],
            'complex' => ['agent' => 'claude', 'model' => 'opus-4.5'],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    // Test output format: array of strings [command, -p, prompt, --model?, model?]
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

it('filters out non-string args', function () {
    $config = [
        'complexity' => [
            'complex' => [
                'agent' => 'claude',
                'args' => ['--valid', 123, '--also-valid', null],
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('complex', 'test');

    // Only string args should be included
    expect($command)->toBe(['claude', '-p', 'test', '--valid', '--also-valid']);
});
