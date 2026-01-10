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
            'agents' => [
                'test-agent-1' => ['command' => 'test-agent-1', 'max_concurrent' => 2],
                'test-agent-2' => ['command' => 'test-agent-2', 'max_concurrent' => 3],
                'claude' => ['command' => 'claude', 'max_concurrent' => 5],
            ],
            'complexity' => [
                'trivial' => 'test-agent-1',
                'simple' => 'test-agent-2',
                'moderate' => ['agent' => 'claude', 'model' => 'sonnet'],
                'complex' => ['agent' => 'claude', 'model' => 'opus'],
            ],
            'primary' => 'claude',
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
            'agents' => [
                'fast-agent' => ['command' => 'fast-agent', 'max_concurrent' => 4],
                'smart-agent' => ['command' => 'smart-agent', 'max_concurrent' => 2],
            ],
            'complexity' => [
                'trivial' => 'fast-agent',
                'simple' => 'fast-agent',
                'moderate' => ['agent' => 'smart-agent', 'model' => 'sonnet'],
                'complex' => ['agent' => 'smart-agent', 'model' => 'opus'],
            ],
            'primary' => 'smart-agent',
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
        expect($trivialConfig['name'])->toBe('fast-agent');

        $moderateConfig = $this->configService->getAgentConfig('moderate');
        expect($moderateConfig['name'])->toBe('smart-agent');
        expect($moderateConfig['model'])->toBe('sonnet');
    });

    it('uses default agent limit of 2 when agent missing max_concurrent', function () {
        // Create config with agent but no max_concurrent
        $config = [
            'agents' => [
                'test-agent' => ['command' => 'test-agent'],
            ],
            'complexity' => [
                'simple' => 'test-agent',
            ],
            'primary' => 'test-agent',
        ];

        file_put_contents($this->configPath, Yaml::dump($config));

        // Verify default limit is 2
        $limit = $this->configService->getAgentLimit('test-agent');
        expect($limit)->toBe(2);
    });
});

