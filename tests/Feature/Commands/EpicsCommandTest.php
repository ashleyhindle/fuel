<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);

    // Create FuelContext pointing to test directory
    $context = new FuelContext($this->tempDir.'/.fuel');
    $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

    // Bind our test service instances
    $context->configureDatabase();
    $databaseService = new DatabaseService($context->getDatabasePath());
    $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);
    Artisan::call('migrate', ['--force' => true]);
    $this->app->singleton(TaskService::class, fn (): TaskService => makeTaskService($databaseService));
    $this->app->singleton(EpicService::class, fn (): EpicService => makeEpicService(
        $this->app->make(DatabaseService::class),
        $this->app->make(TaskService::class)
    ));

    $this->databaseService = $this->app->make(DatabaseService::class);
});

afterEach(function (): void {
    // Recursively delete temp directory
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
                unlink($path);
            }
        }

        rmdir($dir);
    };

    $deleteDir($this->tempDir);
});

describe('epics command', function (): void {
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

    it('lists no epics when none exist', function (): void {
        Artisan::call('epics', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('No epics found.');
    });

    it('lists epics in table format', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic1 = $epicService->createEpic('First Epic', 'Description 1');
        $epic2 = $epicService->createEpic('Second Epic', 'Description 2');

        Artisan::call('epics', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Epics (2):');
        expect($output)->toContain('First Epic');
        expect($output)->toContain('Second Epic');
        expect($output)->toContain($epic1->id);
        expect($output)->toContain($epic2->id);
        expect($output)->toContain('planning');
        expect($output)->toContain('Progress');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('JSON Epic', 'JSON Description');

        Artisan::call('epics', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $epics = json_decode($output, true);

        expect($epics)->toBeArray();
        expect($epics)->toHaveCount(1);
        expect($epics[0]['short_id'])->toBe($epic->short_id);
        expect($epics[0]['title'])->toBe('JSON Epic');
        expect($epics[0]['status'])->toBe('planning');
        expect($epics[0])->toHaveKey('task_count');
        expect($epics[0]['task_count'])->toBe(0);
        expect($epics[0])->toHaveKey('completed_count');
        expect($epics[0]['completed_count'])->toBe(0);
    });

    it('shows correct task count for epics with tasks', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Epic with Tasks');
        $task1 = $taskService->create([
            'title' => 'Task 1',
            'epic_id' => $epic->id,
        ]);
        $task2 = $taskService->create([
            'title' => 'Task 2',
            'epic_id' => $epic->id,
        ]);

        Artisan::call('epics', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Epic with Tasks');
        expect($output)->toContain('0/2 complete'); // Progress should show 0/2 complete
    });

    it('shows correct status based on task states', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        // Epic with no tasks - should be planning
        $epic1 = $epicService->createEpic('Planning Epic');

        // Epic with open task - should be in_progress
        $epic2 = $epicService->createEpic('In Progress Epic');
        $taskService->create([
            'title' => 'Open Task',
            'epic_id' => $epic2->id,
            'status' => 'open',
        ]);

        // Epic with all closed tasks - should be review_pending
        $epic3 = $epicService->createEpic('Review Pending Epic');
        $task3 = $taskService->create([
            'title' => 'Closed Task',
            'epic_id' => $epic3->id,
        ]);
        $taskService->update($task3->short_id, ['status' => 'closed']);

        // Check JSON output for reliable status checking
        Artisan::call('epics', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $epics = json_decode($output, true);

        // Find epics by title
        $planningEpic = collect($epics)->firstWhere('title', 'Planning Epic');
        $inProgressEpic = collect($epics)->firstWhere('title', 'In Progress Epic');
        $reviewPendingEpic = collect($epics)->firstWhere('title', 'Review Pending Epic');

        expect($planningEpic['status'])->toBe('planning');
        expect($inProgressEpic['status'])->toBe('in_progress');
        expect($reviewPendingEpic['status'])->toBe('review_pending');
    });

    it('shows correct progress tracking for epics with completed tasks', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Epic with Mixed Tasks');
        $task1 = $taskService->create([
            'title' => 'Open Task',
            'epic_id' => $epic->id,
        ]);
        $task2 = $taskService->create([
            'title' => 'Closed Task',
            'epic_id' => $epic->id,
        ]);
        $task3 = $taskService->create([
            'title' => 'Another Closed Task',
            'epic_id' => $epic->id,
        ]);
        // Update tasks to closed status
        $taskService->update($task2->short_id, ['status' => 'closed']);
        $taskService->update($task3->short_id, ['status' => 'closed']);

        Artisan::call('epics', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Epic with Mixed Tasks');
        expect($output)->toContain('2/3 complete'); // 2 completed out of 3 total

        // Check JSON output
        Artisan::call('epics', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $epics = json_decode($output, true);
        $foundEpic = collect($epics)->firstWhere('title', 'Epic with Mixed Tasks');

        expect($foundEpic['task_count'])->toBe(3);
        expect($foundEpic['completed_count'])->toBe(2);
    });
});
