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

        $databaseService = new DatabaseService($context->getDatabasePath());
        $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);

        $this->app->singleton(TaskService::class, fn (): TaskService => makeTaskService($databaseService));

        $this->app->singleton(RunService::class, fn (): RunService => new RunService($databaseService));

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
        $this->taskService->initialize();
        $task = $this->taskService->create([
            'title' => 'Task to defer',
            'description' => 'Task description',
            'priority' => 2,
        ]);

        Artisan::call('defer', [
            'id' => $task['id'],
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Deferred task:');
        expect($output)->toContain($task['id']);
        expect($output)->toContain('Task to defer');

        // Verify task still exists with status=someday
        $deferredTask = $this->taskService->find($task['id']);
        expect($deferredTask)->not->toBeNull();
        expect($deferredTask->status)->toBe(TaskStatus::Someday->value);
        expect($deferredTask->title)->toBe('Task to defer');
        expect($deferredTask->description)->toBe('Task description');
        expect($deferredTask->priority)->toBe(2);
    });

    it('defers task with partial ID', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task to defer']);
        $partialId = substr((string) $task['id'], 2, 3);

        Artisan::call('defer', [
            'id' => $partialId,
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Deferred task:');

        // Verify task still exists with status=someday
        $deferredTask = $this->taskService->find($task['id']);
        expect($deferredTask)->not->toBeNull();
        expect($deferredTask->status)->toBe(TaskStatus::Someday->value);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON task']);

        Artisan::call('defer', [
            'id' => $task['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['task_id'])->toBe($task['id']);
        expect($result['title'])->toBe('JSON task');
        expect($result['status'])->toBe(TaskStatus::Someday->value);
    });

    it('returns error when task not found', function (): void {
        $this->artisan('defer', [
            'id' => 'f-nonexistent',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('can defer task that is already someday', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Already deferred']);

        // Defer once
        $this->taskService->defer($task['id']);

        // Defer again - should succeed (idempotent)
        Artisan::call('defer', [
            'id' => $task['id'],
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Deferred task:');

        $deferredTask = $this->taskService->find($task['id']);
        expect($deferredTask)->not->toBeNull();
        expect($deferredTask->status)->toBe(TaskStatus::Someday->value);
    });
});
