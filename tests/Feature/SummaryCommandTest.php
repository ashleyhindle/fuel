<?php

use App\Services\BacklogService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// =============================================================================
// summary Command Tests
// =============================================================================

describe('summary command', function (): void {
    beforeEach(function (): void {
        $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($this->tempDir.'/.fuel', 0755, true);

        $context = new FuelContext($this->tempDir.'/.fuel');
        $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

        $this->dbPath = $context->getDatabasePath();

        $databaseService = new DatabaseService($context->getDatabasePath());
        $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);

        $this->app->singleton(TaskService::class, fn (): TaskService => new TaskService($databaseService));

        $this->app->singleton(RunService::class, fn (): RunService => new RunService($databaseService));

        $this->app->singleton(BacklogService::class, fn (): BacklogService => new BacklogService($context));

        $this->taskService = $this->app->make(TaskService::class);
    });

    afterEach(function (): void {
        $deleteDir = function (string $dir) use (&$deleteDir): void {
            if (! is_dir($dir)) {
                return;
            }

            $items = scandir($dir);
            foreach ($items as $item) {
                if ($item === '.') {
                    continue;
                }

                if ($item === '..') {
                    continue;
                }

                $path = $dir.'/'.$item;
                if (is_dir($path)) {
                    $deleteDir($path);
                } elseif (file_exists($path)) {
                    unlink($path);
                }
            }

            rmdir($dir);
        };

        $deleteDir($this->tempDir);
    });

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
