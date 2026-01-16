<?php

use App\Enums\EpicStatus;
use App\Enums\TaskStatus;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// Unpause Command Tests
describe('unpause command', function (): void {
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
    // Task Unpause Tests
    // =============================================================================

    it('unpauses a paused task', function (): void {
        $task = $this->taskService->create([
            'title' => 'Task to unpause',
            'description' => 'Task description',
        ]);

        // Pause the task first
        $this->taskService->pause($task->short_id);

        Artisan::call('unpause', [
            'id' => $task->short_id,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Unpaused task:');
        expect($output)->toContain($task->short_id);
        expect($output)->toContain('Task to unpause');

        // Verify task has status=open
        $unpausedTask = $this->taskService->find($task->short_id);
        expect($unpausedTask)->not->toBeNull();
        expect($unpausedTask->status)->toBe(TaskStatus::Open);
        expect($unpausedTask->title)->toBe('Task to unpause');
        expect($unpausedTask->description)->toBe('Task description');
    });

    it('unpauses a task with partial ID', function (): void {
        $task = $this->taskService->create(['title' => 'Task to unpause']);
        $this->taskService->pause($task->short_id);

        $partialId = substr((string) $task->short_id, 2, 3);

        Artisan::call('unpause', [
            'id' => $partialId,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Unpaused task:');

        // Verify task has status=open
        $unpausedTask = $this->taskService->find($task->short_id);
        expect($unpausedTask)->not->toBeNull();
        expect($unpausedTask->status)->toBe(TaskStatus::Open);
    });

    it('outputs JSON when --json flag is used for task', function (): void {
        $task = $this->taskService->create(['title' => 'JSON task']);
        $this->taskService->pause($task->short_id);

        Artisan::call('unpause', [
            'id' => $task->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['short_id'])->toBe($task->short_id);
        expect($result['title'])->toBe('JSON task');
        expect($result['status'])->toBe(TaskStatus::Open->value);
    });

    it('returns error when task not found', function (): void {
        $this->artisan('unpause', [
            'id' => 'f-nonexistent',
        ])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('can unpause task that is already open (idempotent)', function (): void {
        $task = $this->taskService->create(['title' => 'Already open']);

        // Task is already open, unpause should still work
        Artisan::call('unpause', [
            'id' => $task->short_id,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Unpaused task:');

        $unpausedTask = $this->taskService->find($task->short_id);
        expect($unpausedTask)->not->toBeNull();
        expect($unpausedTask->status)->toBe(TaskStatus::Open);
    });

    it('sets task to open regardless of previous status', function (): void {
        $task = $this->taskService->create(['title' => 'Test task']);

        // Test unpausing from paused status
        $this->taskService->pause($task->short_id);

        Artisan::call('unpause', [
            'id' => $task->short_id,
        ]);

        $unpausedTask = $this->taskService->find($task->short_id);
        expect($unpausedTask->status)->toBe(TaskStatus::Open);
    });

    // =============================================================================
    // Epic Unpause Tests
    // =============================================================================

    it('unpauses a paused epic', function (): void {
        $epic = $this->epicService->createEpic('Epic to unpause', 'Epic description');

        // Pause the epic first
        $this->epicService->pause($epic->short_id);

        Artisan::call('unpause', [
            'id' => $epic->short_id,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Unpaused epic:');
        expect($output)->toContain($epic->short_id);
        expect($output)->toContain('Epic to unpause');

        // Verify epic is no longer paused
        $unpausedEpic = $this->epicService->getEpic($epic->short_id);
        expect($unpausedEpic)->not->toBeNull();
        expect($unpausedEpic->status)->not->toBe(EpicStatus::Paused);
        expect($unpausedEpic->title)->toBe('Epic to unpause');
        expect($unpausedEpic->description)->toBe('Epic description');
        expect($unpausedEpic->paused_at)->toBeNull();
    });

    it('unpauses an epic with partial ID', function (): void {
        $epic = $this->epicService->createEpic('Epic to unpause');
        $this->epicService->pause($epic->short_id);

        // Use e- prefix with partial hash for clarity
        $partialId = 'e-'.substr((string) $epic->short_id, 2, 3);

        Artisan::call('unpause', [
            'id' => $partialId,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Unpaused epic:');

        // Verify epic is no longer paused
        $unpausedEpic = $this->epicService->getEpic($epic->short_id);
        expect($unpausedEpic)->not->toBeNull();
        expect($unpausedEpic->status)->not->toBe(EpicStatus::Paused);
        expect($unpausedEpic->paused_at)->toBeNull();
    });

    it('outputs JSON when --json flag is used for epic', function (): void {
        $epic = $this->epicService->createEpic('JSON epic');
        $this->epicService->pause($epic->short_id);

        Artisan::call('unpause', [
            'id' => $epic->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['short_id'])->toBe($epic->short_id);
        expect($result['title'])->toBe('JSON epic');
        expect($result['status'])->not->toBe(EpicStatus::Paused->value);
        expect($result['paused_at'])->toBeNull();
    });

    it('returns error when epic not found', function (): void {
        $this->artisan('unpause', [
            'id' => 'e-nonexistent',
        ])
            ->expectsOutputToContain("Epic 'e-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('can unpause epic that is already unpaused (idempotent)', function (): void {
        $epic = $this->epicService->createEpic('Already unpaused');

        // Epic is already unpaused, unpause should still work
        Artisan::call('unpause', [
            'id' => $epic->short_id,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Unpaused epic:');

        $unpausedEpic = $this->epicService->getEpic($epic->short_id);
        expect($unpausedEpic)->not->toBeNull();
        expect($unpausedEpic->status)->not->toBe(EpicStatus::Paused);
    });

    it('epic status is computed dynamically after unpause', function (): void {
        $epic = $this->epicService->createEpic('Epic with tasks');

        // Add a task to the epic to make it in_progress
        $task = $this->taskService->create([
            'title' => 'Task in epic',
            'epic_id' => $epic->short_id,
        ]);

        // Pause the epic
        $this->epicService->pause($epic->short_id);
        $pausedEpic = $this->epicService->getEpic($epic->short_id);
        expect($pausedEpic->status)->toBe(EpicStatus::Paused);

        // Unpause the epic
        Artisan::call('unpause', [
            'id' => $epic->short_id,
        ]);

        // Epic should return to InProgress status (has open tasks)
        $unpausedEpic = $this->epicService->getEpic($epic->short_id);
        expect($unpausedEpic->status)->toBe(EpicStatus::InProgress);
    });

    it('defaults to task when no prefix provided', function (): void {
        $task = $this->taskService->create(['title' => 'Task without prefix']);
        $this->taskService->pause($task->short_id);

        $partialId = substr((string) $task->short_id, 2); // Remove f- prefix

        Artisan::call('unpause', [
            'id' => $partialId,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Unpaused task:');

        $unpausedTask = $this->taskService->find($task->short_id);
        expect($unpausedTask->status)->toBe(TaskStatus::Open);
    });
});
