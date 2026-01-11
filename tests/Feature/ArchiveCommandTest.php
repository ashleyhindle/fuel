<?php

use App\Services\FuelContext;
use App\Services\DatabaseService;
use App\Services\TaskService;
use App\Services\RunService;
use App\Services\BacklogService;
use Illuminate\Support\Facades\Artisan;

describe('archive command', function (): void {
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

    it('archives closed tasks older than specified days', function (): void {
        $this->taskService->initialize();

        // Create a closed task from 35 days ago
        $oldTask = $this->taskService->create(['title' => 'Old closed task']);
        $this->taskService->done($oldTask['id']);
        $oldDate = now()->subDays(35)->toIso8601String();
        $this->taskService->update($oldTask['id'], ['updated_at' => $oldDate]);

        // Create a closed task from 20 days ago (should not be archived)
        $recentTask = $this->taskService->create(['title' => 'Recent closed task']);
        $this->taskService->done($recentTask['id']);
        $recentDate = now()->subDays(20)->toIso8601String();
        $this->taskService->update($recentTask['id'], ['updated_at' => $recentDate]);

        Artisan::call('archive', ['--days' => 30, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Archived 1 task(s)');

        // Verify old task is archived
        expect($this->taskService->find($oldTask['id']))->toBeNull();

        // Verify recent task is still present
        expect($this->taskService->find($recentTask['id']))->not->toBeNull();
    });

    it('archives all closed tasks when --all flag is used', function (): void {
        $this->taskService->initialize();

        // Create closed tasks with different ages
        $task1 = $this->taskService->create(['title' => 'Closed task 1']);
        $this->taskService->done($task1['id']);

        $task2 = $this->taskService->create(['title' => 'Closed task 2']);
        $this->taskService->done($task2['id']);

        // Create an open task (should not be archived)
        $openTask = $this->taskService->create(['title' => 'Open task']);

        Artisan::call('archive', ['--all' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Archived 2 task(s)');

        // Verify closed tasks are archived
        expect($this->taskService->find($task1['id']))->toBeNull();
        expect($this->taskService->find($task2['id']))->toBeNull();

        // Verify open task remains
        expect($this->taskService->find($openTask['id']))->not->toBeNull();
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();

        $task = $this->taskService->create(['title' => 'Closed task']);
        $this->taskService->done($task['id']);
        $oldDate = now()->subDays(35)->toIso8601String();
        $this->taskService->update($task['id'], ['updated_at' => $oldDate]);

        Artisan::call('archive', ['--days' => 30, '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toHaveKeys(['archived', 'archived_tasks']);
        expect($result['archived'])->toBe(1);
        expect($result['archived_tasks'])->toHaveCount(1);
        expect($result['archived_tasks'][0]['id'])->toBe($task['id']);
    });

    it('shows message when no tasks to archive', function (): void {
        $this->taskService->initialize();

        // Create only open tasks
        $this->taskService->create(['title' => 'Open task']);

        Artisan::call('archive', ['--days' => 30, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('No tasks to archive');
    });

    it('validates days option must be positive integer', function (): void {
        $this->taskService->initialize();

        Artisan::call('archive', ['--days' => 0, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Days must be a positive integer');
    });

    it('uses default 30 days when --days not specified', function (): void {
        $this->taskService->initialize();

        // Create a closed task from 35 days ago
        $oldTask = $this->taskService->create(['title' => 'Old closed task']);
        $this->taskService->done($oldTask['id']);
        $oldDate = now()->subDays(35)->toIso8601String();
        $this->taskService->update($oldTask['id'], ['updated_at' => $oldDate]);

        Artisan::call('archive', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Archived 1 task(s)');
    });
});
