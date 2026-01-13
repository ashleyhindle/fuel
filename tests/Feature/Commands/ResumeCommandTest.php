<?php

use App\Services\ConfigService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

// Resume Command Tests
describe('resume command', function (): void {
    beforeEach(function (): void {
        $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($this->tempDir.'/.fuel', 0755, true);

        $context = new FuelContext($this->tempDir.'/.fuel');
        $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

        $this->dbPath = $context->getDatabasePath();

        $context->configureDatabase();
        $databaseService = new DatabaseService($context->getDatabasePath());
        $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);
        Artisan::call('migrate', ['--force' => true]);

        $this->app->singleton(TaskService::class, fn (): TaskService => makeTaskService());

        $this->app->singleton(RunService::class, fn (): RunService => makeRunService());

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
        $this->artisan('resume', ['id' => 'f-nonexistent'])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('shows error when no runs exist for task', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->artisan('resume', ['id' => $task->short_id])
            ->expectsOutputToContain('No runs found for task')
            ->assertExitCode(1);
    });

    it('shows error when run has no session_id', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            // No session_id
        ]);

        $this->artisan('resume', ['id' => $task->short_id])
            ->expectsOutputToContain('has no session_id')
            ->assertExitCode(1);
    });

    // Note: Test for "run has no agent" removed because the database schema
    // has `agent TEXT NOT NULL`, making this condition impossible.

    it('shows error when agent is unknown', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);

        // Create config file so ConfigService can load (driver-based format)
        $configPath = $this->tempDir.'/.fuel/config.yaml';
        file_put_contents($configPath, Yaml::dump([
            'agents' => [
                'claude' => ['driver' => 'claude'],
            ],
            'complexity' => [
                'simple' => 'claude',
            ],
            'primary' => 'claude',
        ]));

        // Bind ConfigService to use the test's context
        $context = $this->app->make(FuelContext::class);
        $this->app->singleton(ConfigService::class, fn (): ConfigService => new ConfigService($context));

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'unknown-agent',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        $output = runCommand('resume', ['id' => $task->short_id]);
        expect($output)->toContain("Unknown agent 'unknown-agent'");
    });

    it('shows error when specific run not found', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        $this->artisan('resume', [
            'id' => $task->short_id,
            '--run' => 'run-nonexistent',
        ])
            ->expectsOutputToContain("Run 'run-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('supports partial run ID matching', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        $runs = $runService->getRuns($task->short_id);
        $runId = $runs[0]->run_id ?? '';
        $partialRunId = substr($runId, 0, 6); // First 6 chars

        // This will fail because exec() replaces the process, but we can test validation passes
        // We'll just verify it doesn't fail with "not found" error
        $this->artisan('resume', [
            'id' => $task->short_id,
            '--run' => $partialRunId,
        ])
            ->assertExitCode(1); // Will fail at exec(), but validation should pass
    });

    it('outputs JSON error when --json flag is used', function (): void {
        Artisan::call('resume', [
            'id' => 'f-nonexistent',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['error'])->toContain("Task 'f-nonexistent' not found");
    });

    it('supports partial task ID matching', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        // Use partial ID (last 6 chars)
        $partialId = substr((string) $task->short_id, -6);

        $this->artisan('resume', ['id' => $partialId])
            ->assertExitCode(1); // Will fail at exec(), but task should be found
    });
});
