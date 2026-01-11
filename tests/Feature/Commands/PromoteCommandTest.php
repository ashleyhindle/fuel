<?php

use App\Enums\TaskStatus;
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

        $this->taskService = $this->app->make(TaskService::class);
        $this->taskService->initialize();
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
        $item = $this->taskService->create([
            'title' => 'Future feature',
            'description' => 'Description',
        ]);
        // Update to someday status (workaround since create() doesn't respect status field)
        $item = $this->taskService->update($item['id'], ['status' => TaskStatus::Someday->value]);

        Artisan::call('promote', [
            'ids' => [$item['id']],
            '--priority' => '2',
            '--type' => 'feature',
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Promoted task');
        expect($output)->toContain($item['id']);
        expect($output)->toContain('from backlog to active');
        expect($output)->toContain('Future feature');

        // Verify task status changed to open
        $task = $this->taskService->find($item['id']);
        expect($task['title'])->toBe('Future feature');
        expect($task['description'])->toBe('Description');
        expect($task['status'])->toBe(TaskStatus::Open->value);
        expect($task['priority'])->toBe(2);
        expect($task['type'])->toBe('feature');
    });

    it('promotes backlog item with all task options', function (): void {
        $item = $this->taskService->create([
            'title' => 'Complete feature',
            'description' => 'Full description',
        ]);
        $item = $this->taskService->update($item['id'], ['status' => TaskStatus::Someday->value]);

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
        $item = $this->taskService->create([
            'title' => 'Feature to promote',
        ]);
        $item = $this->taskService->update($item['id'], ['status' => TaskStatus::Someday->value]);

        $partialId = substr((string) $item['id'], 2, 3);

        Artisan::call('promote', [
            'ids' => [$partialId],
            '--priority' => '1',
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Promoted task');
        expect($output)->toContain('from backlog to active');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $item = $this->taskService->create([
            'title' => 'JSON feature',
        ]);
        $item = $this->taskService->update($item['id'], ['status' => TaskStatus::Someday->value]);

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

    it('returns error when task not found', function (): void {
        $this->artisan('promote', [
            'ids' => ['f-nonexistent'],
            '--priority' => '1',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('returns error when task status is not someday', function (): void {
        $task = $this->taskService->create([
            'title' => 'Regular task',
            'status' => TaskStatus::Open->value,
        ]);

        $this->artisan('promote', [
            'ids' => [$task['id']],
            '--priority' => '1',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('is not a backlog item')
            ->assertExitCode(1);
    });

    it('promotes multiple backlog items to tasks', function (): void {
        $item1 = $this->taskService->create([
            'title' => 'First feature',
            'description' => 'First description',
        ]);
        $item1 = $this->taskService->update($item1['id'], ['status' => TaskStatus::Someday->value]);

        $item2 = $this->taskService->create([
            'title' => 'Second feature',
            'description' => 'Second description',
        ]);
        $item2 = $this->taskService->update($item2['id'], ['status' => TaskStatus::Someday->value]);

        $item3 = $this->taskService->create([
            'title' => 'Third feature',
            'description' => 'Third description',
        ]);
        $item3 = $this->taskService->update($item3['id'], ['status' => TaskStatus::Someday->value]);

        Artisan::call('promote', [
            'ids' => [$item1['id'], $item2['id'], $item3['id']],
            '--priority' => '2',
            '--type' => 'feature',
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Promoted task');
        expect($output)->toContain($item1['id']);
        expect($output)->toContain($item2['id']);
        expect($output)->toContain($item3['id']);
        expect($output)->toContain('First feature');
        expect($output)->toContain('Second feature');
        expect($output)->toContain('Third feature');

        // Verify all tasks status changed to open
        $task1 = $this->taskService->find($item1['id']);
        $task2 = $this->taskService->find($item2['id']);
        $task3 = $this->taskService->find($item3['id']);
        expect($task1['status'])->toBe(TaskStatus::Open->value);
        expect($task2['status'])->toBe(TaskStatus::Open->value);
        expect($task3['status'])->toBe(TaskStatus::Open->value);
    });

    it('promotes multiple backlog items with JSON output', function (): void {
        $item1 = $this->taskService->create([
            'title' => 'JSON feature 1',
        ]);
        $item1 = $this->taskService->update($item1['id'], ['status' => TaskStatus::Someday->value]);

        $item2 = $this->taskService->create([
            'title' => 'JSON feature 2',
        ]);
        $item2 = $this->taskService->update($item2['id'], ['status' => TaskStatus::Someday->value]);

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
        $item1 = $this->taskService->create([
            'title' => 'Valid feature',
        ]);
        $item1 = $this->taskService->update($item1['id'], ['status' => TaskStatus::Someday->value]);

        $item2 = $this->taskService->create([
            'title' => 'Another valid feature',
        ]);
        $item2 = $this->taskService->update($item2['id'], ['status' => TaskStatus::Someday->value]);

        Artisan::call('promote', [
            'ids' => [$item1['id'], 'f-nonexistent', $item2['id']],
            '--priority' => '2',
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        // Should show success for valid items
        expect($output)->toContain('Promoted task');
        expect($output)->toContain('Valid feature');
        expect($output)->toContain('Another valid feature');

        // Should show error for invalid item
        expect($output)->toContain("Task 'f-nonexistent' not found");

        // Verify valid items were promoted
        $task1 = $this->taskService->find($item1['id']);
        $task2 = $this->taskService->find($item2['id']);
        expect($task1['status'])->toBe(TaskStatus::Open->value);
        expect($task2['status'])->toBe(TaskStatus::Open->value);
    });
});
