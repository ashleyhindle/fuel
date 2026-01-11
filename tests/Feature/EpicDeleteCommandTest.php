<?php

declare(strict_types=1);

use App\Services\BacklogService;
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
    $databaseService = new DatabaseService($context->getDatabasePath());
    $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);
    $this->app->singleton(TaskService::class, fn (): TaskService => new TaskService($databaseService));
    $this->app->singleton(EpicService::class, fn (): EpicService => new EpicService(
        $this->app->make(DatabaseService::class),
        $this->app->make(TaskService::class)
    ));

    $this->databaseService = $this->app->make(DatabaseService::class);
    $this->databaseService->initialize();
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
                unlink($path);
            }
        }

        rmdir($dir);
    };

    $deleteDir($this->tempDir);
});

describe('epic:delete command', function (): void {
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

    it('deletes an epic with --force flag', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic', 'Test Description');

        Artisan::call('epic:delete', ['id' => $epic->id, '--cwd' => $this->tempDir, '--force' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Deleted epic: '.$epic->id);

        $deletedEpic = $epicService->getEpic($epic->id);
        expect($deletedEpic)->toBeNull();
    });

    it('shows error when epic not found', function (): void {
        Artisan::call('epic:delete', ['id' => 'e-nonexistent', '--cwd' => $this->tempDir, '--force' => true]);
        $output = Artisan::output();

        expect($output)->toContain("Epic 'e-nonexistent' not found");
    });

    it('outputs JSON when --json flag is used', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('JSON Epic', 'JSON Description');

        Artisan::call('epic:delete', ['id' => $epic->id, '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['id'])->toBe($epic->id);
        expect($data['deleted'])->toBeArray();
        expect($data['deleted']['title'])->toBe('JSON Epic');
        expect($data['unlinked_tasks'])->toBeArray();
        expect($data['unlinked_tasks'])->toBeEmpty();
    });

    it('supports partial ID matching', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Partial ID Epic');

        $partialId = substr((string) $epic->id, 2);

        Artisan::call('epic:delete', ['id' => $partialId, '--cwd' => $this->tempDir, '--force' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Deleted epic: '.$epic->id);

        $deletedEpic = $epicService->getEpic($epic->id);
        expect($deletedEpic)->toBeNull();
    });

    it('unlinks tasks when deleting an epic', function (): void {
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

        Artisan::call('epic:delete', ['id' => $epic->id, '--cwd' => $this->tempDir, '--force' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Deleted epic: '.$epic->id);
        expect($output)->toContain('Unlinked tasks:');
        expect($output)->toContain($task1['id']);
        expect($output)->toContain($task2['id']);

        $updatedTask1 = $taskService->find($task1['id']);
        $updatedTask2 = $taskService->find($task2['id']);
        expect($updatedTask1['epic_id'])->toBeNull();
        expect($updatedTask2['epic_id'])->toBeNull();

        $deletedEpic = $epicService->getEpic($epic->id);
        expect($deletedEpic)->toBeNull();
    });

    it('includes unlinked task IDs in JSON output', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Epic with Tasks JSON');
        $task1 = $taskService->create([
            'title' => 'Task 1',
            'epic_id' => $epic->id,
        ]);
        $task2 = $taskService->create([
            'title' => 'Task 2',
            'epic_id' => $epic->id,
        ]);

        Artisan::call('epic:delete', ['id' => $epic->id, '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['id'])->toBe($epic->id);
        expect($data['unlinked_tasks'])->toContain($task1['id']);
        expect($data['unlinked_tasks'])->toContain($task2['id']);
    });

    it('shows error in JSON format when --json flag is used and epic not found', function (): void {
        Artisan::call('epic:delete', ['id' => 'e-nonexistent', '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['error'])->toContain("Epic 'e-nonexistent' not found");
    });
});
