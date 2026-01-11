<?php

use Illuminate\Support\Facades\Artisan;

uses()->group('commands');

describe('update command', function (): void {
    beforeEach(function (): void {
        $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($this->tempDir.'/.fuel', 0755, true);

        $context = new App\Services\FuelContext($this->tempDir.'/.fuel');
        $this->app->singleton(App\Services\FuelContext::class, fn () => $context);

        $this->dbPath = $context->getDatabasePath();

        $databaseService = new App\Services\DatabaseService($context->getDatabasePath());
        $this->app->singleton(App\Services\DatabaseService::class, fn () => $databaseService);

        $this->app->singleton(App\Services\TaskService::class, fn (): App\Services\TaskService => new App\Services\TaskService($databaseService));

        $this->app->singleton(App\Services\RunService::class, fn (): App\Services\RunService => new App\Services\RunService($databaseService));

        $this->app->singleton(App\Services\BacklogService::class, fn (): App\Services\BacklogService => new App\Services\BacklogService($context));

        $this->taskService = $this->app->make(App\Services\TaskService::class);
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
                } else {
                    if (file_exists($path)) {
                        unlink($path);
                    }
                }
            }

            rmdir($dir);
        };

        $deleteDir($this->tempDir);
    });

    it('updates task title', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Original title']);

        Artisan::call('update', [
            'id' => $task['id'],
            '--title' => 'Updated title',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['title'])->toBe('Updated title');
        expect($updated['id'])->toBe($task['id']);
    });

    it('updates task description', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        Artisan::call('update', [
            'id' => $task['id'],
            '--description' => 'New description',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['description'])->toBe('New description');
    });

    it('clears task description when empty string provided', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'description' => 'Old description']);

        Artisan::call('update', [
            'id' => $task['id'],
            '--description' => '',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['description'])->toBeNull();
    });

    it('updates task type', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'type' => 'task']);

        Artisan::call('update', [
            'id' => $task['id'],
            '--type' => 'bug',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['type'])->toBe('bug');
    });

    it('validates task type enum', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        $this->artisan('update', [
            'id' => $task['id'],
            '--type' => 'invalid-type',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid task type')
            ->assertExitCode(1);
    });

    it('updates task priority', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'priority' => 2]);

        Artisan::call('update', [
            'id' => $task['id'],
            '--priority' => '4',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['priority'])->toBe(4);
    });

    it('validates priority range', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        $this->artisan('update', [
            'id' => $task['id'],
            '--priority' => '5',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid priority')
            ->assertExitCode(1);
    });

    it('updates task status', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        Artisan::call('update', [
            'id' => $task['id'],
            '--status' => 'closed',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['status'])->toBe('closed');
    });

    it('adds labels with --add-labels flag', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'labels' => ['existing']]);

        Artisan::call('update', [
            'id' => $task['id'],
            '--add-labels' => 'new1,new2',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['labels'])->toContain('existing', 'new1', 'new2');
    });

    it('removes labels with --remove-labels flag', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'labels' => ['keep', 'remove1', 'remove2']]);

        Artisan::call('update', [
            'id' => $task['id'],
            '--remove-labels' => 'remove1,remove2',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['labels'])->toBe(['keep']);
    });

    it('adds and removes labels in same update', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'labels' => ['old1', 'old2']]);

        Artisan::call('update', [
            'id' => $task['id'],
            '--add-labels' => 'new1',
            '--remove-labels' => 'old1',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['labels'])->toContain('old2', 'new1');
        expect($updated['labels'])->not->toContain('old1');
    });

    it('updates multiple fields at once', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Original', 'type' => 'task', 'priority' => 2]);

        Artisan::call('update', [
            'id' => $task['id'],
            '--title' => 'Updated',
            '--type' => 'feature',
            '--priority' => '3',
            '--description' => 'New description',
            '--size' => 'l',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['title'])->toBe('Updated');
        expect($updated['type'])->toBe('feature');
        expect($updated['priority'])->toBe(3);
        expect($updated['description'])->toBe('New description');
        expect($updated['size'])->toBe('l');
    });

    it('updates task size', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'size' => 'm']);

        Artisan::call('update', [
            'id' => $task['id'],
            '--size' => 'xl',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['size'])->toBe('xl');
    });

    it('validates task size enum in update', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        $this->artisan('update', [
            'id' => $task['id'],
            '--size' => 'invalid-size',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid task size')
            ->assertExitCode(1);
    });

    it('shows error when no update fields provided', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        $this->artisan('update', [
            'id' => $task['id'],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('No update fields provided')
            ->assertExitCode(1);
    });

    it('shows error for non-existent task', function (): void {
        $this->taskService->initialize();

        $this->artisan('update', [
            'id' => 'nonexistent',
            '--title' => 'New title',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('supports partial ID matching', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);
        $partialId = substr((string) $task['id'], 2, 3);

        Artisan::call('update', [
            'id' => $partialId,
            '--title' => 'Updated',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['title'])->toBe('Updated');
        expect($updated['id'])->toBe($task['id']);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        Artisan::call('update', [
            'id' => $task['id'],
            '--title' => 'Updated',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated)->toHaveKey('id');
        expect($updated)->toHaveKey('title');
        expect($updated['title'])->toBe('Updated');
    });
});
