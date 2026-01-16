<?php

use App\Enums\EpicStatus;
use App\Enums\TaskStatus;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// Pause Command Tests
describe('pause command', function (): void {
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

        $this->app->singleton(TaskService::class, fn (): TaskService => makeTaskService());
        $this->app->singleton(EpicService::class, fn (): EpicService => makeEpicService(makeTaskService()));
        $this->app->singleton(RunService::class, fn (): RunService => makeRunService());

        $this->taskService = $this->app->make(TaskService::class);
        $this->epicService = $this->app->make(EpicService::class);
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

    // =============================================================================
    // Task Pause Tests
    // =============================================================================

    it('pauses a task', function (): void {
        $task = $this->taskService->create([
            'title' => 'Task to pause',
            'description' => 'Task description',
        ]);

        Artisan::call('pause', [
            'id' => $task->short_id,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Paused task:');
        expect($output)->toContain($task->short_id);
        expect($output)->toContain('Task to pause');

        // Verify task has status=paused
        $pausedTask = $this->taskService->find($task->short_id);
        expect($pausedTask)->not->toBeNull();
        expect($pausedTask->status)->toBe(TaskStatus::Paused);
        expect($pausedTask->title)->toBe('Task to pause');
        expect($pausedTask->description)->toBe('Task description');
    });

    it('pauses a task with partial ID', function (): void {
        $task = $this->taskService->create(['title' => 'Task to pause']);
        $partialId = substr((string) $task->short_id, 2, 3);

        Artisan::call('pause', [
            'id' => $partialId,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Paused task:');

        // Verify task has status=paused
        $pausedTask = $this->taskService->find($task->short_id);
        expect($pausedTask)->not->toBeNull();
        expect($pausedTask->status)->toBe(TaskStatus::Paused);
    });

    it('outputs JSON when --json flag is used for task', function (): void {
        $task = $this->taskService->create(['title' => 'JSON task']);

        Artisan::call('pause', [
            'id' => $task->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['short_id'])->toBe($task->short_id);
        expect($result['title'])->toBe('JSON task');
        expect($result['status'])->toBe(TaskStatus::Paused->value);
    });

    it('returns error when task not found', function (): void {
        $this->artisan('pause', [
            'id' => 'f-nonexistent',
        ])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('can pause task that is already paused (idempotent)', function (): void {
        $task = $this->taskService->create(['title' => 'Already paused']);

        // Pause once
        $this->taskService->pause($task->short_id);

        // Pause again - should succeed
        Artisan::call('pause', [
            'id' => $task->short_id,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Paused task:');

        $pausedTask = $this->taskService->find($task->short_id);
        expect($pausedTask)->not->toBeNull();
        expect($pausedTask->status)->toBe(TaskStatus::Paused);
    });

    it('can pause task in any status', function (): void {
        // Test pausing from different statuses
        $statuses = [
            TaskStatus::Open,
            TaskStatus::InProgress,
            TaskStatus::Someday,
        ];

        foreach ($statuses as $status) {
            $task = $this->taskService->create(['title' => "Task in {$status->value}"]);
            $this->taskService->update($task->short_id, ['status' => $status->value]);

            Artisan::call('pause', [
                'id' => $task->short_id,
            ]);

            $pausedTask = $this->taskService->find($task->short_id);
            expect($pausedTask->status)->toBe(TaskStatus::Paused);
        }
    });

    // =============================================================================
    // Epic Pause Tests
    // =============================================================================

    it('pauses an epic', function (): void {
        $epic = $this->epicService->createEpic('Epic to pause', 'Epic description');

        Artisan::call('pause', [
            'id' => $epic->short_id,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Paused epic:');
        expect($output)->toContain($epic->short_id);
        expect($output)->toContain('Epic to pause');

        // Verify epic has status=paused
        $pausedEpic = $this->epicService->getEpic($epic->short_id);
        expect($pausedEpic)->not->toBeNull();
        expect($pausedEpic->status)->toBe(EpicStatus::Paused);
        expect($pausedEpic->title)->toBe('Epic to pause');
        expect($pausedEpic->description)->toBe('Epic description');
        expect($pausedEpic->paused_at)->not->toBeNull();
    });

    it('pauses an epic with partial ID', function (): void {
        $epic = $this->epicService->createEpic('Epic to pause');
        // Use e- prefix with partial hash for clarity
        $partialId = 'e-'.substr((string) $epic->short_id, 2, 3);

        Artisan::call('pause', [
            'id' => $partialId,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Paused epic:');

        // Verify epic has status=paused
        $pausedEpic = $this->epicService->getEpic($epic->short_id);
        expect($pausedEpic)->not->toBeNull();
        expect($pausedEpic->status)->toBe(EpicStatus::Paused);
        expect($pausedEpic->paused_at)->not->toBeNull();
    });

    it('outputs JSON when --json flag is used for epic', function (): void {
        $epic = $this->epicService->createEpic('JSON epic');

        Artisan::call('pause', [
            'id' => $epic->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['short_id'])->toBe($epic->short_id);
        expect($result['title'])->toBe('JSON epic');
        expect($result['status'])->toBe(EpicStatus::Paused->value);
        expect($result['paused_at'])->not->toBeNull();
    });

    it('returns error when epic not found', function (): void {
        $this->artisan('pause', [
            'id' => 'e-nonexistent',
        ])
            ->expectsOutputToContain("Epic 'e-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('can pause epic that is already paused (idempotent)', function (): void {
        $epic = $this->epicService->createEpic('Already paused');

        // Pause once
        $this->epicService->pause($epic->short_id);

        // Pause again - should succeed
        Artisan::call('pause', [
            'id' => $epic->short_id,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Paused epic:');

        $pausedEpic = $this->epicService->getEpic($epic->short_id);
        expect($pausedEpic)->not->toBeNull();
        expect($pausedEpic->status)->toBe(EpicStatus::Paused);
    });

    it('defaults to task when no prefix provided', function (): void {
        $task = $this->taskService->create(['title' => 'Task without prefix']);
        $partialId = substr((string) $task->short_id, 2); // Remove f- prefix

        Artisan::call('pause', [
            'id' => $partialId,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Paused task:');

        $pausedTask = $this->taskService->find($task->short_id);
        expect($pausedTask->status)->toBe(TaskStatus::Paused);
    });
});
