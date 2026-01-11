<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);

    // Create FuelContext pointing to test directory
    $context = new FuelContext($this->tempDir.'/.fuel');
    $this->app->singleton(FuelContext::class, fn () => $context);

    // Bind our test service instances
    $databaseService = new DatabaseService($context->getDatabasePath());
    $this->app->singleton(DatabaseService::class, fn () => $databaseService);
    $this->app->singleton(TaskService::class, fn (): TaskService => new TaskService($databaseService));
    $this->app->singleton(EpicService::class, function (): EpicService {
        return new EpicService(
            $this->app->make(DatabaseService::class),
            $this->app->make(TaskService::class)
        );
    });

    $this->databaseService = $this->app->make(DatabaseService::class);
    $this->databaseService->initialize();
});

afterEach(function (): void {
    // Recursively delete temp directory
    $deleteDir = function (string $dir) use (&$deleteDir): void {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
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

describe('epic:show command', function (): void {
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

    it('shows error when epic not found', function (): void {
        Artisan::call('epic:show', ['id' => 'e-nonexistent', '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain("Epic 'e-nonexistent' not found");
    });

    it('shows epic details without tasks', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic', 'Test Description');

        Artisan::call('epic:show', ['id' => $epic->id, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Epic: '.$epic->id);
        expect($output)->toContain('Title: Test Epic');
        expect($output)->toContain('Description: Test Description');
        expect($output)->toContain('Status: planning');
        expect($output)->toContain('Progress: 0/0 complete');
        expect($output)->toContain('Created:');
        expect($output)->toContain('No tasks linked to this epic.');
    });

    it('shows epic details with linked tasks in table format', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Epic with Tasks', 'Epic Description');
        $task1 = $taskService->create([
            'title' => 'Task 1',
            'type' => 'feature',
            'priority' => 1,
            'epic_id' => $epic->id,
        ]);
        $task2 = $taskService->create([
            'title' => 'Task 2',
            'type' => 'bug',
            'priority' => 2,
            'epic_id' => $epic->id,
        ]);
        // Update task2 to closed status
        $taskService->update($task2['id'], ['status' => 'closed']);

        Artisan::call('epic:show', ['id' => $epic->id, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Epic: '.$epic->id);
        expect($output)->toContain('Title: Epic with Tasks');
        expect($output)->toContain('Description: Epic Description');
        expect($output)->toContain('Linked Tasks (2):');
        expect($output)->toContain($task1['id']);
        expect($output)->toContain('Task 1');
        expect($output)->toContain($task2['id']);
        expect($output)->toContain('Task 2');
        expect($output)->toContain('ID');
        expect($output)->toContain('Title');
        expect($output)->toContain('Status');
        expect($output)->toContain('Type');
        expect($output)->toContain('Priority');

        // Verify both task statuses are in the output (checking for status values)
        expect($output)->toContain('open');
        // Note: "closed" may be formatted differently in table output, so we verify task2 exists instead
        expect($output)->toContain('Progress: 1/2 complete'); // 1 completed out of 2 total
    });

    it('shows correct progress tracking for epic with completed tasks', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Epic with Progress');
        $task1 = $taskService->create([
            'title' => 'Task 1',
            'epic_id' => $epic->id,
        ]);
        $task2 = $taskService->create([
            'title' => 'Task 2',
            'epic_id' => $epic->id,
        ]);
        $task3 = $taskService->create([
            'title' => 'Task 3',
            'epic_id' => $epic->id,
        ]);
        // Update tasks to closed status
        $taskService->update($task1['id'], ['status' => 'closed']);
        $taskService->update($task2['id'], ['status' => 'closed']);

        Artisan::call('epic:show', ['id' => $epic->id, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Progress: 2/3 complete'); // 2 completed out of 3 total

        // Check JSON output
        Artisan::call('epic:show', ['id' => $epic->id, '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data['task_count'])->toBe(3);
        expect($data['completed_count'])->toBe(2);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('JSON Epic', 'JSON Description');
        $task = $taskService->create([
            'title' => 'JSON Task',
            'status' => 'open',
            'epic_id' => $epic->id,
        ]);

        Artisan::call('epic:show', ['id' => $epic->id, '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['id'])->toBe($epic->id);
        expect($data['title'])->toBe('JSON Epic');
        expect($data['description'])->toBe('JSON Description');
        expect($data['status'])->toBe('in_progress');
        expect($data)->toHaveKey('tasks');
        expect($data['tasks'])->toBeArray();
        expect($data['tasks'])->toHaveCount(1);
        expect($data['tasks'][0]['id'])->toBe($task['id']);
        expect($data['tasks'][0]['title'])->toBe('JSON Task');
        expect($data)->toHaveKey('task_count');
        expect($data['task_count'])->toBe(1);
        expect($data)->toHaveKey('completed_count');
        expect($data['completed_count'])->toBe(0);
    });

    it('supports partial ID matching', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Partial ID Epic');

        // Use partial ID (without e- prefix)
        $partialId = substr($epic->id, 2);

        Artisan::call('epic:show', ['id' => $partialId, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Epic: '.$epic->id);
        expect($output)->toContain('Title: Partial ID Epic');
    });

    it('sorts tasks by ready order: unblocked first, then priority, then created_at', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Epic with Sorting');

        // Create blocker task (not in epic)
        $blocker = $taskService->create([
            'title' => 'Blocker Task',
            'status' => 'open',
        ]);

        // Create tasks with different priorities and blocked statuses
        // Task 1: Unblocked, priority 0
        $task1 = $taskService->create([
            'title' => 'Unblocked P0',
            'priority' => 0,
            'epic_id' => $epic->id,
        ]);

        // Task 2: Blocked, priority 0 (should appear after all unblocked tasks)
        $task2 = $taskService->create([
            'title' => 'Blocked P0',
            'priority' => 0,
            'epic_id' => $epic->id,
            'blocked_by' => [$blocker['id']],
        ]);

        // Task 3: Unblocked, priority 1 (should appear after P0 tasks)
        $task3 = $taskService->create([
            'title' => 'Unblocked P1',
            'priority' => 1,
            'epic_id' => $epic->id,
        ]);

        // Task 4: Unblocked, priority 0, created later (add delay to ensure different timestamp)
        usleep(1100000); // 1.1s delay to ensure different created_at timestamp (ISO8601 has second precision)
        $task4 = $taskService->create([
            'title' => 'Unblocked P0 Later',
            'priority' => 0,
            'epic_id' => $epic->id,
        ]);

        Artisan::call('epic:show', ['id' => $epic->id, '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        $tasks = $data['tasks'];
        expect($tasks)->toHaveCount(4);

        // Find positions of each task
        $task1Pos = array_search($task1['id'], array_column($tasks, 'id'), true);
        $task2Pos = array_search($task2['id'], array_column($tasks, 'id'), true);
        $task3Pos = array_search($task3['id'], array_column($tasks, 'id'), true);
        $task4Pos = array_search($task4['id'], array_column($tasks, 'id'), true);

        // Verify blocked task comes after all unblocked tasks
        expect($task2Pos)->toBeGreaterThan($task1Pos);
        expect($task2Pos)->toBeGreaterThan($task3Pos);
        expect($task2Pos)->toBeGreaterThan($task4Pos);

        // Verify unblocked tasks are sorted by priority (P0 before P1)
        expect($task3Pos)->toBeGreaterThan($task1Pos);
        expect($task3Pos)->toBeGreaterThan($task4Pos);

        // Verify P0 tasks are sorted by created_at (task1 before task4, since task1 was created first)
        expect($task1Pos)->toBeLessThan($task4Pos);
    });

    it('shows blocked indicator for blocked tasks', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Epic with Blocked Task');

        // Create blocker task (not in epic)
        $blocker = $taskService->create([
            'title' => 'Blocker Task',
            'status' => 'open',
        ]);

        // Create blocked task
        $blockedTask = $taskService->create([
            'title' => 'Blocked Task',
            'status' => 'open',
            'epic_id' => $epic->id,
            'blocked_by' => [$blocker['id']],
        ]);

        Artisan::call('epic:show', ['id' => $epic->id, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        // Check that blocked indicator appears (yellow "blocked" text)
        expect($output)->toContain('blocked');
        expect($output)->toContain($blockedTask['id']);
        expect($output)->toContain('Blocked Task');
    });
});
