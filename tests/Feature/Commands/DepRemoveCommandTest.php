<?php

use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;

// =============================================================================
// dep:remove Command Tests
// =============================================================================
describe('dep:remove command', function (): void {
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

    it('removes dependency via CLI', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // First add a dependency
        $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

        // Then remove it via CLI
        $this->artisan('dep:remove', [
            'from' => $blocked->short_id,
            'to' => $blocker->short_id,
        ])
            ->expectsOutputToContain('Removed dependency')
            ->assertExitCode(0);

        // Verify blocker was removed from blocked_by array
        $updated = $this->taskService->find($blocked->short_id);
        expect($updated->blocked_by ?? [])->toBeEmpty();
    });

    it('dep:remove outputs JSON when --json flag is used', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // First add a dependency
        $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

        Artisan::call('dep:remove', [
            'from' => $blocked->short_id,
            'to' => $blocker->short_id,
            '--json' => true,
        ]);

        $output = Artisan::output();
        expect($output)->toContain($blocked->short_id);
        expect($output)->toContain('blocked_by');
    });

    it('dep:remove shows error for non-existent task', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);

        $this->artisan('dep:remove', [
            'from' => 'nonexistent',
            'to' => $blocker->short_id,
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('dep:remove shows error when no dependency exists', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        // Try to remove a dependency that doesn't exist
        $this->artisan('dep:remove', [
            'from' => $task1->short_id,
            'to' => $task2->short_id,
        ])
            ->expectsOutputToContain('No dependency exists')
            ->assertExitCode(1);
    });

    it('dep:remove supports partial ID matching', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // First add a dependency
        $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

        // Use partial IDs (just the hash part)
        $blockerPartial = substr((string) $blocker->short_id, 2, 3);
        $blockedPartial = substr((string) $blocked->short_id, 2, 3);

        $this->artisan('dep:remove', [
            'from' => $blockedPartial,
            'to' => $blockerPartial,
        ])
            ->expectsOutputToContain('Removed dependency')
            ->assertExitCode(0);

        // Verify blocker was removed from blocked_by array using full ID
        $updated = $this->taskService->find($blocked->short_id);
        expect($updated->blocked_by ?? [])->toBeEmpty();
    });
});
