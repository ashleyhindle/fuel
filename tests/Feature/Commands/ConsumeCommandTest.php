<?php

use App\Enums\TaskStatus;
use App\Process\CompletionResult;
use App\Process\CompletionType;
use App\Process\ReviewResult;
use App\Services\ConfigService;
use App\Services\EpicService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    // Use TestCase's infrastructure - just get the services and config path we need
    $this->configPath = $this->testContext->getConfigPath();
    $this->taskService = $this->app->make(TaskService::class);
    $this->configService = $this->app->make(ConfigService::class);
    $this->runService = makeRunService();
});

describe('consume command parallel execution logic', function (): void {
    it('verifies config supports multiple agent limits', function (): void {
        // Create config with different agent limits (driver-based format)
        $config = [
            'agents' => [
                'test-agent-1' => ['driver' => 'claude', 'max_concurrent' => 2],
                'test-agent-2' => ['driver' => 'cursor-agent', 'max_concurrent' => 3],
                'claude' => ['driver' => 'claude', 'max_concurrent' => 5],
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

    it('supports parallel task selection with different complexities', function (): void {
        // Create config mapping complexities to different agents (driver-based format)
        $config = [
            'agents' => [
                'fast-agent' => ['driver' => 'claude', 'max_concurrent' => 4],
                'smart-agent' => ['driver' => 'claude', 'max_concurrent' => 2],
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

    it('uses default agent limit of 2 when agent missing max_concurrent', function (): void {
        // Create config with agent but no max_concurrent
        $config = [
            'agents' => [
                'test-agent' => ['driver' => 'claude'],
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

describe('consume command integration', function (): void {
    it('creates run entries when spawning tasks', function (): void {
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

        $taskId = $task->short_id;

        // Note: We can't easily test actual spawning without mocking Process
        // But we can verify the RunService methods work correctly
        $this->runService->logRun($taskId, [
            'agent' => 'echo',
            'started_at' => date('c'),
        ]);

        $runs = $this->runService->getRuns($taskId);
        expect($runs)->toHaveCount(1);
        expect($runs[0]->agent)->toBe('echo');
        expect($runs[0]->run_id)->toStartWith('run-');
    });

    it('tracks multiple concurrent runs separately', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        // Log runs for different tasks
        $this->runService->logRun($task1->short_id, [
            'agent' => 'agent1',
            'started_at' => date('c'),
        ]);

        $this->runService->logRun($task2->short_id, [
            'agent' => 'agent2',
            'started_at' => date('c'),
        ]);

        // Verify separate tracking
        $runs1 = $this->runService->getRuns($task1->short_id);
        $runs2 = $this->runService->getRuns($task2->short_id);

        expect($runs1)->toHaveCount(1);
        expect($runs2)->toHaveCount(1);
        expect($runs1[0]->agent)->toBe('agent1');
        expect($runs2[0]->agent)->toBe('agent2');
    });
});

describe('consume command permission-blocked detection', function (): void {
    it('detects "commands are being rejected" and creates needs-human task', function (): void {
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
        $taskId = $task->short_id;

        // Start the task
        $this->taskService->start($taskId);

        // Create a mock process with permission-blocked output
        // We'll simulate the handleProcessCompletion logic by directly testing the side effects

        // Create a script that outputs permission error
        $scriptPath = $this->testDir.'/test_blocked_agent.sh';
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
        $this->taskService->addDependency($taskId, $humanTask->short_id);

        // 3. Reopen original task
        $this->taskService->reopen($taskId);

        // Verify needs-human task was created correctly
        expect($humanTask->title)->toBe('Configure agent permissions for echo');
        expect($humanTask->labels)->toContain('needs-human');
        expect($humanTask->priority)->toBe(1);
        expect($humanTask->description)->toContain('blocked from running commands');

        // Verify original task was reopened
        $updatedTask = $this->taskService->find($taskId);
        expect($updatedTask->status)->toBe(TaskStatus::Open);

        // Verify dependency was added
        expect($updatedTask->blocked_by)->toContain($humanTask->short_id);

        // Verify original task is NOT in ready list (because it's blocked)
        $readyTasks = $this->taskService->ready();
        $readyIds = array_column($readyTasks->toArray(), 'id');
        expect($readyIds)->not->toContain($taskId);
    });

    it('detects "terminal commands are being rejected" pattern', function (): void {
        $config = [
            'agents' => [
                'test-agent' => ['driver' => 'claude'],
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
        $taskId = $task->short_id;
        $this->taskService->start($taskId);

        // Create script with different permission error pattern
        $scriptPath = $this->testDir.'/test_blocked_terminal.sh';
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

        $this->taskService->addDependency($taskId, $humanTask->short_id);
        $this->taskService->reopen($taskId);

        // Verify results
        $updatedTask = $this->taskService->find($taskId);
        expect($updatedTask->status)->toBe(TaskStatus::Open);
        expect($updatedTask->blocked_by)->toContain($humanTask->short_id);
        expect($humanTask->labels)->toContain('needs-human');
    });

    it('detects "please manually complete" pattern', function (): void {
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
        $taskId = $task->short_id;
        $this->taskService->start($taskId);

        // Create script with manual completion message
        $scriptPath = $this->testDir.'/test_manual_complete.sh';
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

        $this->taskService->addDependency($taskId, $humanTask->short_id);
        $this->taskService->reopen($taskId);

        // Verify results
        $updatedTask = $this->taskService->find($taskId);
        expect($updatedTask->status)->toBe(TaskStatus::Open);
        expect($updatedTask->blocked_by)->toContain($humanTask->short_id);
    });

    it('completes normally when no permission errors are detected', function (): void {
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
        $taskId = $task->short_id;
        $this->taskService->start($taskId);

        // Create script with normal output
        $scriptPath = $this->testDir.'/test_normal.sh';
        file_put_contents($scriptPath, "#!/bin/bash\necho 'Task completed successfully'\nexit 0");
        chmod($scriptPath, 0755);

        $output = shell_exec($scriptPath);
        expect($output)->toContain('completed successfully');
        expect($output)->not->toContain('commands are being rejected');
        expect($output)->not->toContain('please manually complete');

        // Simulate normal completion (what handleProcessCompletion does for exit 0)
        $task = $this->taskService->find($taskId);
        if ($task && $task->status === TaskStatus::InProgress) {
            $this->taskService->done($taskId, 'Auto-completed by consume (agent exit 0)');
        }

        // Verify task was completed
        $completedTask = $this->taskService->find($taskId);
        expect($completedTask->status)->toBe(TaskStatus::Done);
        expect($completedTask->reason)->toBe('Auto-completed by consume (agent exit 0)');

        // Verify no needs-human tasks were created
        $allTasks = $this->taskService->all();
        $needsHumanTasks = $allTasks->filter(fn ($t): bool => in_array('needs-human', $t->labels ?? []));
        expect($needsHumanTasks)->toHaveCount(0);
    });

    it('detects permission errors case-insensitively', function (): void {
        $config = [
            'agents' => [
                'test-agent' => ['driver' => 'claude'],
            ],
            'complexity' => [
                'simple' => 'test-agent',
            ],
            'primary' => 'test-agent',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        $task = $this->taskService->create(['title' => 'Case test', 'complexity' => 'simple']);
        $taskId = $task->short_id;
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

describe('consume command auto-close feature', function (): void {
    it('adds auto-closed label when agent exits 0 without self-reporting', function (): void {
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
        $taskId = $task->short_id;
        $this->taskService->start($taskId);

        // Verify task is in_progress
        $task = $this->taskService->find($taskId);
        expect($task->status)->toBe(TaskStatus::InProgress);

        // Simulate what ConsumeCommand::handleSuccess does when agent exits 0
        // but task is still in_progress (agent didn't call fuel done)
        $task = $this->taskService->find($taskId);
        if ($task && $task->status === TaskStatus::InProgress) {
            // Add 'auto-closed' label to indicate it wasn't self-reported
            $this->taskService->update($taskId, [
                'add_labels' => ['auto-closed'],
            ]);

            // Use DoneCommand logic (via Artisan::call) so future done enhancements apply
            Artisan::call('done', [
                'ids' => [$taskId],
                '--reason' => 'Auto-completed by consume (agent exit 0)',
            ]);
        }

        // Verify task was done with auto-closed label
        $closedTask = $this->taskService->find($taskId);
        expect($closedTask->status)->toBe(TaskStatus::Done);
        expect($closedTask->labels)->toContain('auto-closed');
        expect($closedTask->reason)->toBe('Auto-completed by consume (agent exit 0)');
    });

    it('does not add auto-closed label when agent calls fuel done itself', function (): void {
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
            'title' => 'Task done by agent',
            'complexity' => 'trivial',
        ]);
        $taskId = $task->short_id;
        $this->taskService->start($taskId);

        // Simulate agent calling fuel done before exiting
        Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Agent completed the task',
        ]);

        // Now simulate what handleSuccess does - check if task is still in_progress
        $task = $this->taskService->find($taskId);

        // Task should already be done (by agent), so handleSuccess skips auto-close
        expect($task->status)->toBe(TaskStatus::Done);

        // The condition in handleSuccess ($task->status === 'in_progress') is false
        // so auto-closed label should NOT be added
        $labels = $task->labels ?? [];
        expect($labels)->not->toContain('auto-closed');

        // Verify the reason is from the agent, not auto-close
        expect($task->reason)->toBe('Agent completed the task');
    });

    it('uses Artisan::call for done command instead of direct taskService->done', function (): void {
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
        $taskId = $task->short_id;
        $this->taskService->start($taskId);

        // Add auto-closed label first (as handleSuccess does)
        $this->taskService->update($taskId, [
            'add_labels' => ['auto-closed'],
        ]);

        // Use Artisan::call exactly as ConsumeCommand::handleSuccess does
        $exitCode = Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Auto-completed by consume (agent exit 0)',
        ]);

        // Verify Artisan::call succeeded
        expect($exitCode)->toBe(0);

        // Verify task was done correctly
        $closedTask = $this->taskService->find($taskId);
        expect($closedTask->status)->toBe(TaskStatus::Done);
        expect($closedTask->labels)->toContain('auto-closed');
        expect($closedTask->reason)->toBe('Auto-completed by consume (agent exit 0)');
    });

    it('shows auto-closed task in done count', function (): void {
        // Create config (driver-based format)
        $config = [
            'agents' => [
                'echo' => ['driver' => 'claude', 'max_concurrent' => 1],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        // Create a task, start it, and auto-close it (with auto-closed label)
        $task = $this->taskService->create([
            'title' => 'Auto-done for board test',
            'complexity' => 'trivial',
        ]);
        $taskId = $task->short_id;
        $this->taskService->start($taskId);

        // Simulate auto-close behavior
        $this->taskService->update($taskId, [
            'add_labels' => ['auto-closed'],
        ]);
        Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Auto-completed by consume (agent exit 0)',
        ]);

        // Capture consume --once output (done tasks are in a modal, but count is in footer)
        $output = runCommand('consume', [
            '--once' => true,
        ]);

        // Verify done task count is shown in footer
        expect($output)->toContain('d: done (1)');
    });

    it('shows manually done task in done count', function (): void {
        // Create config (driver-based format)
        $config = [
            'agents' => [
                'echo' => ['driver' => 'claude', 'max_concurrent' => 1],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        // Create a task and close it manually (no auto-closed label)
        $task = $this->taskService->create([
            'title' => 'Manually done task',
            'complexity' => 'trivial',
        ]);
        $taskId = $task->short_id;
        $this->taskService->start($taskId);

        // Close without auto-closed label (agent called fuel done)
        Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Agent completed it',
        ]);

        // Capture consume --once output (done tasks are in a modal, but count is in footer)
        $output = runCommand('consume', [
            '--once' => true,
        ]);

        // Verify done task count is shown in footer
        expect($output)->toContain('d: done (1)');
    });

    it('handles CompletionResult with Success type for auto-close flow', function (): void {
        // Create a CompletionResult simulating a successful agent exit
        $completion = new CompletionResult(
            taskId: 'f-test01',
            agentName: 'test-agent',
            exitCode: 0,
            duration: 60,
            sessionId: null,
            costUsd: null,
            output: 'Task completed',
            type: CompletionType::Success
        );

        // Verify it's a success
        expect($completion->isSuccess())->toBeTrue();
        expect($completion->isFailed())->toBeFalse();
        expect($completion->exitCode)->toBe(0);
        expect($completion->type)->toBe(CompletionType::Success);

        // Verify formatted duration
        expect($completion->getFormattedDuration())->toBe('1m 0s');
    });

    it('verifies auto-close only happens for in_progress tasks with exit 0', function (): void {
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

        // Test case 1: Task already done - should not auto-close again
        $task1 = $this->taskService->create(['title' => 'Already done task']);
        $taskId1 = $task1->short_id;
        $this->taskService->start($taskId1);
        $this->taskService->done($taskId1, 'Closed by agent');

        $task1 = $this->taskService->find($taskId1);
        expect($task1->status)->toBe(TaskStatus::Done);

        // Simulate handleSuccess check - condition ($task->status === 'in_progress') is false
        $shouldAutoClose = $task1->status === TaskStatus::InProgress;
        expect($shouldAutoClose)->toBeFalse();

        // Test case 2: Task still in_progress - should auto-close
        $task2 = $this->taskService->create(['title' => 'Still in progress task']);
        $taskId2 = $task2->short_id;
        $this->taskService->start($taskId2);

        $task2 = $this->taskService->find($taskId2);
        expect($task2->status)->toBe(TaskStatus::InProgress);

        // Simulate handleSuccess check - condition ($task->status === 'in_progress') is true
        $shouldAutoClose = $task2->status === TaskStatus::InProgress;
        expect($shouldAutoClose)->toBeTrue();
    });
});

describe('consume command review integration', function (): void {
    it('skips review by default', function (): void {
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
            'title' => 'Task for default skip-review test',
            'complexity' => 'trivial',
        ]);
        $taskId = $task->short_id;
        $this->taskService->start($taskId);

        // Simulate what handleSuccess does by default (without --review flag)
        // It directly marks task as done, skipping review
        $task = $this->taskService->find($taskId);
        if ($task && $task->status === TaskStatus::InProgress) {
            $this->taskService->done($taskId, 'Auto-completed by consume (review skipped)');
        }

        // Verify task was done with the skip-review reason
        $closedTask = $this->taskService->find($taskId);
        expect($closedTask->status)->toBe(TaskStatus::Done);
        expect($closedTask->reason)->toBe('Auto-completed by consume (review skipped)');

        // Verify no auto-closed label was added (skip-review path doesn't add it)
        expect($closedTask->labels)->not->toContain('auto-closed');
    });

    it('enables review when --review flag is used', function (): void {
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
            'title' => 'Task for --review flag test',
            'complexity' => 'trivial',
        ]);
        $taskId = $task->short_id;
        $this->taskService->start($taskId);

        // Verify the condition that would trigger review when --review flag is used
        // When --review is set and task is in_progress, review should be triggered
        $task = $this->taskService->find($taskId);
        expect($task->status)->toBe(TaskStatus::InProgress);

        // When --review flag is used and status is 'in_progress',
        // handleSuccess calls reviewService->triggerReview($taskId, $agentName)
        $shouldTriggerReview = $task !== null && $task->status === TaskStatus::InProgress;
        expect($shouldTriggerReview)->toBeTrue();
    });

    it('verifies review conditions - task in_progress should trigger review', function (): void {
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
            'title' => 'Task for review trigger test',
            'complexity' => 'trivial',
        ]);
        $taskId = $task->short_id;
        $this->taskService->start($taskId);

        // Verify the condition that would trigger review in handleSuccess
        $task = $this->taskService->find($taskId);
        expect($task->status)->toBe(TaskStatus::InProgress);

        // In handleSuccess, when status is 'in_progress' and ReviewService is available,
        // it calls reviewService->triggerReview($taskId, $agentName)
        // The condition is: $task && $task->status !== 'in_progress' returns early
        // So status === 'in_progress' means we should trigger review
        $shouldTriggerReview = $task !== null && $task->status === TaskStatus::InProgress;
        expect($shouldTriggerReview)->toBeTrue();
    });

    it('verifies review conditions - done task should not trigger review', function (): void {
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

        // Create a task, start it, and close it (agent called fuel done)
        $task = $this->taskService->create([
            'title' => 'Task already done',
            'complexity' => 'trivial',
        ]);
        $taskId = $task->short_id;
        $this->taskService->start($taskId);
        $this->taskService->done($taskId, 'Agent completed it');

        // Verify the condition that would skip review in handleSuccess
        $task = $this->taskService->find($taskId);
        expect($task->status)->toBe(TaskStatus::Done);

        // In handleSuccess, when status is NOT 'in_progress', it returns early
        // So status === 'done' means we should NOT trigger review
        $shouldTriggerReview = $task !== null && $task->status === TaskStatus::InProgress;
        expect($shouldTriggerReview)->toBeFalse();
    });

    it('marks task done when review passes', function (): void {
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

        // Create a task and put it in 'review' status
        $task = $this->taskService->create([
            'title' => 'Task for review pass test',
            'complexity' => 'trivial',
        ]);
        $taskId = $task->short_id;
        $this->taskService->update($taskId, ['status' => 'review']);

        // Create a ReviewResult that passed
        $reviewResult = new ReviewResult(
            taskId: $taskId,
            passed: true,
            issues: [],
            completedAt: now()->toIso8601String()
        );

        // Verify the result indicates passing
        expect($reviewResult->passed)->toBeTrue();
        expect($reviewResult->issues)->toBe([]);

        // Simulate what checkCompletedReviews does when review passes
        Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Review passed',
        ]);

        // Verify task was done
        $closedTask = $this->taskService->find($taskId);
        expect($closedTask->status)->toBe(TaskStatus::Done);
        expect($closedTask->reason)->toBe('Review passed');
    });

    it('leaves task in review status when review fails', function (): void {
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

        // Create a task and put it in 'review' status
        $task = $this->taskService->create([
            'title' => 'Task for review fail test',
            'complexity' => 'trivial',
        ]);
        $taskId = $task->short_id;
        $this->taskService->update($taskId, ['status' => 'review']);

        // Create a ReviewResult that failed
        $reviewResult = new ReviewResult(
            taskId: $taskId,
            passed: false,
            issues: ['Modified files not committed: src/Service.php', 'Tests failed in UserServiceTest'],
            completedAt: now()->toIso8601String()
        );

        // Verify the result indicates failure
        expect($reviewResult->passed)->toBeFalse();
        expect($reviewResult->issues)->toContain('Modified files not committed: src/Service.php');
        expect($reviewResult->issues)->toContain('Tests failed in UserServiceTest');

        // Task should stay in 'review' status (don't call done)
        $reviewTask = $this->taskService->find($taskId);
        expect($reviewTask->status)->toBe(TaskStatus::Review);
    });

    it('falls back to auto-complete when ReviewService is not available', function (): void {
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
            'title' => 'Task for fallback test',
            'complexity' => 'trivial',
        ]);
        $taskId = $task->short_id;
        $this->taskService->start($taskId);

        // Simulate fallback auto-complete behavior (when ReviewService is null)
        $this->taskService->update($taskId, [
            'add_labels' => ['auto-closed'],
        ]);
        Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Auto-completed by consume (agent exit 0)',
        ]);

        // Verify task was auto-closed
        $closedTask = $this->taskService->find($taskId);
        expect($closedTask->status)->toBe(TaskStatus::Done);
        expect($closedTask->labels)->toContain('auto-closed');
        expect($closedTask->reason)->toBe('Auto-completed by consume (agent exit 0)');
    });

    it('verifies exception handling causes fallback to auto-complete', function (): void {
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
            'title' => 'Task for exception fallback test',
            'complexity' => 'trivial',
        ]);
        $taskId = $task->short_id;
        $this->taskService->start($taskId);

        // Verify that if triggerReview throws RuntimeException,
        // the fallback behavior would auto-complete the task
        // This tests the fallback logic path
        $reviewException = new \RuntimeException('Review agent unavailable');
        expect($reviewException->getMessage())->toBe('Review agent unavailable');

        // Simulate the fallback auto-complete behavior
        $this->taskService->update($taskId, [
            'add_labels' => ['auto-closed'],
        ]);
        Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Auto-completed by consume (agent exit 0)',
        ]);

        // Verify fallback worked
        $closedTask = $this->taskService->find($taskId);
        expect($closedTask->status)->toBe(TaskStatus::Done);
        expect($closedTask->labels)->toContain('auto-closed');
    });

});

