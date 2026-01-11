<?php

use App\Services\FuelContext;
use App\Services\DatabaseService;
use App\Services\TaskService;
use App\Services\BacklogService;
use App\Services\RunService;
use Illuminate\Support\Facades\Artisan;

// Runs Command Tests
describe('runs command', function (): void {
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
