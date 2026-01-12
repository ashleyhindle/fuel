<?php

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

        $databaseService = new DatabaseService($context->getDatabasePath());
        $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);

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

    it('retries a stuck task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Stuck task']);
        $this->taskService->start($task['short_id']);

        // Mark task as consumed with non-zero exit code
        $this->taskService->update($task['short_id'], [
            'consumed' => true,
            'consumed_at' => '2026-01-07T10:00:00+00:00',
            'consumed_exit_code' => 1,
            'consumed_output' => 'Some error output',
        ]);

        $this->artisan('retry', ['ids' => [$task['short_id']], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Retried task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['short_id']);
        expect($updated['status'])->toBe('open');
        expect($updated)->not->toHaveKey('consumed');
        expect($updated)->not->toHaveKey('consumed_at');
        expect($updated)->not->toHaveKey('consumed_exit_code');
        expect($updated)->not->toHaveKey('consumed_output');
    });

    it('supports partial ID matching', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Partial ID stuck task']);
        $this->taskService->start($task['short_id']);

        $this->taskService->update($task['short_id'], [
            'consumed' => true,
            'consumed_exit_code' => 1,
        ]);

        $partialId = substr((string) $task['short_id'], 2, 3); // Just 3 chars of the hash

        $this->artisan('retry', ['ids' => [$partialId], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Retried task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['short_id']);
        expect($updated['status'])->toBe('open');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON retry task']);
        $this->taskService->start($task['short_id']);

        $this->taskService->update($task['short_id'], [
            'consumed' => true,
            'consumed_exit_code' => 1,
        ]);

        Artisan::call('retry', [
            'ids' => [$task['short_id']],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['short_id'])->toBe($task['short_id']);
        expect($result['status'])->toBe('open');
        expect($result['title'])->toBe('JSON retry task');
    });

    it('clears consumed fields when retrying a task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task with consumed fields']);
        $this->taskService->start($task['short_id']);

        $this->taskService->update($task['short_id'], [
            'consumed' => true,
            'consumed_at' => '2026-01-07T10:00:00+00:00',
            'consumed_exit_code' => 1,
            'consumed_output' => 'Some error output',
        ]);

        $stuckTask = $this->taskService->find($task['short_id']);
        expect($stuckTask['consumed'])->toBeTrue();
        expect($stuckTask['consumed_at'])->toBe('2026-01-07T10:00:00+00:00');
        expect($stuckTask['consumed_exit_code'])->toBe(1);
        expect($stuckTask['consumed_output'])->toBe('Some error output');

        $this->artisan('retry', ['ids' => [$task['short_id']], '--cwd' => $this->tempDir])
            ->assertExitCode(0);

        $retriedTask = $this->taskService->find($task['short_id']);
        expect($retriedTask['status'])->toBe('open');
        expect($retriedTask)->not->toHaveKey('consumed');
        expect($retriedTask)->not->toHaveKey('consumed_at');
        expect($retriedTask)->not->toHaveKey('consumed_exit_code');
        expect($retriedTask)->not->toHaveKey('consumed_output');
    });

    it('fails when task is not found', function (): void {
        $this->taskService->initialize();

        $this->artisan('retry', ['ids' => ['nonexistent'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('fails when task is not consumed', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Not consumed task']);
        $this->taskService->start($task['short_id']);

        $this->artisan('retry', ['ids' => [$task['short_id']], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('is not a consumed in_progress task')
            ->assertExitCode(1);

        $updated = $this->taskService->find($task['short_id']);
        expect($updated['status'])->toBe('in_progress');
    });

    it('retries task with zero exit code if still in_progress', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task with zero exit code']);
        $this->taskService->start($task['short_id']);

        // Agent exited cleanly but task still in_progress = something went wrong
        $this->taskService->update($task['short_id'], [
            'consumed' => true,
            'consumed_exit_code' => 0,
        ]);

        $this->artisan('retry', ['ids' => [$task['short_id']], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Retried task:')
            ->assertExitCode(0);
    });

    it('retries multiple stuck tasks', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Stuck task 1']);
        $task2 = $this->taskService->create(['title' => 'Stuck task 2']);
        $task3 = $this->taskService->create(['title' => 'Stuck task 3']);

        $this->taskService->start($task1['short_id']);
        $this->taskService->start($task2['short_id']);
        $this->taskService->start($task3['short_id']);

        $this->taskService->update($task1['short_id'], ['consumed' => true, 'consumed_exit_code' => 1]);
        $this->taskService->update($task2['short_id'], ['consumed' => true, 'consumed_exit_code' => 2]);
        $this->taskService->update($task3['short_id'], ['consumed' => true, 'consumed_exit_code' => 3]);

        $this->artisan('retry', [
            'ids' => [$task1['short_id'], $task2['short_id'], $task3['short_id']],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Retried task:')
            ->assertExitCode(0);

        expect($this->taskService->find($task1['short_id'])['status'])->toBe('open');
        expect($this->taskService->find($task2['short_id'])['status'])->toBe('open');
        expect($this->taskService->find($task3['short_id'])['status'])->toBe('open');
    });

    it('outputs multiple tasks as JSON array when multiple IDs provided', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Stuck task 1']);
        $task2 = $this->taskService->create(['title' => 'Stuck task 2']);

        $this->taskService->start($task1['short_id']);
        $this->taskService->start($task2['short_id']);

        $this->taskService->update($task1['short_id'], ['consumed' => true, 'consumed_exit_code' => 1]);
        $this->taskService->update($task2['short_id'], ['consumed' => true, 'consumed_exit_code' => 1]);

        Artisan::call('retry', [
            'ids' => [$task1['short_id'], $task2['short_id']],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveCount(2);
        expect($result[0]['status'])->toBe('open');
        expect($result[1]['status'])->toBe('open');
        expect(collect($result)->pluck('short_id')->toArray())->toContain($task1['short_id'], $task2['short_id']);
    });

    it('handles partial failures when retrying multiple tasks', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Stuck task 1']);
        $task2 = $this->taskService->create(['title' => 'Stuck task 2']);

        $this->taskService->start($task1['short_id']);
        $this->taskService->start($task2['short_id']);

        $this->taskService->update($task1['short_id'], ['consumed' => true, 'consumed_exit_code' => 1]);
        $this->taskService->update($task2['short_id'], ['consumed' => true, 'consumed_exit_code' => 1]);

        $this->artisan('retry', [
            'ids' => [$task1['short_id'], 'nonexistent', $task2['short_id']],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Retried task:')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);

        // Task1 should be retried
        expect($this->taskService->find($task1['short_id'])['status'])->toBe('open');
        // Task2 should be retried
        expect($this->taskService->find($task2['short_id'])['status'])->toBe('open');
    });
});
