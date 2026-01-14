<?php

use App\Enums\TaskStatus;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

describe('retry command', function (): void {
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
        $this->runService = $this->app->make(RunService::class);
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

    it('retries a stuck task', function (): void {
        $task = $this->taskService->create(['title' => 'Stuck task']);
        $this->taskService->start($task->short_id);

        // Mark task as consumed with non-zero exit code
        $this->taskService->update($task->short_id, [
            'consumed' => true,
            'consumed_at' => '2026-01-07T10:00:00+00:00',
            'consumed_output' => 'Some error output',
        ]);
        $this->runService->logRun($task->short_id, ['agent' => 'test', 'exit_code' => 1]);

        $this->artisan('retry', ['ids' => [$task->short_id]])
            ->expectsOutputToContain('Retried task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Open);
        expect($updated->consumed)->toBe(false);
        expect($updated->consumed_at)->toBeNull();
        expect($updated->consumed_output)->toBeNull();
    });

    it('supports partial ID matching', function (): void {
        $task = $this->taskService->create(['title' => 'Partial ID stuck task']);
        $this->taskService->start($task->short_id);

        $this->taskService->update($task->short_id, [
            'consumed' => true,
        ]);
        $this->runService->logRun($task->short_id, ['agent' => 'test', 'exit_code' => 1]);

        $partialId = substr((string) $task->short_id, 2, 3); // Just 3 chars of the hash

        $this->artisan('retry', ['ids' => [$partialId]])
            ->expectsOutputToContain('Retried task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Open);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $task = $this->taskService->create(['title' => 'JSON retry task']);
        $this->taskService->start($task->short_id);

        $this->taskService->update($task->short_id, [
            'consumed' => true,
        ]);
        $this->runService->logRun($task->short_id, ['agent' => 'test', 'exit_code' => 1]);

        Artisan::call('retry', [
            'ids' => [$task->short_id],
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['short_id'])->toBe($task->short_id);
        expect($result['status'])->toBe('open');
        expect($result['title'])->toBe('JSON retry task');
    });

    it('clears consumed fields when retrying a task', function (): void {
        $task = $this->taskService->create(['title' => 'Task with consumed fields']);
        $this->taskService->start($task->short_id);

        $this->taskService->update($task->short_id, [
            'consumed' => true,
            'consumed_at' => '2026-01-07T10:00:00+00:00',
            'consumed_output' => 'Some error output',
        ]);
        $this->runService->logRun($task->short_id, ['agent' => 'test', 'exit_code' => 1]);

        $stuckTask = $this->taskService->find($task->short_id);
        expect($stuckTask->consumed)->toBeTrue();
        expect($stuckTask->consumed_at)->toBe('2026-01-07T10:00:00+00:00');
        expect($stuckTask['consumed_output'])->toBe('Some error output');

        $this->artisan('retry', ['ids' => [$task->short_id]])
            ->assertExitCode(0);

        $retriedTask = $this->taskService->find($task->short_id);
        expect($retriedTask->status)->toBe(TaskStatus::Open);
        expect($retriedTask->consumed)->toBe(false);
        expect($retriedTask->consumed_at)->toBeNull();
        expect($retriedTask->consumed_output)->toBeNull();
    });

    it('fails when task is not found', function (): void {

        $this->artisan('retry', ['ids' => ['nonexistent']])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('fails when task is not consumed', function (): void {
        $task = $this->taskService->create(['title' => 'Not consumed task']);
        $this->taskService->start($task->short_id);

        $this->artisan('retry', ['ids' => [$task->short_id]])
            ->expectsOutputToContain('is not a consumed in_progress task')
            ->assertExitCode(1);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::InProgress);
    });

    it('retries task with zero exit code if still in_progress', function (): void {
        $task = $this->taskService->create(['title' => 'Task with zero exit code']);
        $this->taskService->start($task->short_id);

        // Agent exited cleanly but task still in_progress = something went wrong
        $this->taskService->update($task->short_id, [
            'consumed' => true,
        ]);
        $this->runService->logRun($task->short_id, ['agent' => 'test', 'exit_code' => 0]);

        $this->artisan('retry', ['ids' => [$task->short_id]])
            ->expectsOutputToContain('Retried task:')
            ->assertExitCode(0);
    });

    it('retries multiple stuck tasks', function (): void {
        $task1 = $this->taskService->create(['title' => 'Stuck task 1']);
        $task2 = $this->taskService->create(['title' => 'Stuck task 2']);
        $task3 = $this->taskService->create(['title' => 'Stuck task 3']);

        $this->taskService->start($task1->short_id);
        $this->taskService->start($task2->short_id);
        $this->taskService->start($task3->short_id);

        $this->taskService->update($task1->short_id, ['consumed' => true]);
        $this->taskService->update($task2->short_id, ['consumed' => true]);
        $this->taskService->update($task3->short_id, ['consumed' => true]);
        $this->runService->logRun($task1->short_id, ['agent' => 'test', 'exit_code' => 1]);
        $this->runService->logRun($task2->short_id, ['agent' => 'test', 'exit_code' => 2]);
        $this->runService->logRun($task3->short_id, ['agent' => 'test', 'exit_code' => 3]);

        $this->artisan('retry', [
            'ids' => [$task1->short_id, $task2->short_id, $task3->short_id],
        ])
            ->expectsOutputToContain('Retried task:')
            ->assertExitCode(0);

        expect($this->taskService->find($task1->short_id)->status)->toBe(TaskStatus::Open);
        expect($this->taskService->find($task2->short_id)->status)->toBe(TaskStatus::Open);
        expect($this->taskService->find($task3->short_id)->status)->toBe(TaskStatus::Open);
    });

    it('outputs multiple tasks as JSON array when multiple IDs provided', function (): void {
        $task1 = $this->taskService->create(['title' => 'Stuck task 1']);
        $task2 = $this->taskService->create(['title' => 'Stuck task 2']);

        $this->taskService->start($task1->short_id);
        $this->taskService->start($task2->short_id);

        $this->taskService->update($task1->short_id, ['consumed' => true]);
        $this->taskService->update($task2->short_id, ['consumed' => true]);
        $this->runService->logRun($task1->short_id, ['agent' => 'test', 'exit_code' => 1]);
        $this->runService->logRun($task2->short_id, ['agent' => 'test', 'exit_code' => 1]);

        Artisan::call('retry', [
            'ids' => [$task1->short_id, $task2->short_id],
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveCount(2);
        expect($result[0]['status'])->toBe('open');
        expect($result[1]['status'])->toBe('open');
        expect(collect($result)->pluck('short_id')->toArray())->toContain($task1->short_id, $task2->short_id);
    });

    it('handles partial failures when retrying multiple tasks', function (): void {
        $task1 = $this->taskService->create(['title' => 'Stuck task 1']);
        $task2 = $this->taskService->create(['title' => 'Stuck task 2']);

        $this->taskService->start($task1->short_id);
        $this->taskService->start($task2->short_id);

        $this->taskService->update($task1->short_id, ['consumed' => true]);
        $this->taskService->update($task2->short_id, ['consumed' => true]);
        $this->runService->logRun($task1->short_id, ['agent' => 'test', 'exit_code' => 1]);
        $this->runService->logRun($task2->short_id, ['agent' => 'test', 'exit_code' => 1]);

        $this->artisan('retry', [
            'ids' => [$task1->short_id, 'nonexistent', $task2->short_id],
        ])
            ->expectsOutputToContain('Retried task:')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);

        // Task1 should be retried
        expect($this->taskService->find($task1->short_id)->status)->toBe(TaskStatus::Open);
        // Task2 should be retried
        expect($this->taskService->find($task2->short_id)->status)->toBe(TaskStatus::Open);
    });
});