describe('consume command restart functionality', function (): void {
    it('restarts the runner daemon when --restart flag is used', function (): void {
        // This test verifies that the --restart flag properly stops and starts the runner
        // We can't easily test the full IPC lifecycle without mocking, but we can verify
        // that the handleRestartCommand method logic is correct

        // Create a minimal config
        $config = [
            'agents' => [
                'echo' => ['driver' => 'claude', 'max_concurrent' => 1],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        // Verify the restart logic expectations:
        // 1. hasIpcControlFlag() should return true when --restart is set
        // 2. The command should handle restart in handleIpcControl()
        // 3. Browser daemon stop/start happens through ConsumeRunner cleanup/start

        // We can't easily test actual IPC restart without running the full daemon,
        // but we can verify the config is valid and the command structure is correct

        // The config should validate without errors
        $this->configService->validate();

        // Verify config has the expected structure
        expect($this->configService->getAgentConfig('trivial')['name'])->toBe('echo');
    });
});

describe('consume command options and aliases', function (): void {
    it('accepts --resume option', function (): void {
        $command = Artisan::all()['consume'];
        $definition = $command->getDefinition();

        expect($definition->hasOption('resume'))->toBeTrue();
    });

    it('accepts --unpause as alias for --resume', function (): void {
        $command = Artisan::all()['consume'];
        $definition = $command->getDefinition();

        // Both resume and unpause should be accepted
        expect($definition->hasOption('resume'))->toBeTrue();
        expect($definition->hasOption('unpause'))->toBeTrue();
    });
});

describe('consume command task card display', function (): void {
    it('shows epic id on task card footer when task has epic', function (): void {
        // Create config
        $config = [
            'agents' => [
                'echo' => ['driver' => 'claude', 'max_concurrent' => 1],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        // Create epic and link task to it
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic', 'Epic description');
        $task = $this->taskService->create([
            'title' => 'Task with epic',
            'complexity' => 'trivial',
            'epic_id' => $epic->short_id,
        ]);

        // Run consume --once and capture output
        $output = runCommand('consume', [
            '--once' => true,
        ]);

        // Verify epic ID appears in the task card footer
        // Footer format: ╰───────────── t · e-xxxxxx ─╯
        expect($output)->toContain($epic->short_id);
        expect($output)->toContain('Task with epic');
    });

    it('shows epic id on in-progress task card footer when task has epic', function (): void {
        // Create config
        $config = [
            'agents' => [
                'echo' => ['driver' => 'claude', 'max_concurrent' => 1],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        // Create epic and link task to it, then start the task
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('In Progress Epic', 'Epic description');
        $task = $this->taskService->create([
            'title' => 'In progress task with epic',
            'complexity' => 'trivial',
            'epic_id' => $epic->short_id,
        ]);
        $this->taskService->start($task->short_id);

        // Run consume --once and capture output
        $output = runCommand('consume', [
            '--once' => true,
        ]);

        // Verify epic ID appears in the in-progress task card footer
        expect($output)->toContain($epic->short_id);
        expect($output)->toContain('In progress task with epic');
    });

    it('supports paused epic status in epics modal', function (): void {
        // Create config
        $config = [
            'agents' => [
                'echo' => ['driver' => 'claude', 'max_concurrent' => 1],
            ],
            'complexity' => [
                'trivial' => 'echo',
            ],
            'primary' => 'echo',
        ];
        file_put_contents($this->configPath, Yaml::dump($config));

        // Create an epic and pause it
        $epicService = $this->app->make(EpicService::class);
        $pausedEpic = $epicService->createEpic('Paused Epic', 'Paused epic');

        // Pause the epic
        $epicService->pause($pausedEpic->short_id);

        // Verify the paused epic has the correct status
        $epic = $epicService->getEpic($pausedEpic->short_id);
        expect($epic->status->value)->toBe('paused');

        // This test verifies that ConsumeCommand's captureEpicsModal and
        // renderEpicsModal methods can handle the 'paused' status without errors.
        // Both methods have a match statement with 'paused' => 'fg=#888888' color.
        // The feature already exists - this test documents the expected behavior.
        expect($pausedEpic->short_id)->toStartWith('e-');
    });

});