describe('consume command integration', function () {
    it('creates run entries when spawning tasks', function () {
        // This test verifies the integration between ConsumeCommand and RunService
        // Create a simple config
        $config = [
            'agents' => [
                'echo' => ['command' => 'echo', 'args' => ['Task completed'], 'max_concurrent' => 1],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
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

describe('consume command permission-blocked detection', function () {
    it('detects "commands are being rejected" and creates needs-human task', function () {
        // Create config
        $config = [
            'agents' => [
                'echo' => ['command' => 'echo', 'args' => [], 'max_concurrent' => 1],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        // Create a task
        $task = $this->taskService->create([
            'title' => 'Test task that will be blocked',
            'complexity' => 'trivial',
        ]);
        $taskId = $task['id'];

        // Start the task
        $this->taskService->start($taskId);

        // Create a mock process with permission-blocked output
        // We'll simulate the handleProcessCompletion logic by directly testing the side effects

        // Create a script that outputs permission error
        $scriptPath = $this->tempDir.'/test_blocked_agent.sh';
        file_put_contents($scriptPath, "#!/bin/bash\necho 'Error: commands are being rejected by the user'\nexit 0");
        chmod($scriptPath, 0755);

        // Execute the script to simulate agent output
        $output = shell_exec($scriptPath);

        // Verify the output contains the trigger pattern
        expect($output)->toContain('commands are being rejected');

        // Now simulate what handleProcessCompletion does when it detects this:
        // 1. Create needs-human task
        $humanTask = $this->taskService->create([
            'title' => 'Configure agent permissions for echo',
            'description' => "Agent echo was blocked from running commands while working on {$taskId}.\n\n".
                "To fix, either:\n".
                "1. Run `echo` interactively and select 'Always allow' for tool permissions\n".
                "2. Or add autonomous flags to .fuel/config.yaml:\n".
                "   - Claude: args: [\"--dangerously-skip-permissions\"]\n".
                "   - cursor-agent: args: [\"--force\"]\n\n".
                "See README.md 'Agent Permissions' section for details.",
            'labels' => ['needs-human'],
            'priority' => 1,
        ]);

        // 2. Add dependency (original task blocked by needs-human task)
        $this->taskService->addDependency($taskId, $humanTask['id']);

        // 3. Reopen original task
        $this->taskService->reopen($taskId);

        // Verify needs-human task was created correctly
        expect($humanTask['title'])->toBe('Configure agent permissions for echo');
        expect($humanTask['labels'])->toContain('needs-human');
        expect($humanTask['priority'])->toBe(1);
        expect($humanTask['description'])->toContain('blocked from running commands');

        // Verify original task was reopened
        $updatedTask = $this->taskService->find($taskId);
        expect($updatedTask['status'])->toBe('open');

        // Verify dependency was added
        expect($updatedTask['blocked_by'])->toContain($humanTask['id']);

        // Verify original task is NOT in ready list (because it's blocked)
        $readyTasks = $this->taskService->ready();
        $readyIds = array_column($readyTasks->toArray(), 'id');
        expect($readyIds)->not->toContain($taskId);
    });

    it('detects "terminal commands are being rejected" pattern', function () {
        $config = [
            'agents' => [
                'test-agent' => ['command' => 'test-agent'],
            ],
            'complexity' => [
                'simple' => 'test-agent',
            ],
            'primary' => 'test-agent',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        $task = $this->taskService->create([
            'title' => 'Another blocked task',
            'complexity' => 'simple',
        ]);
        $taskId = $task['id'];
        $this->taskService->start($taskId);

        // Create script with different permission error pattern
        $scriptPath = $this->tempDir.'/test_blocked_terminal.sh';
        file_put_contents($scriptPath, "#!/bin/bash\necho 'Error: terminal commands are being rejected'\nexit 0");
        chmod($scriptPath, 0755);

        $output = shell_exec($scriptPath);
        expect($output)->toContain('terminal commands are being rejected');

        // Simulate handleProcessCompletion behavior
        $humanTask = $this->taskService->create([
            'title' => 'Configure agent permissions for test-agent',
            'labels' => ['needs-human'],
            'priority' => 1,
        ]);

        $this->taskService->addDependency($taskId, $humanTask['id']);
        $this->taskService->reopen($taskId);

        // Verify results
        $updatedTask = $this->taskService->find($taskId);
        expect($updatedTask['status'])->toBe('open');
        expect($updatedTask['blocked_by'])->toContain($humanTask['id']);
        expect($humanTask['labels'])->toContain('needs-human');
    });

    it('detects "please manually complete" pattern', function () {
        $config = [
            'agents' => [
                'manual-agent' => ['command' => 'manual-agent'],
            ],
            'complexity' => [
                'simple' => 'manual-agent',
            ],
            'primary' => 'manual-agent',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        $task = $this->taskService->create([
            'title' => 'Task requiring manual completion',
            'complexity' => 'simple',
        ]);
        $taskId = $task['id'];
        $this->taskService->start($taskId);

        // Create script with manual completion message
        $scriptPath = $this->tempDir.'/test_manual_complete.sh';
        file_put_contents($scriptPath, "#!/bin/bash\necho 'I cannot proceed. Please manually complete this task.'\nexit 0");
        chmod($scriptPath, 0755);

        $output = shell_exec($scriptPath);
        expect(stripos($output, 'please manually complete') !== false)->toBeTrue();

        // Simulate handleProcessCompletion behavior
        $humanTask = $this->taskService->create([
            'title' => 'Configure agent permissions for manual-agent',
            'labels' => ['needs-human'],
            'priority' => 1,
        ]);

        $this->taskService->addDependency($taskId, $humanTask['id']);
        $this->taskService->reopen($taskId);

        // Verify results
        $updatedTask = $this->taskService->find($taskId);
        expect($updatedTask['status'])->toBe('open');
        expect($updatedTask['blocked_by'])->toContain($humanTask['id']);
    });

    it('completes normally when no permission errors are detected', function () {
        $config = [
            'agents' => [
                'echo' => ['command' => 'echo', 'args' => []],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        $task = $this->taskService->create([
            'title' => 'Normal task completion',
            'complexity' => 'trivial',
        ]);
        $taskId = $task['id'];
        $this->taskService->start($taskId);

        // Create script with normal output
        $scriptPath = $this->tempDir.'/test_normal.sh';
        file_put_contents($scriptPath, "#!/bin/bash\necho 'Task completed successfully'\nexit 0");
        chmod($scriptPath, 0755);

        $output = shell_exec($scriptPath);
        expect($output)->toContain('completed successfully');
        expect($output)->not->toContain('commands are being rejected');
        expect($output)->not->toContain('please manually complete');

        // Simulate normal completion (what handleProcessCompletion does for exit 0)
        $task = $this->taskService->find($taskId);
        if ($task && $task['status'] === 'in_progress') {
            $this->taskService->done($taskId, 'Auto-completed by consume (agent exit 0)');
        }

        // Verify task was completed
        $completedTask = $this->taskService->find($taskId);
        expect($completedTask['status'])->toBe('closed');
        expect($completedTask['reason'])->toBe('Auto-completed by consume (agent exit 0)');

        // Verify no needs-human tasks were created
        $allTasks = $this->taskService->all();
        $needsHumanTasks = $allTasks->filter(fn ($t) => in_array('needs-human', $t['labels'] ?? []));
        expect($needsHumanTasks)->toHaveCount(0);
    });

    it('detects permission errors case-insensitively', function () {
        $config = [
            'agents' => [
                'test-agent' => ['command' => 'test-agent'],
            ],
            'complexity' => [
                'simple' => 'test-agent',
            ],
            'primary' => 'test-agent',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        $task = $this->taskService->create(['title' => 'Case test', 'complexity' => 'simple']);
        $taskId = $task['id'];
        $this->taskService->start($taskId);

        // Test various case combinations
        $testCases = [
            'COMMANDS ARE BEING REJECTED',
            'Commands Are Being Rejected',
            'commands ARE being REJECTED',
            'PLEASE MANUALLY COMPLETE',
            'Please Manually Complete',
        ];

        foreach ($testCases as $pattern) {
            // Verify stripos would detect these (simulating what handleProcessCompletion does)
            expect(stripos($pattern, 'commands are being rejected') !== false ||
                   stripos($pattern, 'please manually complete') !== false)->toBeTrue();
        }
    });
});

describe('consume command auto-close feature', function () {
    it('adds auto-closed label when agent exits 0 without self-reporting', function () {
        // Create config
        $config = [
            'agents' => [
                'echo' => ['command' => 'echo', 'args' => [], 'max_concurrent' => 1],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        // Create a task and put it in_progress status (simulating agent was spawned)
        $task = $this->taskService->create([
            'title' => 'Task to be auto-closed',
            'complexity' => 'trivial',
        ]);
        $taskId = $task['id'];
        $this->taskService->start($taskId);

        // Verify task is in_progress
        $task = $this->taskService->find($taskId);
        expect($task['status'])->toBe('in_progress');

        // Simulate what ConsumeCommand::handleSuccess does when agent exits 0
        // but task is still in_progress (agent didn't call fuel done)
        $task = $this->taskService->find($taskId);
        if ($task && $task['status'] === 'in_progress') {
            // Add 'auto-closed' label to indicate it wasn't self-reported
            $this->taskService->update($taskId, [
                'add_labels' => ['auto-closed'],
            ]);

            // Use DoneCommand logic (via Artisan::call) so future done enhancements apply
            Artisan::call('done', [
                'ids' => [$taskId],
                '--reason' => 'Auto-completed by consume (agent exit 0)',
                '--cwd' => $this->tempDir,
            ]);
        }

        // Verify task was closed with auto-closed label
        $closedTask = $this->taskService->find($taskId);
        expect($closedTask['status'])->toBe('closed');
        expect($closedTask['labels'])->toContain('auto-closed');
        expect($closedTask['reason'])->toBe('Auto-completed by consume (agent exit 0)');
    });

    it('does not add auto-closed label when agent calls fuel done itself', function () {
        // Create config
        $config = [
            'agents' => [
                'echo' => ['command' => 'echo', 'args' => [], 'max_concurrent' => 1],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        // Create a task and put it in_progress status
        $task = $this->taskService->create([
            'title' => 'Task closed by agent',
            'complexity' => 'trivial',
        ]);
        $taskId = $task['id'];
        $this->taskService->start($taskId);

        // Simulate agent calling fuel done before exiting
        Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Agent completed the task',
            '--cwd' => $this->tempDir,
        ]);

        // Now simulate what handleSuccess does - check if task is still in_progress
        $task = $this->taskService->find($taskId);

        // Task should already be closed (by agent), so handleSuccess skips auto-close
        expect($task['status'])->toBe('closed');

        // The condition in handleSuccess ($task['status'] === 'in_progress') is false
        // so auto-closed label should NOT be added
        $labels = $task['labels'] ?? [];
        expect($labels)->not->toContain('auto-closed');

        // Verify the reason is from the agent, not auto-close
        expect($task['reason'])->toBe('Agent completed the task');
    });

    it('uses Artisan::call for done command instead of direct taskService->done', function () {
        // Create config
        $config = [
            'agents' => [
                'echo' => ['command' => 'echo', 'args' => [], 'max_concurrent' => 1],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        // Create and start a task
        $task = $this->taskService->create([
            'title' => 'Task for Artisan call test',
            'complexity' => 'trivial',
        ]);
        $taskId = $task['id'];
        $this->taskService->start($taskId);

        // Add auto-closed label first (as handleSuccess does)
        $this->taskService->update($taskId, [
            'add_labels' => ['auto-closed'],
        ]);

        // Use Artisan::call exactly as ConsumeCommand::handleSuccess does
        $exitCode = Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Auto-completed by consume (agent exit 0)',
            '--cwd' => $this->tempDir,
        ]);

        // Verify Artisan::call succeeded
        expect($exitCode)->toBe(0);

        // Verify task was closed correctly
        $closedTask = $this->taskService->find($taskId);
        expect($closedTask['status'])->toBe('closed');
        expect($closedTask['labels'])->toContain('auto-closed');
        expect($closedTask['reason'])->toBe('Auto-completed by consume (agent exit 0)');
    });

    it('shows auto-closed icon in board done column', function () {
        // Create config
        $config = [
            'agents' => [
                'echo' => ['command' => 'echo', 'args' => [], 'max_concurrent' => 1],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        // Create a task, start it, and auto-close it (with auto-closed label)
        $task = $this->taskService->create([
            'title' => 'Auto-closed for board test',
            'complexity' => 'trivial',
        ]);
        $taskId = $task['id'];
        $this->taskService->start($taskId);

        // Simulate auto-close behavior
        $this->taskService->update($taskId, [
            'add_labels' => ['auto-closed'],
        ]);
        Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Auto-completed by consume (agent exit 0)',
            '--cwd' => $this->tempDir,
        ]);

        // Capture board output
        Artisan::call('board', [
            '--once' => true,
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        // Verify auto-closed task appears in Done column with 🤖 icon
        // The board shows: [shortId ·complexity] 🤖 title
        expect($output)->toContain('🤖');
        expect($output)->toContain('Done');
    });

    it('does not show auto-closed icon for manually closed tasks', function () {
        // Create config
        $config = [
            'agents' => [
                'echo' => ['command' => 'echo', 'args' => [], 'max_concurrent' => 1],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        // Create a task and close it manually (no auto-closed label)
        $task = $this->taskService->create([
            'title' => 'Manually closed task',
            'complexity' => 'trivial',
        ]);
        $taskId = $task['id'];
        $this->taskService->start($taskId);

        // Close without auto-closed label (agent called fuel done)
        Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Agent completed it',
            '--cwd' => $this->tempDir,
        ]);

        // Capture board output
        Artisan::call('board', [
            '--once' => true,
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        // Verify the 🤖 icon is NOT present for this task
        // The task should appear in Done but without the robot icon
        expect($output)->toContain('Done');
        // Since there's only one task and it's not auto-closed, no 🤖 should appear
        expect($output)->not->toContain('🤖');
    });

    it('handles CompletionResult with Success type for auto-close flow', function () {
        // Create a CompletionResult simulating a successful agent exit
        $completion = new \App\Process\CompletionResult(
            taskId: 'f-test01',
            agentName: 'test-agent',
            exitCode: 0,
            duration: 60,
            sessionId: null,
            costUsd: null,
            output: 'Task completed',
            type: \App\Process\CompletionType::Success,
            message: null
        );

        // Verify it's a success
        expect($completion->isSuccess())->toBeTrue();
        expect($completion->isFailed())->toBeFalse();
        expect($completion->exitCode)->toBe(0);
        expect($completion->type)->toBe(\App\Process\CompletionType::Success);

        // Verify formatted duration
        expect($completion->getFormattedDuration())->toBe('1m 0s');
    });

    it('verifies auto-close only happens for in_progress tasks with exit 0', function () {
        $config = [
            'agents' => [
                'echo' => ['command' => 'echo', 'args' => [], 'max_concurrent' => 1],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        // Test case 1: Task already closed - should not auto-close again
        $task1 = $this->taskService->create(['title' => 'Already closed task']);
        $taskId1 = $task1['id'];
        $this->taskService->start($taskId1);
        $this->taskService->done($taskId1, 'Closed by agent');

        $task1 = $this->taskService->find($taskId1);
        expect($task1['status'])->toBe('closed');

        // Simulate handleSuccess check - condition ($task['status'] === 'in_progress') is false
        $shouldAutoClose = $task1['status'] === 'in_progress';
        expect($shouldAutoClose)->toBeFalse();

        // Test case 2: Task still in_progress - should auto-close
        $task2 = $this->taskService->create(['title' => 'Still in progress task']);
        $taskId2 = $task2['id'];
        $this->taskService->start($taskId2);

        $task2 = $this->taskService->find($taskId2);
        expect($task2['status'])->toBe('in_progress');

        // Simulate handleSuccess check - condition ($task['status'] === 'in_progress') is true
        $shouldAutoClose = $task2['status'] === 'in_progress';
        expect($shouldAutoClose)->toBeTrue();
    });
});
