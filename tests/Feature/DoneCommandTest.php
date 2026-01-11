<?php

use App\Services\FuelContext;
use App\Services\DatabaseService;
use App\Services\TaskService;
use App\Services\RunService;
use App\Services\BacklogService;

// Done Command Tests
describe('done command', function (): void {
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

    it('marks a task as done', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'To complete']);

        $this->artisan('done', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Completed task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
    });

    it('supports partial ID matching', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Partial ID task']);
        $partialId = substr((string) $task['id'], 2, 3); // Just 3 chars of the hash

        $this->artisan('done', ['ids' => [$partialId], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Completed task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
    });

    it('shows error for non-existent task', function (): void {
        $this->taskService->initialize();

        $this->artisan('done', ['ids' => ['nonexistent'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON done task']);

        $this->artisan('done', ['ids' => [$task['id']], '--cwd' => $this->tempDir, '--json' => true])
            ->expectsOutputToContain('"status": "closed"')
            ->assertExitCode(0);
    });

    it('outputs JSON error for non-existent task with --json flag', function (): void {
        $this->taskService->initialize();

        $this->artisan('done', ['ids' => ['nonexistent'], '--cwd' => $this->tempDir, '--json' => true])
            ->expectsOutputToContain('"error":')
            ->assertExitCode(1);
    });

    it('marks task as done with --reason flag', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task with reason']);

        $this->artisan('done', [
            'ids' => [$task['id']],
            '--reason' => 'Fixed the bug',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain('Reason: Fixed the bug')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
        expect($updated['reason'])->toBe('Fixed the bug');
    });

    it('outputs reason in JSON when --reason flag is used with --json', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON task with reason']);

        Artisan::call('done', [
            'ids' => [$task['id']],
            '--reason' => 'Completed successfully',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['status'])->toBe('closed');
        expect($result['reason'])->toBe('Completed successfully');
    });

    it('does not add reason field when --reason is not provided', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task without reason']);

        $this->artisan('done', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
        expect($updated)->not->toHaveKey('reason');
    });

    it('marks task as done with --commit flag', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task with commit']);
        $commitHash = 'abc123def456';

        $this->artisan('done', [
            'ids' => [$task['id']],
            '--commit' => $commitHash,
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain('Commit: '.$commitHash)
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
        expect($updated['commit_hash'])->toBe($commitHash);
    });

    it('outputs commit hash in JSON when --commit flag is used with --json', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON task with commit']);
        $commitHash = 'xyz789abc123';

        Artisan::call('done', [
            'ids' => [$task['id']],
            '--commit' => $commitHash,
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['status'])->toBe('closed');
        expect($result['commit_hash'])->toBe($commitHash);
    });

    it('does not add commit_hash field when --commit is not provided', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task without commit']);

        $this->artisan('done', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
        expect($updated)->not->toHaveKey('commit_hash');
    });

    it('can use both --reason and --commit flags together', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task with both flags']);
        $commitHash = 'def456ghi789';

        $this->artisan('done', [
            'ids' => [$task['id']],
            '--reason' => 'Fixed the bug',
            '--commit' => $commitHash,
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain('Reason: Fixed the bug')
            ->expectsOutputToContain('Commit: '.$commitHash)
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
        expect($updated['reason'])->toBe('Fixed the bug');
        expect($updated['commit_hash'])->toBe($commitHash);
    });

    it('marks multiple tasks as done', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $task3 = $this->taskService->create(['title' => 'Task 3']);

        $this->artisan('done', [
            'ids' => [$task1['id'], $task2['id'], $task3['id']],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Completed task:')
            ->assertExitCode(0);

        expect($this->taskService->find($task1['id'])['status'])->toBe('closed');
        expect($this->taskService->find($task2['id'])['status'])->toBe('closed');
        expect($this->taskService->find($task3['id'])['status'])->toBe('closed');
    });

    it('outputs multiple tasks as JSON array when multiple IDs provided', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        Artisan::call('done', [
            'ids' => [$task1['id'], $task2['id']],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveCount(2);
        expect($result[0]['status'])->toBe('closed');
        expect($result[1]['status'])->toBe('closed');
        expect(collect($result)->pluck('id')->toArray())->toContain($task1['id'], $task2['id']);
    });

    it('outputs single task as object when one ID provided with --json', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Single task']);

        Artisan::call('done', [
            'ids' => [$task['id']],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveKey('id');
        expect($result['id'])->toBe($task['id']);
        expect($result['status'])->toBe('closed');
    });

    it('handles partial failures when marking multiple tasks', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        $this->artisan('done', [
            'ids' => [$task1['id'], 'nonexistent', $task2['id']],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);

        // Task1 should be closed
        expect($this->taskService->find($task1['id'])['status'])->toBe('closed');
        // Task2 should be closed
        expect($this->taskService->find($task2['id'])['status'])->toBe('closed');
    });

    it('applies same reason to all tasks when --reason provided', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        $this->artisan('done', [
            'ids' => [$task1['id'], $task2['id']],
            '--reason' => 'Batch completion',
            '--cwd' => $this->tempDir,
        ])
            ->assertExitCode(0);

        expect($this->taskService->find($task1['id'])['reason'])->toBe('Batch completion');
        expect($this->taskService->find($task2['id'])['reason'])->toBe('Batch completion');
    });

    it('supports partial IDs when marking multiple tasks', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $partialId1 = substr((string) $task1['id'], 2, 3);
        $partialId2 = substr((string) $task2['id'], 2, 3);

        $this->artisan('done', [
            'ids' => [$partialId1, $partialId2],
            '--cwd' => $this->tempDir,
        ])
            ->assertExitCode(0);

        expect($this->taskService->find($task1['id'])['status'])->toBe('closed');
        expect($this->taskService->find($task2['id'])['status'])->toBe('closed');
    });
});
