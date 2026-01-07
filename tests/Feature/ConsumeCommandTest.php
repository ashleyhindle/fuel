<?php

use App\Services\ConfigService;
use App\Services\RunService;
use App\Services\TaskService;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->storagePath = $this->tempDir.'/.fuel/tasks.jsonl';
    $this->configPath = $this->tempDir.'/.fuel/config.yaml';

    // Bind test services
    $this->app->singleton(TaskService::class, function () {
        return new TaskService($this->storagePath);
    });

    $this->app->singleton(RunService::class, function () {
        return new RunService($this->tempDir.'/.fuel/runs');
    });

    $this->app->singleton(ConfigService::class, function () {
        return new ConfigService($this->configPath);
    });

    $this->taskService = $this->app->make(TaskService::class);
    $this->configService = $this->app->make(ConfigService::class);
    $this->runService = $this->app->make(RunService::class);

    // Initialize storage
    $this->taskService->initialize();
});

afterEach(function () {
    // Recursively delete temp directory
    $deleteDir = function (string $dir) use (&$deleteDir) {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $deleteDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    };

    $deleteDir($this->tempDir);
});

describe('consume command parallel execution logic', function () {
    it('verifies config supports multiple agent limits', function () {
        // Create config with different agent limits
        $config = [
            'complexity' => [
                'trivial' => ['agent' => 'test-agent-1'],
                'simple' => ['agent' => 'test-agent-2'],
                'moderate' => ['agent' => 'claude', 'model' => 'sonnet'],
                'complex' => ['agent' => 'claude', 'model' => 'opus'],
            ],
            'agents' => [
                'test-agent-1' => ['max_concurrent' => 2],
                'test-agent-2' => ['max_concurrent' => 3],
                'claude' => ['max_concurrent' => 5],
            ],
        ];

        file_put_contents($this->configPath, Yaml::dump($config));

        // Verify each agent has correct limit
        expect($this->configService->getAgentLimit('test-agent-1'))->toBe(2);
        expect($this->configService->getAgentLimit('test-agent-2'))->toBe(3);
        expect($this->configService->getAgentLimit('claude'))->toBe(5);

        // Verify getAgentLimits returns all limits
        $limits = $this->configService->getAgentLimits();
        expect($limits)->toBe([
            'test-agent-1' => 2,
            'test-agent-2' => 3,
            'claude' => 5,
        ]);
    });

    it('supports parallel task selection with different complexities', function () {
        // Create config mapping complexities to different agents
        $config = [
            'complexity' => [
                'trivial' => ['agent' => 'fast-agent'],
                'simple' => ['agent' => 'fast-agent'],
                'moderate' => ['agent' => 'smart-agent', 'model' => 'sonnet'],
                'complex' => ['agent' => 'smart-agent', 'model' => 'opus'],
            ],
            'agents' => [
                'fast-agent' => ['max_concurrent' => 4],
                'smart-agent' => ['max_concurrent' => 2],
            ],
        ];

        file_put_contents($this->configPath, Yaml::dump($config));

        // Create multiple tasks with different complexities
        $tasks = [
            $this->taskService->create([
                'title' => 'Trivial task 1',
                'complexity' => 'trivial',
                'priority' => 0,
            ]),
            $this->taskService->create([
                'title' => 'Simple task 1',
                'complexity' => 'simple',
                'priority' => 1,
            ]),
            $this->taskService->create([
                'title' => 'Moderate task 1',
                'complexity' => 'moderate',
                'priority' => 0,
            ]),
            $this->taskService->create([
                'title' => 'Complex task 1',
                'complexity' => 'complex',
                'priority' => 2,
            ]),
        ];

        // Verify tasks are ready
        $readyTasks = $this->taskService->ready();
        expect($readyTasks)->toHaveCount(4);

        // Verify we can get agent config for each complexity
        $trivialConfig = $this->configService->getAgentConfig('trivial');
        expect($trivialConfig['agent'])->toBe('fast-agent');

        $moderateConfig = $this->configService->getAgentConfig('moderate');
        expect($moderateConfig['agent'])->toBe('smart-agent');
        expect($moderateConfig['model'])->toBe('sonnet');
    });

    it('uses default agent limit of 2 when not configured', function () {
        // Create minimal config without agents section
        $config = [
            'complexity' => [
                'simple' => ['agent' => 'test-agent'],
            ],
        ];

        file_put_contents($this->configPath, Yaml::dump($config));

        // Verify default limit is 2
        $limit = $this->configService->getAgentLimit('test-agent');
        expect($limit)->toBe(2);

        // Verify unconfigured agent also gets default
        $limit2 = $this->configService->getAgentLimit('unknown-agent');
        expect($limit2)->toBe(2);
    });
});

describe('consume command integration', function () {
    it('creates run entries when spawning tasks', function () {
        // This test verifies the integration between ConsumeCommand and RunService
        // Create a simple config
        $config = [
            'complexity' => [
                'trivial' => ['agent' => 'echo', 'args' => ['Task completed']],
            ],
            'agents' => [
                'echo' => ['max_concurrent' => 1],
            ],
        ];

        file_put_contents($this->configPath, Yaml::dump($config));

        // Create a task
        $task = $this->taskService->create([
            'title' => 'Test task for run tracking',
            'complexity' => 'trivial',
        ]);

        $taskId = $task['id'];

        // Note: We can't easily test actual spawning without mocking Process
        // But we can verify the RunService methods work correctly
        $this->runService->logRun($taskId, [
            'agent' => 'echo',
            'started_at' => date('c'),
        ]);

        $runs = $this->runService->getRuns($taskId);
        expect($runs)->toHaveCount(1);
        expect($runs[0]['agent'])->toBe('echo');
        expect($runs[0]['run_id'])->toStartWith('run-');
    });

    it('tracks multiple concurrent runs separately', function () {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        // Log runs for different tasks
        $this->runService->logRun($task1['id'], [
            'agent' => 'agent1',
            'started_at' => date('c'),
        ]);

        $this->runService->logRun($task2['id'], [
            'agent' => 'agent2',
            'started_at' => date('c'),
        ]);

        // Verify separate tracking
        $runs1 = $this->runService->getRuns($task1['id']);
        $runs2 = $this->runService->getRuns($task2['id']);

        expect($runs1)->toHaveCount(1);
        expect($runs2)->toHaveCount(1);
        expect($runs1[0]['agent'])->toBe('agent1');
        expect($runs2[0]['agent'])->toBe('agent2');
    });
});
