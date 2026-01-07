<?php

use App\Services\ConfigService;
use RuntimeException;
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

it('validates agent names against presets', function () {
    $invalidConfig = [
        'agents' => [
            'invalid-agent' => [
                'command' => 'invalid-agent',
                'prompt_flag' => '-p',
            ],
        ],
        'complexity' => [
            'simple' => [
                'agent' => 'invalid-agent',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'Invalid agent name');
});

it('validates complexity values', function () {
    $config = [
        'agents' => [
            'cursor-agent' => [
                'command' => 'cursor-agent',
                'prompt_flag' => '-p',
            ],
        ],
        'complexity' => [
            'simple' => [
                'agent' => 'cursor-agent',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    expect(fn () => $this->configService->getAgentCommand('invalid-complexity', 'test prompt'))
        ->toThrow(RuntimeException::class, 'Invalid complexity');
});

it('builds command array with prompt', function () {
    $config = [
        'agents' => [
            'cursor-agent' => [
                'command' => 'cursor-agent',
                'prompt_flag' => '-p',
            ],
        ],
        'complexity' => [
            'simple' => [
                'agent' => 'cursor-agent',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('simple', 'test prompt');

    expect($command)->toBe(['cursor-agent', '-p', 'test prompt']);
});

it('builds command array with prompt and model args', function () {
    $config = [
        'agents' => [
            'claude' => [
                'command' => 'claude',
                'prompt_flag' => '-p',
                'model_flag' => '--model',
            ],
        ],
        'complexity' => [
            'moderate' => [
                'agent' => 'claude',
                'model' => 'claude-3-sonnet',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('moderate', 'test prompt');

    expect($command)->toBe(['claude', '-p', 'test prompt', '--model', 'claude-3-sonnet']);
});

it('uses default prompt flag when not specified', function () {
    $config = [
        'agents' => [
            'cursor-agent' => [
                'command' => 'cursor-agent',
            ],
        ],
        'complexity' => [
            'simple' => [
                'agent' => 'cursor-agent',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('simple', 'test prompt');

    expect($command)->toBe(['cursor-agent', '-p', 'test prompt']);
});

it('uses default model flag when not specified', function () {
    $config = [
        'agents' => [
            'claude' => [
                'command' => 'claude',
                'prompt_flag' => '-p',
            ],
        ],
        'complexity' => [
            'moderate' => [
                'agent' => 'claude',
                'model' => 'claude-3-sonnet',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    $command = $this->configService->getAgentCommand('moderate', 'test prompt');

    expect($command)->toBe(['claude', '-p', 'test prompt', '--model', 'claude-3-sonnet']);
});

it('returns agent config for complexity', function () {
    $config = [
        'agents' => [
            'claude' => [
                'command' => 'claude',
                'prompt_flag' => '-p',
                'model_flag' => '--model',
            ],
        ],
        'complexity' => [
            'moderate' => [
                'agent' => 'claude',
                'model' => 'claude-3-sonnet',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    $agentConfig = $this->configService->getAgentConfig('moderate');

    expect($agentConfig)->toBe([
        'agent' => 'claude',
        'command' => 'claude',
        'prompt_flag' => '-p',
        'model_flag' => '--model',
        'model' => 'claude-3-sonnet',
    ]);
});

it('returns agent config without model when not specified', function () {
    $config = [
        'agents' => [
            'cursor-agent' => [
                'command' => 'cursor-agent',
                'prompt_flag' => '-p',
            ],
        ],
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
        'command' => 'cursor-agent',
        'prompt_flag' => '-p',
        'model_flag' => '--model',
        'model' => null,
    ]);
});

it('validates that referenced agent exists in agents section', function () {
    $config = [
        'agents' => [
            'cursor-agent' => [
                'command' => 'cursor-agent',
                'prompt_flag' => '-p',
            ],
        ],
        'complexity' => [
            'simple' => [
                'agent' => 'claude', // Referenced but not in agents
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'not found in agents section');
});

it('validates config structure has agents key', function () {
    $invalidConfig = [
        'complexity' => [
            'simple' => [
                'agent' => 'cursor-agent',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'must have "agents" key');
});

it('validates config structure has complexity key', function () {
    $invalidConfig = [
        'agents' => [
            'cursor-agent' => [
                'command' => 'cursor-agent',
                'prompt_flag' => '-p',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'must have "complexity" key');
});

it('creates default config file', function () {
    $this->configService->createDefaultConfig();

    expect(file_exists($this->configPath))->toBeTrue();

    $config = Yaml::parseFile($this->configPath);

    expect($config)->toHaveKeys(['agents', 'complexity']);
    expect($config['agents'])->toHaveKeys(['cursor-agent', 'claude', 'opencode']);
    expect($config['complexity'])->toHaveKeys(['trivial', 'simple', 'moderate', 'complex']);
});

it('does not overwrite existing config file', function () {
    $customConfig = [
        'agents' => [
            'cursor-agent' => [
                'command' => 'custom-agent',
                'prompt_flag' => '--prompt',
            ],
        ],
        'complexity' => [
            'simple' => [
                'agent' => 'cursor-agent',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($customConfig));

    $this->configService->createDefaultConfig();

    $config = Yaml::parseFile($this->configPath);
    expect($config['agents']['cursor-agent']['command'])->toBe('custom-agent');
});

it('allows setting custom config path', function () {
    $customPath = $this->tempDir.'/custom-config.yaml';
    $this->configService->setConfigPath($customPath);

    expect($this->configService->getConfigPath())->toBe($customPath);
});

it('supports all valid agent presets', function () {
    $config = [
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
            'trivial' => ['agent' => 'cursor-agent'],
            'simple' => ['agent' => 'opencode'],
            'moderate' => ['agent' => 'claude', 'model' => 'claude-3-sonnet'],
            'complex' => ['agent' => 'claude', 'model' => 'claude-3-opus'],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    expect($this->configService->getAgentCommand('trivial', 'prompt'))->toBe(['cursor-agent', '-p', 'prompt']);
    expect($this->configService->getAgentCommand('simple', 'prompt'))->toBe(['opencode', '-p', 'prompt']);
    expect($this->configService->getAgentCommand('moderate', 'prompt'))->toBe(['claude', '-p', 'prompt', '--model', 'claude-3-sonnet']);
    expect($this->configService->getAgentCommand('complex', 'prompt'))->toBe(['claude', '-p', 'prompt', '--model', 'claude-3-opus']);
});

it('throws exception when complexity not found in config', function () {
    $config = [
        'agents' => [
            'cursor-agent' => [
                'command' => 'cursor-agent',
                'prompt_flag' => '-p',
            ],
        ],
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

it('throws exception when agent config is missing command key', function () {
    $invalidConfig = [
        'agents' => [
            'cursor-agent' => [
                'prompt_flag' => '-p',
                // Missing 'command' key
            ],
        ],
        'complexity' => [
            'simple' => [
                'agent' => 'cursor-agent',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'must have \'command\' string');
});

it('throws exception when agent config is not an array', function () {
    $invalidConfig = [
        'agents' => [
            'cursor-agent' => 'not-an-array',
        ],
        'complexity' => [
            'simple' => [
                'agent' => 'cursor-agent',
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'must be an array');
});

it('throws exception when complexity config is missing agent key', function () {
    $invalidConfig = [
        'agents' => [
            'cursor-agent' => [
                'command' => 'cursor-agent',
                'prompt_flag' => '-p',
            ],
        ],
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
        'agents' => [
            'cursor-agent' => [
                'command' => 'cursor-agent',
                'prompt_flag' => '-p',
            ],
        ],
        'complexity' => [
            'simple' => 'not-an-array',
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'must be an array');
});

it('throws exception when complexity references invalid agent name', function () {
    $invalidConfig = [
        'agents' => [
            'cursor-agent' => [
                'command' => 'cursor-agent',
                'prompt_flag' => '-p',
            ],
        ],
        'complexity' => [
            'simple' => [
                'agent' => 'invalid-agent', // Invalid agent name
            ],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($invalidConfig));

    expect(fn () => $this->configService->getAgentCommand('simple', 'test prompt'))
        ->toThrow(RuntimeException::class, 'Invalid agent');
});

it('caches config after first load', function () {
    $config = [
        'agents' => [
            'cursor-agent' => [
                'command' => 'cursor-agent',
                'prompt_flag' => '-p',
            ],
        ],
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
        'agents' => [
            'claude' => [
                'command' => 'claude',
                'prompt_flag' => '-p',
            ],
        ],
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
        ],
        'complexity' => [
            'trivial' => ['agent' => 'cursor-agent'],
            'simple' => ['agent' => 'cursor-agent'],
            'moderate' => ['agent' => 'claude', 'model' => 'claude-3-sonnet'],
            'complex' => ['agent' => 'claude', 'model' => 'claude-3-opus'],
        ],
    ];

    file_put_contents($this->configPath, Yaml::dump($config));

    // Test output format: array of strings [command, prompt_flag, prompt, model_flag?, model?]
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
    expect($moderate)->toBe(['claude', '-p', 'do something', '--model', 'claude-3-sonnet']);
    expect($moderate)->toHaveCount(5);

    $complex = $this->configService->getAgentCommand('complex', 'do something');
    expect($complex)->toBeArray();
    expect($complex)->toBe(['claude', '-p', 'do something', '--model', 'claude-3-opus']);
    expect($complex)->toHaveCount(5);
});
