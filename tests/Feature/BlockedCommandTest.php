<?php

// Blocked Command Tests
describe('blocked command', function (): void {
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

    it('shows empty when no blocked tasks', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Unblocked task']);

        $this->artisan('blocked', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No blocked tasks.')
            ->assertExitCode(0);
    });

    it('blocked includes tasks with open blockers', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        $this->artisan('blocked', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Blocked task')
            ->doesntExpectOutputToContain('Blocker task')
            ->assertExitCode(0);
    });

    it('blocked excludes tasks when blocker is closed', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        // Close the blocker
        $this->taskService->done($blocker['id']);

        $this->artisan('blocked', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No blocked tasks.')
            ->doesntExpectOutputToContain('Blocked task')
            ->assertExitCode(0);
    });

    it('blocked outputs JSON when --json flag is provided', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        Artisan::call('blocked', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain($blocked['id']);
        expect($output)->toContain('Blocked task');
        expect($output)->not->toContain('Blocker task');
    });

    it('blocked filters by size when --size option is provided', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blockedSmall = $this->taskService->create(['title' => 'Small blocked task', 'size' => 's']);
        $blockedLarge = $this->taskService->create(['title' => 'Large blocked task', 'size' => 'l']);

        // Add dependencies
        $this->taskService->addDependency($blockedSmall['id'], $blocker['id']);
        $this->taskService->addDependency($blockedLarge['id'], $blocker['id']);

        $this->artisan('blocked', ['--cwd' => $this->tempDir, '--size' => 's'])
            ->expectsOutputToContain('Small blocked task')
            ->doesntExpectOutputToContain('Large blocked task')
            ->assertExitCode(0);
    });
});
