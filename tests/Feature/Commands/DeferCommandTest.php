<?php

use App\Enums\TaskStatus;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// Defer Command Tests
describe('defer command', function (): void {
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

        $this->app->singleton(TaskService::class, fn (): TaskService => makeTaskService($databaseService));

        $this->app->singleton(RunService::class, fn (): RunService => makeRunService($databaseService));

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

    it('defers task to backlog', function (): void {
        $task = $this->taskService->create([
            'title' => 'Task to defer',
            'description' => 'Task description',
            'priority' => 2,
        ]);

        Artisan::call('defer', [
            'id' => $task->short_id,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Deferred task:');
        expect($output)->toContain($task->short_id);
        expect($output)->toContain('Task to defer');

        // Verify task still exists with status=someday
        $deferredTask = $this->taskService->find($task->short_id);
        expect($deferredTask)->not->toBeNull();
        expect($deferredTask->status)->toBe(TaskStatus::Someday);
        expect($deferredTask->title)->toBe('Task to defer');
        expect($deferredTask->description)->toBe('Task description');
        expect($deferredTask->priority)->toBe(2);
    });

    it('defers task with partial ID', function (): void {
        $task = $this->taskService->create(['title' => 'Task to defer']);
        $partialId = substr((string) $task->short_id, 2, 3);

        Artisan::call('defer', [
            'id' => $partialId,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Deferred task:');

        // Verify task still exists with status=someday
        $deferredTask = $this->taskService->find($task->short_id);
        expect($deferredTask)->not->toBeNull();
        expect($deferredTask->status)->toBe(TaskStatus::Someday);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $task = $this->taskService->create(['title' => 'JSON task']);

        Artisan::call('defer', [
            'id' => $task->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['task_id'])->toBe($task->short_id);
        expect($result['title'])->toBe('JSON task');
        expect($result['status'])->toBe(TaskStatus::Someday->value); // JSON output is string
    });

    it('returns error when task not found', function (): void {
        $this->artisan('defer', [
            'id' => 'f-nonexistent',
        ])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('can defer task that is already someday', function (): void {
        $task = $this->taskService->create(['title' => 'Already deferred']);

        // Defer once
        $this->taskService->defer($task->short_id);

        // Defer again - should succeed (idempotent)
        Artisan::call('defer', [
            'id' => $task->short_id,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Deferred task:');

        $deferredTask = $this->taskService->find($task->short_id);
        expect($deferredTask)->not->toBeNull();
        expect($deferredTask->status)->toBe(TaskStatus::Someday);
    });
});
