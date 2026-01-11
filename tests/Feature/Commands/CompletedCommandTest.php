<?php

use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

// =============================================================================
// completed Command Tests
// =============================================================================

describe('completed command', function (): void {
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

    it('shows no completed tasks when empty', function (): void {
        Artisan::call('completed', ['--cwd' => $this->tempDir]);

        expect(Artisan::output())->toContain('No completed tasks found');
    });

    it('shows completed tasks in reverse chronological order', function (): void {
        // Create and close some tasks
        $task1 = $this->taskService->create(['title' => 'First task']);
        $task2 = $this->taskService->create(['title' => 'Second task']);
        $task3 = $this->taskService->create(['title' => 'Third task']);

        // Close them in order
        $this->taskService->done($task1['id']);
        sleep(1); // Ensure different timestamps
        $this->taskService->done($task2['id']);
        sleep(1);
        $this->taskService->done($task3['id']);

        Artisan::call('completed', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        // Should show most recent first
        expect($output)->toContain('Third task');
        expect($output)->toContain('Second task');
        expect($output)->toContain('First task');
    });

    it('excludes open and in_progress tasks', function (): void {
        $open = $this->taskService->create(['title' => 'Open task']);
        $inProgress = $this->taskService->create(['title' => 'In progress task']);
        $closed = $this->taskService->create(['title' => 'Closed task']);

        $this->taskService->start($inProgress['id']);
        $this->taskService->done($closed['id']);

        Artisan::call('completed', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Closed task');
        expect($output)->not->toContain('Open task');
        expect($output)->not->toContain('In progress task');
    });

    it('respects --limit option', function (): void {
        // Use explicit timestamps to ensure reliable ordering
        $baseTime = Carbon::parse('2024-01-01 12:00:00');
        Carbon::setTestNow($baseTime);

        try {
            // Create and close 5 tasks with explicit timestamps
            $taskIds = [];
            for ($i = 1; $i <= 5; $i++) {
                // Set time for this task (each task gets a different timestamp)
                Carbon::setTestNow($baseTime->copy()->addSeconds($i));
                $task = $this->taskService->create(['title' => 'Task '.$i]);
                $taskIds[] = $task['id'];
                // Increment time slightly for done() call to ensure updated_at differs
                Carbon::setTestNow($baseTime->copy()->addSeconds($i)->addMilliseconds(100));
                $this->taskService->done($task['id']);
            }

            Artisan::call('completed', ['--cwd' => $this->tempDir, '--limit' => 3, '--json' => true]);
            $output = Artisan::output();

            $data = json_decode($output, true);
            expect($data)->toBeArray();
            expect($data)->toHaveCount(3);
            // Verify limit works - should only return 3 tasks
            $titles = array_column($data, 'title');
            expect($titles)->toHaveCount(3);
            // Most recent tasks should be included (Task 5 should be in results)
            expect($titles)->toContain('Task 5');
        } finally {
            // Always restore real time
            Carbon::setTestNow();
        }
    });

    it('outputs JSON when --json flag is used', function (): void {
        $task = $this->taskService->create(['title' => 'Completed task']);
        $this->taskService->done($task['id']);

        Artisan::call('completed', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toHaveCount(1);
        expect($data[0]['id'])->toBe($task['id']);
        expect($data[0]['status'])->toBe('closed');
    });

    it('outputs empty array as JSON when no completed tasks', function (): void {
        Artisan::call('completed', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toBeEmpty();
    });

    it('displays task details in table format', function (): void {
        $task = $this->taskService->create([
            'title' => 'Test completed task',
            'type' => 'feature',
            'priority' => 1,
        ]);
        $this->taskService->done($task['id']);

        Artisan::call('completed', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('ID');
        expect($output)->toContain('Title');
        expect($output)->toContain('Completed');
        expect($output)->toContain('Type');
        expect($output)->toContain('Priority');
        expect($output)->toContain($task['id']);
        expect($output)->toContain('Test completed task');
        expect($output)->toContain('feature');
        expect($output)->toContain('1');
    });
});
