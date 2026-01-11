<?php

use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;

// Status Command Tests
describe('status command', function (): void {
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

    it('shows zero counts when no tasks exist', function (): void {
        $this->taskService->initialize();

        $this->artisan('status', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Open')
            ->expectsOutputToContain('In Progress')
            ->expectsOutputToContain('Closed')
            ->expectsOutputToContain('Blocked')
            ->expectsOutputToContain('Total')
            ->assertExitCode(0);
    });

    it('counts tasks by status correctly', function (): void {
        $this->taskService->initialize();
        $open1 = $this->taskService->create(['title' => 'Open task 1']);
        $open2 = $this->taskService->create(['title' => 'Open task 2']);
        $inProgress = $this->taskService->create(['title' => 'In progress task']);
        $closed1 = $this->taskService->create(['title' => 'Closed task 1']);
        $closed2 = $this->taskService->create(['title' => 'Closed task 2']);

        $this->taskService->start($inProgress['id']);
        $this->taskService->done($closed1['id']);
        $this->taskService->done($closed2['id']);

        $this->artisan('status', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Open')
            ->expectsOutputToContain('In Progress')
            ->expectsOutputToContain('Closed')
            ->assertExitCode(0);
    });

    it('counts blocked tasks correctly', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked1 = $this->taskService->create(['title' => 'Blocked task 1']);
        $blocked2 = $this->taskService->create(['title' => 'Blocked task 2']);

        // Add dependencies
        $this->taskService->addDependency($blocked1['id'], $blocker['id']);
        $this->taskService->addDependency($blocked2['id'], $blocker['id']);

        $this->artisan('status', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Blocked')
            ->assertExitCode(0);
    });

    it('does not count tasks as blocked when blocker is closed', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add dependency
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        // Close the blocker
        $this->taskService->done($blocker['id']);

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['blocked'])->toBe(0);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Open task']);

        $inProgress = $this->taskService->create(['title' => 'In progress task']);
        $closed = $this->taskService->create(['title' => 'Closed task']);

        $this->taskService->start($inProgress['id']);
        $this->taskService->done($closed['id']);

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveKeys(['open', 'in_progress', 'closed', 'blocked', 'total']);
        expect($result['open'])->toBe(1);
        expect($result['in_progress'])->toBe(1);
        expect($result['closed'])->toBe(1);
        expect($result['blocked'])->toBe(0);
        expect($result['total'])->toBe(3);
    });

    it('shows correct total count', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task 1']);
        $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->create(['title' => 'Task 3']);

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['total'])->toBe(3);
        expect($result['open'])->toBe(3);
    });

    it('handles empty state with JSON output', function (): void {
        $this->taskService->initialize();

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['open'])->toBe(0);
        expect($result['in_progress'])->toBe(0);
        expect($result['closed'])->toBe(0);
        expect($result['blocked'])->toBe(0);
        expect($result['total'])->toBe(0);
    });

    it('counts only open tasks as blocked', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blockedOpen = $this->taskService->create(['title' => 'Blocked open task']);
        $blockedInProgress = $this->taskService->create(['title' => 'Blocked in progress task']);

        // Add dependencies
        $this->taskService->addDependency($blockedOpen['id'], $blocker['id']);
        $this->taskService->addDependency($blockedInProgress['id'], $blocker['id']);

        // Set one to in_progress
        $this->taskService->start($blockedInProgress['id']);

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        // Only open tasks should be counted as blocked
        expect($result['blocked'])->toBe(1);
    });
});
