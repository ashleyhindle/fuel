<?php

use App\Services\BacklogService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// Promote Command Tests
describe('promote command', function (): void {
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

    it('promotes backlog item to task', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item = $backlogService->add('Future feature', 'Description');

        Artisan::call('promote', [
            'ids' => [$item['id']],
            '--priority' => '2',
            '--type' => 'feature',
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Promoted backlog item');
        expect($output)->toContain($item['id']);
        expect($output)->toContain('to task: f-');
        expect($output)->toContain('Future feature');

        // Verify item removed from backlog
        expect($backlogService->find($item['id']))->toBeNull();

        // Verify task created
        $this->taskService->initialize();
        $tasks = $this->taskService->all();
        expect($tasks->count())->toBe(1);
        $task = $tasks->first();
        expect($task['title'])->toBe('Future feature');
        expect($task['description'])->toBe('Description');
        expect($task['priority'])->toBe(2);
        expect($task['type'])->toBe('feature');
    });

    it('promotes backlog item with all task options', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item = $backlogService->add('Complete feature', 'Full description');

        Artisan::call('promote', [
            'ids' => [$item['id']],
            '--priority' => '3',
            '--type' => 'feature',
            '--complexity' => 'moderate',
            '--labels' => 'frontend,backend',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['title'])->toBe('Complete feature');
        expect($task['description'])->toBe('Full description');
        expect($task['priority'])->toBe(3);
        expect($task['type'])->toBe('feature');
        expect($task['complexity'])->toBe('moderate');
        expect($task['labels'])->toBe(['frontend', 'backend']);
    });

    it('promotes backlog item with partial ID', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item = $backlogService->add('Feature to promote');
        $partialId = substr((string) $item['id'], 2, 3);

        Artisan::call('promote', [
            'ids' => [$partialId],
            '--priority' => '1',
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Promoted backlog item');
        expect($output)->toContain('to task: f-');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item = $backlogService->add('JSON feature');

        Artisan::call('promote', [
            'ids' => [$item['id']],
            '--priority' => '2',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['id'])->toStartWith('f-');
        expect($task['title'])->toBe('JSON feature');
        expect($task['priority'])->toBe(2);
    });

    it('returns error when backlog item not found', function (): void {
        $this->artisan('promote', [
            'ids' => ['b-nonexistent'],
            '--priority' => '1',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain("Backlog item 'b-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('returns error when ID is not a backlog item', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        $this->artisan('promote', [
            'ids' => [$task['id']],
            '--priority' => '1',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('is not a backlog item')
            ->assertExitCode(1);
    });

    it('promotes multiple backlog items to tasks', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item1 = $backlogService->add('First feature', 'First description');
        $item2 = $backlogService->add('Second feature', 'Second description');
        $item3 = $backlogService->add('Third feature', 'Third description');

        Artisan::call('promote', [
            'ids' => [$item1['id'], $item2['id'], $item3['id']],
            '--priority' => '2',
            '--type' => 'feature',
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Promoted backlog item');
        expect($output)->toContain($item1['id']);
        expect($output)->toContain($item2['id']);
        expect($output)->toContain($item3['id']);
        expect($output)->toContain('First feature');
        expect($output)->toContain('Second feature');
        expect($output)->toContain('Third feature');

        // Verify items removed from backlog
        expect($backlogService->find($item1['id']))->toBeNull();
        expect($backlogService->find($item2['id']))->toBeNull();
        expect($backlogService->find($item3['id']))->toBeNull();

        // Verify tasks created
        $this->taskService->initialize();
        $tasks = $this->taskService->all();
        expect($tasks->count())->toBe(3);
        $taskTitles = $tasks->pluck('title')->toArray();
        expect($taskTitles)->toContain('First feature');
        expect($taskTitles)->toContain('Second feature');
        expect($taskTitles)->toContain('Third feature');
    });

    it('promotes multiple backlog items with JSON output', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item1 = $backlogService->add('JSON feature 1');
        $item2 = $backlogService->add('JSON feature 2');

        Artisan::call('promote', [
            'ids' => [$item1['id'], $item2['id']],
            '--priority' => '1',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $tasks = json_decode($output, true);

        expect($tasks)->toBeArray();
        expect(count($tasks))->toBe(2);
        expect($tasks[0]['title'])->toBe('JSON feature 1');
        expect($tasks[1]['title'])->toBe('JSON feature 2');
        expect($tasks[0]['id'])->toStartWith('f-');
        expect($tasks[1]['id'])->toStartWith('f-');
    });

    it('handles partial failures when promoting multiple items', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item1 = $backlogService->add('Valid feature');
        $item2 = $backlogService->add('Another valid feature');

        Artisan::call('promote', [
            'ids' => [$item1['id'], 'b-nonexistent', $item2['id']],
            '--priority' => '2',
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        // Should show success for valid items
        expect($output)->toContain('Promoted backlog item');
        expect($output)->toContain('Valid feature');
        expect($output)->toContain('Another valid feature');

        // Should show error for invalid item
        expect($output)->toContain("Backlog item 'b-nonexistent' not found");

        // Verify valid items were promoted
        $this->taskService->initialize();
        $tasks = $this->taskService->all();
        expect($tasks->count())->toBe(2);
    });
});
