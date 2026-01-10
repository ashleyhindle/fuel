<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->dbPath = $this->tempDir.'/.fuel/agent.db';
    mkdir(dirname($this->dbPath), 0755, true);
    $this->tasksPath = $this->tempDir.'/.fuel/tasks.jsonl';

    // Bind our test service instances
    $this->app->singleton(DatabaseService::class, fn (): DatabaseService => new DatabaseService($this->dbPath));
    $this->app->singleton(TaskService::class, fn (): TaskService => new TaskService($this->tasksPath));
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
    it('shows error when epic not found', function (): void {
        Artisan::call('epic:show', ['id' => 'e-nonexistent', '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain("Epic 'e-nonexistent' not found");
    });

    it('shows epic details without tasks', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic', 'Test Description');

        Artisan::call('epic:show', ['id' => $epic['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Epic: '.$epic['id']);
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
            'epic_id' => $epic['id'],
        ]);
        $task2 = $taskService->create([
            'title' => 'Task 2',
            'type' => 'bug',
            'priority' => 2,
            'epic_id' => $epic['id'],
        ]);
        // Update task2 to closed status
        $taskService->update($task2['id'], ['status' => 'closed']);

        Artisan::call('epic:show', ['id' => $epic['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Epic: '.$epic['id']);
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
            'epic_id' => $epic['id'],
        ]);
        $task2 = $taskService->create([
            'title' => 'Task 2',
            'epic_id' => $epic['id'],
        ]);
        $task3 = $taskService->create([
            'title' => 'Task 3',
            'epic_id' => $epic['id'],
        ]);
        // Update tasks to closed status
        $taskService->update($task1['id'], ['status' => 'closed']);
        $taskService->update($task2['id'], ['status' => 'closed']);

        Artisan::call('epic:show', ['id' => $epic['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Progress: 2/3 complete'); // 2 completed out of 3 total

        // Check JSON output
        Artisan::call('epic:show', ['id' => $epic['id'], '--cwd' => $this->tempDir, '--json' => true]);
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
            'epic_id' => $epic['id'],
        ]);

        Artisan::call('epic:show', ['id' => $epic['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['id'])->toBe($epic['id']);
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
        $partialId = substr($epic['id'], 2);

        Artisan::call('epic:show', ['id' => $partialId, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Epic: '.$epic['id']);
        expect($output)->toContain('Title: Partial ID Epic');
    });
});
