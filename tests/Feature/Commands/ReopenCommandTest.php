<?php

use App\Enums\TaskStatus;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

describe('reopen command', function (): void {
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

    it('reopens a done task', function (): void {
        $task = $this->taskService->create(['title' => 'To reopen']);
        $this->taskService->done($task->short_id);

        $this->artisan('reopen', ['ids' => [$task->short_id]])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Open);
    });

    it('supports partial ID matching', function (): void {
        $task = $this->taskService->create(['title' => 'Partial ID task']);
        $this->taskService->done($task->short_id);
        $partialId = substr((string) $task->short_id, 2, 3); // Just 3 chars of the hash

        $this->artisan('reopen', ['ids' => [$partialId]])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Open);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $task = $this->taskService->create(['title' => 'JSON reopen task']);
        $this->taskService->done($task->short_id);

        Artisan::call('reopen', [
            'ids' => [$task->short_id],
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['short_id'])->toBe($task->short_id);
        expect($result['status'])->toBe('open');
        expect($result['title'])->toBe('JSON reopen task');
    });

    it('removes reason when reopening a task', function (): void {
        $task = $this->taskService->create(['title' => 'Task with reason']);
        $this->taskService->done($task->short_id, 'Fixed the bug');

        $closedTask = $this->taskService->find($task->short_id);
        expect($closedTask->reason)->toBe('Fixed the bug');

        $this->artisan('reopen', ['ids' => [$task->short_id]])
            ->assertExitCode(0);

        $reopenedTask = $this->taskService->find($task->short_id);
        expect($reopenedTask->status)->toBe(TaskStatus::Open);
        expect($reopenedTask->reason)->toBeNull();
    });

    it('clears consumed fields when reopening a task', function (): void {
        $task = $this->taskService->create(['title' => 'Task with consumed fields']);
        $this->taskService->done($task->short_id);

        // Manually add consumed fields (simulating a consumed task)
        $this->taskService->update($task->short_id, [
            'consumed' => true,
            'consumed_at' => '2026-01-07T10:00:00+00:00',
            'consumed_exit_code' => 1,
            'consumed_output' => 'Some error output',
        ]);

        $closedTask = $this->taskService->find($task->short_id);
        expect($closedTask->consumed)->toBeTrue();
        expect($closedTask->consumed_at)->toBe('2026-01-07T10:00:00+00:00');
        expect($closedTask->consumed_exit_code)->toBe(1);
        expect($closedTask->consumed_output)->toBe('Some error output');

        $this->artisan('reopen', ['ids' => [$task->short_id]])
            ->assertExitCode(0);

        $reopenedTask = $this->taskService->find($task->short_id);
        expect($reopenedTask->status)->toBe(TaskStatus::Open);
        expect($reopenedTask->consumed)->toBe(false);
        expect($reopenedTask->consumed_at)->toBeNull();
        expect($reopenedTask->consumed_exit_code)->toBeNull();
        expect($reopenedTask->consumed_output)->toBeNull();
    });

    it('fails when task is not found', function (): void {

        $this->artisan('reopen', ['ids' => ['nonexistent']])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('fails when task is open', function (): void {
        $task = $this->taskService->create(['title' => 'Open task']);

        $this->artisan('reopen', ['ids' => [$task->short_id]])
            ->expectsOutputToContain('is not done, in_progress, or review')
            ->assertExitCode(1);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Open);
    });

    it('reopens an in_progress task', function (): void {
        $task = $this->taskService->create(['title' => 'In progress task']);
        $this->taskService->start($task->short_id);

        $this->artisan('reopen', ['ids' => [$task->short_id]])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Open);
    });

    it('reopens multiple tasks', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $task3 = $this->taskService->create(['title' => 'Task 3']);
        $this->taskService->done($task1->short_id);
        $this->taskService->done($task2->short_id);
        $this->taskService->done($task3->short_id);

        $this->artisan('reopen', [
            'ids' => [$task1->short_id, $task2->short_id, $task3->short_id],
        ])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        expect($this->taskService->find($task1->short_id)->status)->toBe(TaskStatus::Open);
        expect($this->taskService->find($task2->short_id)->status)->toBe(TaskStatus::Open);
        expect($this->taskService->find($task3->short_id)->status)->toBe(TaskStatus::Open);
    });

    it('outputs multiple tasks as JSON array when multiple IDs provided', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->done($task1->short_id);
        $this->taskService->done($task2->short_id);

        Artisan::call('reopen', [
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

    it('handles partial failures when reopening multiple tasks', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->done($task1->short_id);
        $this->taskService->done($task2->short_id);

        $this->artisan('reopen', [
            'ids' => [$task1->short_id, 'nonexistent', $task2->short_id],
        ])
            ->expectsOutputToContain('Reopened task:')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);

        // Task1 should be reopened
        expect($this->taskService->find($task1->short_id)->status)->toBe(TaskStatus::Open);
        // Task2 should be reopened
        expect($this->taskService->find($task2->short_id)->status)->toBe(TaskStatus::Open);
    });

    it('supports partial IDs when reopening multiple tasks', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->done($task1->short_id);
        $this->taskService->done($task2->short_id);

        $partialId1 = substr((string) $task1->short_id, 2, 3);
        $partialId2 = substr((string) $task2->short_id, 2, 3);

        $this->artisan('reopen', [
            'ids' => [$partialId1, $partialId2],
        ])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        expect($this->taskService->find($task1->short_id)->status)->toBe(TaskStatus::Open);
        expect($this->taskService->find($task2->short_id)->status)->toBe(TaskStatus::Open);
    });
});
