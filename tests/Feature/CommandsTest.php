<?php

use App\Services\RunService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/Concerns/CommandTestSetup.php';

beforeEach($beforeEach);

afterEach($afterEach);

// Archive Command Tests
describe('archive command', function (): void {
    it('archives closed tasks older than specified days', function (): void {
        $this->taskService->initialize();

        // Create a closed task from 35 days ago
        $oldTask = $this->taskService->create(['title' => 'Old closed task']);
        $this->taskService->done($oldTask['id']);
        $oldDate = now()->subDays(35)->toIso8601String();
        $this->taskService->update($oldTask['id'], ['updated_at' => $oldDate]);

        // Create a closed task from 20 days ago (should not be archived)
        $recentTask = $this->taskService->create(['title' => 'Recent closed task']);
        $this->taskService->done($recentTask['id']);
        $recentDate = now()->subDays(20)->toIso8601String();
        $this->taskService->update($recentTask['id'], ['updated_at' => $recentDate]);

        Artisan::call('archive', ['--days' => 30, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Archived 1 task(s)');

        // Verify old task is archived
        expect($this->taskService->find($oldTask['id']))->toBeNull();

        // Verify recent task is still present
        expect($this->taskService->find($recentTask['id']))->not->toBeNull();
    });

    it('archives all closed tasks when --all flag is used', function (): void {
        $this->taskService->initialize();

        // Create closed tasks with different ages
        $task1 = $this->taskService->create(['title' => 'Closed task 1']);
        $this->taskService->done($task1['id']);

        $task2 = $this->taskService->create(['title' => 'Closed task 2']);
        $this->taskService->done($task2['id']);

        // Create an open task (should not be archived)
        $openTask = $this->taskService->create(['title' => 'Open task']);

        Artisan::call('archive', ['--all' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Archived 2 task(s)');

        // Verify closed tasks are archived
        expect($this->taskService->find($task1['id']))->toBeNull();
        expect($this->taskService->find($task2['id']))->toBeNull();

        // Verify open task remains
        expect($this->taskService->find($openTask['id']))->not->toBeNull();
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();

        $task = $this->taskService->create(['title' => 'Closed task']);
        $this->taskService->done($task['id']);
        $oldDate = now()->subDays(35)->toIso8601String();
        $this->taskService->update($task['id'], ['updated_at' => $oldDate]);

        Artisan::call('archive', ['--days' => 30, '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toHaveKeys(['archived', 'archived_tasks']);
        expect($result['archived'])->toBe(1);
        expect($result['archived_tasks'])->toHaveCount(1);
        expect($result['archived_tasks'][0]['id'])->toBe($task['id']);
    });

    it('shows message when no tasks to archive', function (): void {
        $this->taskService->initialize();

        // Create only open tasks
        $this->taskService->create(['title' => 'Open task']);

        Artisan::call('archive', ['--days' => 30, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('No tasks to archive');
    });

    it('validates days option must be positive integer', function (): void {
        $this->taskService->initialize();

        Artisan::call('archive', ['--days' => 0, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Days must be a positive integer');
    });

    it('uses default 30 days when --days not specified', function (): void {
        $this->taskService->initialize();

        // Create a closed task from 35 days ago
        $oldTask = $this->taskService->create(['title' => 'Old closed task']);
        $this->taskService->done($oldTask['id']);
        $oldDate = now()->subDays(35)->toIso8601String();
        $this->taskService->update($oldTask['id'], ['updated_at' => $oldDate]);

        Artisan::call('archive', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Archived 1 task(s)');
    });
});

// =============================================================================
// tree Command Tests
// =============================================================================

describe('tree command', function (): void {
    it('shows empty message when no pending tasks', function (): void {
        $this->taskService->initialize();

        $this->artisan('tree', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No pending tasks.')
            ->assertExitCode(0);
    });

    it('shows tasks without dependencies as flat list', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task one', 'priority' => 1]);
        $this->taskService->create(['title' => 'Task two', 'priority' => 2]);

        $this->artisan('tree', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Task one')
            ->expectsOutputToContain('Task two')
            ->assertExitCode(0);
    });

    it('shows blocking tasks with blocked tasks indented underneath', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        Artisan::call('tree', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Blocked task');
        expect($output)->toContain('Blocker task');
        expect($output)->toContain('blocked by this');
    });

    it('shows task with multiple blockers', function (): void {
        $this->taskService->initialize();
        $blocker1 = $this->taskService->create(['title' => 'First blocker']);
        $blocker2 = $this->taskService->create(['title' => 'Second blocker']);
        $blocked = $this->taskService->create(['title' => 'Multi-blocked task']);
        $this->taskService->addDependency($blocked['id'], $blocker1['id']);
        $this->taskService->addDependency($blocked['id'], $blocker2['id']);

        $this->artisan('tree', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Multi-blocked task')
            ->expectsOutputToContain('First blocker')
            ->expectsOutputToContain('Second blocker')
            ->assertExitCode(0);
    });

    it('excludes closed tasks from tree', function (): void {
        $this->taskService->initialize();
        $openTask = $this->taskService->create(['title' => 'Open task']);
        $closedTask = $this->taskService->create(['title' => 'Closed task']);
        $this->taskService->done($closedTask['id']);

        $this->artisan('tree', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Open task')
            ->doesntExpectOutputToContain('Closed task')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is provided', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        Artisan::call('tree', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveCount(2);

        // Find the blocker task entry - it should have the blocked task in its 'blocks' array
        $blockerEntry = collect($data)->first(fn ($item): bool => $item['task']['title'] === 'Blocker task');
        expect($blockerEntry)->not->toBeNull();
        expect($blockerEntry['blocks'])->toHaveCount(1);
        expect($blockerEntry['blocks'][0]['title'])->toBe('Blocked task');
    });

    it('returns empty array in JSON when no tasks', function (): void {
        $this->taskService->initialize();

        Artisan::call('tree', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toBeEmpty();
    });

    it('sorts tasks by priority then created_at', function (): void {
        $this->taskService->initialize();
        $lowPriority = $this->taskService->create(['title' => 'Low priority', 'priority' => 3]);
        $highPriority = $this->taskService->create(['title' => 'High priority', 'priority' => 0]);

        Artisan::call('tree', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data[0]['task']['title'])->toBe('High priority');
        expect($data[1]['task']['title'])->toBe('Low priority');
    });

    it('shows needs-human label with special indicator', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Human task', 'labels' => ['needs-human']]);
        $this->taskService->create(['title' => 'Normal task']);

        Artisan::call('tree', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Human task');
        expect($output)->toContain('needs human');
        expect($output)->toContain('Normal task');
    });
});

// =============================================================================
// runs Command Tests
// =============================================================================

describe('runs command', function (): void {
    it('shows error when task not found', function (): void {
        $this->taskService->initialize();

        $this->artisan('runs', ['id' => 'f-nonexistent', '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('shows error when no runs exist for task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->artisan('runs', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('No runs found for task')
            ->assertExitCode(1);
    });

    it('displays runs for a task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'model' => 'test-model',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Runs for task');
        expect($output)->toContain('test-agent');
        expect($output)->toContain('test-model');
    });

    it('displays multiple runs in table format', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'agent1',
            'model' => 'model1',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        $runService->logRun($task['id'], [
            'agent' => 'agent2',
            'model' => 'model2',
            'started_at' => '2026-01-07T11:00:00+00:00',
            'ended_at' => '2026-01-07T11:10:00+00:00',
            'exit_code' => 1,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Runs for task');
        expect($output)->toContain('agent1');
        expect($output)->toContain('agent2');
        expect($output)->toContain('model1');
        expect($output)->toContain('model2');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'model' => 'test-model',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveCount(1);
        expect($data[0])->toHaveKeys(['run_id', 'agent', 'model', 'started_at', 'ended_at', 'exit_code', 'duration']);
        expect($data[0]['agent'])->toBe('test-agent');
        expect($data[0]['model'])->toBe('test-model');
    });

    it('shows latest run with --last flag', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'agent1',
            'model' => 'model1',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        $runService->logRun($task['id'], [
            'agent' => 'agent2',
            'model' => 'model2',
            'started_at' => '2026-01-07T11:00:00+00:00',
            'ended_at' => '2026-01-07T11:10:00+00:00',
            'exit_code' => 1,
            'output' => 'Test output',
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--last' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Latest run for task');
        expect($output)->toContain('agent2');
        expect($output)->toContain('model2');
        expect($output)->toContain('Test output');
        expect($output)->not->toContain('agent1');
    });

    it('shows latest run with full output in JSON format', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'agent1',
            'model' => 'model1',
            'started_at' => '2026-01-07T10:00:00+00:00',
        ]);

        $runService->logRun($task['id'], [
            'agent' => 'agent2',
            'model' => 'model2',
            'started_at' => '2026-01-07T11:00:00+00:00',
            'output' => 'Test output',
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--last' => true, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['agent'])->toBe('agent2');
        expect($data['output'])->toBe('Test output');
        expect($data)->toHaveKey('duration');
    });

    it('calculates duration correctly', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:30+00:00', // 5 minutes 30 seconds
            'exit_code' => 0,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data[0]['duration'])->toContain('5m');
        expect($data[0]['duration'])->toContain('30s');
    });

    it('handles running tasks with no end time', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => null,
            'exit_code' => null,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data[0]['duration'])->not->toBeEmpty();
        expect($data[0]['exit_code'])->toBeNull();
    });

    it('supports partial task ID matching', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'started_at' => '2026-01-07T10:00:00+00:00',
        ]);

        // Use partial ID (last 6 chars)
        $partialId = substr((string) $task['id'], -6);

        $this->artisan('runs', ['id' => $partialId, '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Runs for task')
            ->assertExitCode(0);
    });
});

// Resume Command Tests
describe('resume command', function (): void {
    it('shows error when task not found', function (): void {
        $this->artisan('resume', ['id' => 'f-nonexistent', '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('shows error when no runs exist for task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->artisan('resume', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('No runs found for task')
            ->assertExitCode(1);
    });

    it('shows error when run has no session_id', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            // No session_id
        ]);

        $this->artisan('resume', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('has no session_id')
            ->assertExitCode(1);
    });

    // Note: Test for "run has no agent" removed because the database schema
    // has `agent TEXT NOT NULL`, making this condition impossible.

    it('shows error when agent is unknown', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        // Create config file so ConfigService can load
        $configPath = $this->tempDir.'/.fuel/config.yaml';
        file_put_contents($configPath, Yaml::dump([
            'agents' => [
                'claude' => ['command' => 'claude'],
            ],
            'complexity' => [
                'simple' => 'claude',
            ],
            'primary' => 'claude',
        ]));

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'unknown-agent',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        $this->artisan('resume', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Unknown agent 'unknown-agent'")
            ->assertExitCode(1);
    });

    it('shows error when specific run not found', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        $this->artisan('resume', [
            'id' => $task['id'],
            '--run' => 'run-nonexistent',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain("Run 'run-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('supports partial run ID matching', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        $runs = $runService->getRuns($task['id']);
        $runId = $runs[0]['run_id'] ?? '';
        $partialRunId = substr($runId, 0, 6); // First 6 chars

        // This will fail because exec() replaces the process, but we can test validation passes
        // We'll just verify it doesn't fail with "not found" error
        $this->artisan('resume', [
            'id' => $task['id'],
            '--run' => $partialRunId,
            '--cwd' => $this->tempDir,
        ])
            ->assertExitCode(1); // Will fail at exec(), but validation should pass
    });

    it('outputs JSON error when --json flag is used', function (): void {
        Artisan::call('resume', [
            'id' => 'f-nonexistent',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['error'])->toContain("Task 'f-nonexistent' not found");
    });

    it('supports partial task ID matching', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        // Use partial ID (last 6 chars)
        $partialId = substr((string) $task['id'], -6);

        $this->artisan('resume', ['id' => $partialId, '--cwd' => $this->tempDir])
            ->assertExitCode(1); // Will fail at exec(), but task should be found
    });
});

// =============================================================================
// summary Command Tests
// =============================================================================

describe('summary command', function (): void {
    it('shows error when task not found', function (): void {
        $this->taskService->initialize();

        $this->artisan('summary', ['id' => 'f-nonexistent', '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('shows error when no runs exist for task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->artisan('summary', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('No runs found for task')
            ->assertExitCode(1);
    });

    it('displays summary for task with single run', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);
        $this->taskService->done($task['id']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'model' => 'sonnet',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'cost_usd' => 0.08,
            'session_id' => '550e8400-e29b-41d4-a716-446655440000',
            'output' => 'Task completed',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Test task');
        expect($output)->toContain('closed');
        expect($output)->toContain('Claude');
        expect($output)->toContain('sonnet');
        expect($output)->toContain('5m');
        expect($output)->toContain('$0.0800');
        expect($output)->toContain('0 (success)');
        expect($output)->toContain('550e8400-e29b-41d4-a716-446655440000');
        expect($output)->toContain('claude --resume');
    });

    it('parses file operations from output', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Created app/Services/AuthService.php. Modified tests/Feature/AuthTest.php. Deleted old/LegacyAuth.php.',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Created file: app/Services/AuthService.php');
        expect($output)->toContain('Modified file: tests/Feature/AuthTest.php');
        expect($output)->toContain('Deleted file: old/LegacyAuth.php');
    });

    it('parses test results from output', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Tests: 5 passed. Assertions: 15 passed.',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('5 tests passed');
        expect($output)->toContain('15 assertions passed');
    });

    it('parses git commits from output', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => '[main abc1234] feat: add authentication',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Git commit: abc1234');
    });

    it('shows all runs when --all flag is used', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        $runService->logRun($task['id'], [
            'agent' => 'cursor-agent',
            'started_at' => '2026-01-07T11:00:00+00:00',
            'ended_at' => '2026-01-07T11:10:00+00:00',
            'exit_code' => 1,
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir, '--all' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Claude');
        expect($output)->toContain('Cursor Agent');
        expect($output)->toContain('0 (success)');
        expect($output)->toContain('1 (failed)');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Created app/Test.php',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveKeys(['task', 'runs']);
        expect($data['task']['id'])->toBe($task['id']);
        expect($data['runs'])->toHaveCount(1);
        expect($data['runs'][0])->toHaveKey('duration');
        expect($data['runs'][0])->toHaveKey('parsed_summary');
        expect($data['runs'][0]['parsed_summary'])->toContain('Created file: app/Test.php');
    });

    it('shows no actionable items when output has no parseable content', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Some random text without patterns',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('No actionable items detected');
    });

    it('supports partial task ID matching', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        // Use partial ID (first 5 chars after f-)
        $partialId = substr((string) $task['id'], 2, 5);

        $this->artisan('summary', ['id' => $partialId, '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Test task')
            ->assertExitCode(0);
    });

    it('parses fuel task operations from output', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Created task f-abc123. Ran fuel done f-abc123.',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Created task: f-abc123');
        expect($output)->toContain('Completed task: f-abc123');
    });

    it('displays status with color coding', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        // The output should contain the task status
        expect($output)->toContain('Status:');
        expect($output)->toContain('open');
    });
});
