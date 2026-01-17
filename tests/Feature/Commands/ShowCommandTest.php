<?php

use App\Services\DatabaseService;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// Show Command Tests
describe('show command', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
    });

    it('shows task details with all fields', function (): void {
        $task = $this->taskService->create([
            'title' => 'Test task',
            'description' => 'Test description',
            'type' => 'feature',
            'priority' => 3,
            'labels' => ['frontend', 'backend'],
        ]);

        $this->artisan('show', ['id' => $task->short_id])
            ->expectsOutputToContain('Task: '.$task->short_id)
            ->expectsOutputToContain('Title: Test task')
            ->expectsOutputToContain('Status: open')
            ->expectsOutputToContain('Test description')
            ->expectsOutputToContain('Type: feature')
            ->expectsOutputToContain('Priority: P3')
            ->expectsOutputToContain('Labels: frontend, backend')
            ->assertExitCode(0);
    });

    it('shows multiline descriptions with proper indentation', function (): void {
        $task = $this->taskService->create([
            'title' => 'Test multiline',
            'description' => "Line 1\nLine 2\nLine 3",
        ]);

        Artisan::call('show', ['id' => $task->short_id]);
        $output = Artisan::output();

        expect($output)->toContain('── Description ──');
        expect($output)->toContain('  Line 1');
        expect($output)->toContain('  Line 2');
        expect($output)->toContain('  Line 3');
    });

    it('shows multiline titles with proper indentation', function (): void {
        $task = $this->taskService->create([
            'title' => "Multi-line task title\nSecond line\nThird line",
            'description' => 'Test description',
        ]);

        Artisan::call('show', ['id' => $task->short_id]);
        $output = Artisan::output();

        expect($output)->toContain('Title: Multi-line task title');
        expect($output)->toContain('         Second line');
        expect($output)->toContain('         Third line');
    });

    it('shows task with blockers in blocked_by array', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker']);
        $task = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($task->short_id, $blocker->short_id);

        $this->artisan('show', ['id' => $task->short_id])
            ->expectsOutputToContain('Blocked by: '.$blocker->short_id)
            ->assertExitCode(0);
    });

    it('shows task with reason if present', function (): void {
        $task = $this->taskService->create(['title' => 'Completed task']);
        $this->taskService->done($task->short_id, 'Fixed the issue');

        $this->artisan('show', ['id' => $task->short_id])
            ->expectsOutputToContain('Reason: Fixed the issue')
            ->assertExitCode(0);
    });

    it('shows commit hash when present', function (): void {
        $task = $this->taskService->create(['title' => 'Task with commit']);

        // Create a run with commit hash
        $runService = app(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'model' => 'test-model',
        ]);
        $runService->updateLatestRun($task->short_id, ['commit_hash' => 'abc123456']);

        $this->taskService->done($task->short_id, 'Completed', 'abc123456');

        $this->artisan('show', ['id' => $task->short_id])
            ->expectsOutputToContain('Commit: abc123456')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $task = $this->taskService->create([
            'title' => 'JSON task',
            'description' => 'JSON description',
            'type' => 'bug',
            'priority' => 4,
            'labels' => ['critical'],
        ]);

        Artisan::call('show', ['id' => $task->short_id, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['short_id'])->toBe($task->short_id);
        expect($result['title'])->toBe('JSON task');
        expect($result['description'])->toBe('JSON description');
        expect($result['type'])->toBe('bug');
        expect($result['priority'])->toBe(4);
        expect($result['labels'])->toBe(['critical']);
    });

    it('includes commit hash in JSON output when present', function (): void {
        $task = $this->taskService->create(['title' => 'Task with commit']);

        // Create a run with commit hash
        $runService = app(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'model' => 'test-model',
        ]);
        $runService->updateLatestRun($task->short_id, ['commit_hash' => 'abc123456']);

        $this->taskService->done($task->short_id, 'Completed', 'abc123456');

        Artisan::call('show', ['id' => $task->short_id, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toHaveKey('commit_hash');
        expect($result['commit_hash'])->toBe('abc123456');
    });

    it('shows error for non-existent task', function (): void {

        $this->artisan('show', ['id' => 'nonexistent'])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('supports partial ID matching', function (): void {
        $task = $this->taskService->create(['title' => 'Partial ID task']);
        $partialId = substr((string) $task->short_id, 2, 3);

        $this->artisan('show', ['id' => $partialId])
            ->expectsOutputToContain('Task: '.$task->short_id)
            ->assertExitCode(0);
    });

    it('shows epic information when task has epic_id', function (): void {
        $epicService = makeEpicService($this->taskService);
        $epic = $epicService->createEpic('Test Epic', 'Epic description');

        $task = $this->taskService->create([
            'title' => 'Task with epic',
            'epic_id' => $epic->short_id,
        ]);

        Artisan::call('show', ['id' => $task->short_id]);
        $output = Artisan::output();

        // Verify task has epic_id
        $taskData = $this->taskService->find($task->short_id);
        expect($taskData->epic)->not->toBeNull();
        expect($taskData->epic->short_id)->toBe($epic->short_id);

        expect($output)->toContain('Epic: '.$epic->short_id);
        expect($output)->toContain('Test Epic');
        expect($output)->toContain('in_progress'); // Epic status is in_progress because task is open
    });

    it('includes epic information in JSON output when task has epic_id', function (): void {
        $epicService = makeEpicService($this->taskService);
        $epic = $epicService->createEpic('JSON Epic', 'Epic description');

        $task = $this->taskService->create([
            'title' => 'Task with epic',
            'epic_id' => $epic->short_id,
        ]);

        Artisan::call('show', ['id' => $task->short_id, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['epic'])->toBeArray();
        expect($result['epic']['short_id'])->toBe($epic->short_id);
        expect($result['epic']['title'])->toBe('JSON Epic');
        expect($result['epic']['status'])->toBe('in_progress'); // Epic status is in_progress because task is open
    });

    it('shows live output from stdout.log when task is in_progress', function (): void {
        $task = $this->taskService->create(['title' => 'In progress task']);
        $this->taskService->start($task->short_id);

        $runService = $this->app->make(RunService::class);
        $runShortId = $runService->createRun($task->short_id, [
            'agent' => 'test-agent',
        ]);

        // Create processes directory and stdout.log with some content
        $processDir = $this->testDir.'/.fuel/processes/'.$runShortId;
        mkdir($processDir, 0755, true);
        $stdoutPath = $processDir.'/stdout.log';
        file_put_contents($stdoutPath, "Line 1\nLine 2\nLine 3\n");

        Artisan::call('show', ['id' => $task->short_id, '--raw' => true]);
        $output = Artisan::output();

        expect($output)->toContain('(live output)');
        expect($output)->toContain('Line 1');
        expect($output)->toContain('Line 2');
        expect($output)->toContain('Line 3');
    });

    it('shows last 50 lines from stdout.log when file has more lines', function (): void {
        $task = $this->taskService->create(['title' => 'In progress task']);
        $this->taskService->start($task->short_id);

        $runService = $this->app->make(RunService::class);
        $runShortId = $runService->createRun($task->short_id, [
            'agent' => 'test-agent',
        ]);

        // Create processes directory and stdout.log with 60 lines
        $processDir = $this->testDir.'/.fuel/processes/'.$runShortId;
        mkdir($processDir, 0755, true);
        $stdoutPath = $processDir.'/stdout.log';
        $lines = [];
        for ($i = 1; $i <= 60; $i++) {
            $lines[] = 'Line '.$i;
        }

        file_put_contents($stdoutPath, implode("\n", $lines)."\n");

        Artisan::call('show', ['id' => $task->short_id, '--raw' => true]);
        $output = Artisan::output();

        expect($output)->toContain('(live output)');
        // Should contain last 50 lines (11-60)
        expect($output)->toContain('Line 11');
        expect($output)->toContain('Line 60');
        // Should not contain first 10 lines (check for exact line matches)
        expect($output)->not->toContain("\n    Line 1\n");
        expect($output)->not->toContain("\n    Line 10\n");
    });

    it('shows regular run output when task is not in_progress', function (): void {
        $task = $this->taskService->create(['title' => 'Completed task']);
        $this->taskService->start($task->short_id);

        // Create a run with output
        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'output' => 'Run output content',
        ]);

        // Mark task as done
        $this->taskService->done($task->short_id);

        // Create stdout.log (should be ignored for done tasks)
        $databaseService = $this->app->make(DatabaseService::class);
        $run = $databaseService->fetchOne(
            'SELECT short_id FROM runs WHERE task_id = (SELECT id FROM tasks WHERE short_id = ?) ORDER BY id DESC LIMIT 1',
            [$task->short_id]
        );
        $runShortId = $run['short_id'];

        $processDir = $this->testDir.'/.fuel/processes/'.$runShortId;
        mkdir($processDir, 0755, true);
        $stdoutPath = $processDir.'/stdout.log';
        file_put_contents($stdoutPath, "Live output\n");

        Artisan::call('show', ['id' => $task->short_id, '--raw' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Run Output');
        expect($output)->not->toContain('Run Output (live)');
        expect($output)->not->toContain('Showing live output (tail)...');
        expect($output)->toContain('Run output content');
        expect($output)->not->toContain('Live output');
    });

    it('shows regular run output when stdout.log does not exist', function (): void {
        $task = $this->taskService->create(['title' => 'In progress task']);
        $this->taskService->start($task->short_id);

        // Create a run with output
        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'output' => 'Run output content',
        ]);

        Artisan::call('show', ['id' => $task->short_id, '--raw' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Run Output');
        expect($output)->not->toContain('Run Output (live)');
        expect($output)->toContain('Run output content');
    });

    it('shows --tail flag in help output', function (): void {
        Artisan::call('show', ['--help' => true]);
        $output = Artisan::output();

        expect($output)->toContain('--tail');
        expect($output)->toContain('Continuously tail the live output');
    });

    it('routes run- prefix to run:show command', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'model' => 'test-model',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        $runs = $runService->getRuns($task->short_id);
        $runId = $runs[0]->run_id;

        // Call show command with run- ID, should route to run:show
        Artisan::call('show', ['id' => $runId]);
        $output = Artisan::output();

        expect($output)->toContain('Run: '.$runId);
        expect($output)->toContain('test-agent');
        expect($output)->toContain('test-model');
    });

    it('handles --tail flag on epic without error', function (): void {
        $epicService = makeEpicService($this->taskService);
        $epic = $epicService->createEpic('Test Epic', 'Epic description');

        // Should not throw error when --tail is used with epic
        $this->artisan('show', ['id' => $epic->short_id, '--tail' => true])
            ->expectsOutputToContain('Epic: '.$epic->short_id)
            ->assertExitCode(0);
    });

    it('handles --raw flag on epic without error', function (): void {
        $epicService = makeEpicService($this->taskService);
        $epic = $epicService->createEpic('Test Epic', 'Epic description');

        // Should not throw error when --raw is used with epic
        $this->artisan('show', ['id' => $epic->short_id, '--raw' => true])
            ->expectsOutputToContain('Epic: '.$epic->short_id)
            ->assertExitCode(0);
    });

    it('passes --raw flag to run:show command', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'output' => 'Test output',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
        ]);

        $runs = $runService->getRuns($task->short_id);
        $runId = $runs[0]->run_id;

        // Call show command with --raw flag, should pass through to run:show
        Artisan::call('show', ['id' => $runId, '--raw' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Run: '.$runId);
        expect($output)->toContain('Test output');
    });

    it('shows task cost when runs have cost data', function (): void {
        $task = $this->taskService->create(['title' => 'Task with costs']);
        $this->taskService->start($task->short_id);

        // Create multiple runs with costs
        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'cost_usd' => 0.1234,
            'output' => 'Run 1',
        ]);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'cost_usd' => 0.2345,
            'output' => 'Run 2',
        ]);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'cost_usd' => null,  // Run without cost
            'output' => 'Run 3',
        ]);

        Artisan::call('show', ['id' => $task->short_id]);
        $output = Artisan::output();

        expect($output)->toContain('── Cost ──');
        expect($output)->toContain('Total: $0.3579');  // 0.1234 + 0.2345
    });

    it('does not show cost when no runs have cost data', function (): void {
        $task = $this->taskService->create(['title' => 'Task without costs']);
        $this->taskService->start($task->short_id);

        // Create runs without costs
        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'cost_usd' => null,
            'output' => 'Run 1',
        ]);

        Artisan::call('show', ['id' => $task->short_id]);
        $output = Artisan::output();

        expect($output)->not->toContain('── Cost ──');
    });

    it('includes cost in JSON output', function (): void {
        $task = $this->taskService->create(['title' => 'Task with costs']);
        $this->taskService->start($task->short_id);

        // Create runs with costs
        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'cost_usd' => 0.5,
            'output' => 'Run 1',
        ]);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'cost_usd' => 0.25,
            'output' => 'Run 2',
        ]);

        Artisan::call('show', ['id' => $task->short_id, '--json' => true]);
        $output = Artisan::output();
        $json = json_decode($output, true);

        expect($json)->toHaveKey('cost_usd');
        expect($json['cost_usd'])->toBe(0.75);
    });

    it('does not include cost in JSON when no cost data', function (): void {
        $task = $this->taskService->create(['title' => 'Task without costs']);

        Artisan::call('show', ['id' => $task->short_id, '--json' => true]);
        $output = Artisan::output();
        $json = json_decode($output, true);

        expect($json)->not->toHaveKey('cost_usd');
    });
});
