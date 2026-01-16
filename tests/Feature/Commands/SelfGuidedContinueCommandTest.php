<?php

use App\Enums\TaskStatus;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

describe('selfguided:continue command', function (): void {
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

        $this->app->singleton(RunService::class, fn (): RunService => makeRunService());

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

    it('continues a selfguided task by incrementing iteration and reopening', function (): void {
        $task = $this->taskService->create([
            'title' => 'Selfguided task',
            'agent' => 'selfguided',
            'selfguided_iteration' => 5,
            'selfguided_stuck_count' => 3,
        ]);
        $this->taskService->done($task->short_id);

        $this->artisan('selfguided:continue', ['id' => $task->short_id])
            ->expectsOutputToContain('Task reopened for iteration 6')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Open);
        expect($updated->selfguided_iteration)->toBe(6);
        expect($updated->selfguided_stuck_count)->toBe(0);
    });

    it('initializes iteration to 1 when null', function (): void {
        $task = $this->taskService->create([
            'title' => 'New selfguided task',
            'agent' => 'selfguided',
        ]);
        $this->taskService->done($task->short_id);

        $this->artisan('selfguided:continue', ['id' => $task->short_id])
            ->expectsOutputToContain('Task reopened for iteration 1')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->selfguided_iteration)->toBe(1);
    });

    it('creates needs-human task when max iterations reached', function (): void {
        $task = $this->taskService->create([
            'title' => 'Task at max iterations',
            'agent' => 'selfguided',
            'selfguided_iteration' => 49,
        ]);
        $this->taskService->done($task->short_id);

        $this->artisan('selfguided:continue', ['id' => $task->short_id])
            ->expectsOutputToContain('Max iterations (50) reached')
            ->expectsOutputToContain('Created needs-human task')
            ->assertExitCode(0);

        $allTasks = $this->taskService->all();
        $needsHumanTask = $allTasks->first(fn ($t): bool => str_starts_with((string) $t->title, 'Max iterations reached'));

        expect($needsHumanTask)->not->toBeNull();
        expect($needsHumanTask->labels)->toContain('needs-human');

        // Verify dependency was added
        $updated = $this->taskService->find($task->short_id);
        expect($updated->blocked_by)->toContain($needsHumanTask->short_id);
    });

    it('appends notes to task description when provided', function (): void {
        $task = $this->taskService->create([
            'title' => 'Task with notes',
            'description' => 'Original description',
            'agent' => 'selfguided',
            'selfguided_iteration' => 2,
        ]);
        $this->taskService->done($task->short_id);

        $this->artisan('selfguided:continue', [
            'id' => $task->short_id,
            '--notes' => 'Made some progress on feature X',
        ])
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->description)->toContain('Original description');
        expect($updated->description)->toContain('--- Iteration 3 notes ---');
        expect($updated->description)->toContain('Made some progress on feature X');
    });

    it('stores commit hash on latest run when --commit is provided', function (): void {
        $task = $this->taskService->create([
            'title' => 'Selfguided task with commit',
            'agent' => 'selfguided',
            'selfguided_iteration' => 1,
        ]);
        $this->taskService->done($task->short_id);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'selfguided',
            'model' => 'test-model',
        ]);

        $commitHash = 'abc123def456';
        $this->artisan('selfguided:continue', [
            'id' => $task->short_id,
            '--commit' => $commitHash,
        ])
            ->assertExitCode(0);

        $latestRun = $runService->getLatestRun($task->short_id);
        expect($latestRun)->not->toBeNull();
        expect($latestRun->commit_hash)->toBe($commitHash);
    });

    it('continues without --commit and leaves run commit_hash null', function (): void {
        $task = $this->taskService->create([
            'title' => 'Selfguided task without commit',
            'agent' => 'selfguided',
            'selfguided_iteration' => 2,
        ]);
        $this->taskService->done($task->short_id);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'selfguided',
            'model' => 'test-model',
        ]);

        $this->artisan('selfguided:continue', ['id' => $task->short_id])
            ->assertExitCode(0);

        $latestRun = $runService->getLatestRun($task->short_id);
        expect($latestRun)->not->toBeNull();
        expect($latestRun->commit_hash)->toBeNull();
    });

    it('fails when task is not selfguided', function (): void {
        $task = $this->taskService->create([
            'title' => 'Regular task',
            'agent' => 'haiku',
        ]);
        $this->taskService->done($task->short_id);

        $this->artisan('selfguided:continue', ['id' => $task->short_id])
            ->expectsOutputToContain('is not a selfguided task')
            ->assertExitCode(1);
    });

    it('fails when task does not exist', function (): void {
        $this->artisan('selfguided:continue', ['id' => 'nonexistent'])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('supports partial ID matching', function (): void {
        $task = $this->taskService->create([
            'title' => 'Partial ID task',
            'agent' => 'selfguided',
            'selfguided_iteration' => 1,
        ]);
        $this->taskService->done($task->short_id);
        $partialId = substr((string) $task->short_id, 2, 3);

        $this->artisan('selfguided:continue', ['id' => $partialId])
            ->expectsOutputToContain('Task reopened for iteration 2')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->selfguided_iteration)->toBe(2);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $task = $this->taskService->create([
            'title' => 'JSON task',
            'agent' => 'selfguided',
            'selfguided_iteration' => 3,
        ]);
        $this->taskService->done($task->short_id);

        Artisan::call('selfguided:continue', [
            'id' => $task->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['short_id'])->toBe($task->short_id);
        expect($result['status'])->toBe('open');
        expect($result['selfguided_iteration'])->toBe(4);
        expect($result['selfguided_stuck_count'])->toBe(0);
    });

    it('outputs JSON with max iterations info when limit reached', function (): void {
        $task = $this->taskService->create([
            'title' => 'Max iteration JSON task',
            'agent' => 'selfguided',
            'selfguided_iteration' => 49,
        ]);
        $this->taskService->done($task->short_id);

        Artisan::call('selfguided:continue', [
            'id' => $task->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['status'])->toBe('max_iterations_reached');
        expect($result['iteration'])->toBe(50);
        expect($result['needs_human_task'])->toBeArray();
        expect($result['needs_human_task']['labels'])->toContain('needs-human');
    });

    it('resets stuck count to 0 on continue', function (): void {
        $task = $this->taskService->create([
            'title' => 'Stuck task',
            'agent' => 'selfguided',
            'selfguided_iteration' => 10,
            'selfguided_stuck_count' => 5,
        ]);
        $this->taskService->done($task->short_id);

        $this->artisan('selfguided:continue', ['id' => $task->short_id])
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->selfguided_stuck_count)->toBe(0);
    });

    it('handles task with null agent field', function (): void {
        $task = $this->taskService->create([
            'title' => 'Task without agent',
        ]);
        $this->taskService->done($task->short_id);

        $this->artisan('selfguided:continue', ['id' => $task->short_id])
            ->expectsOutputToContain("agent='null'")
            ->assertExitCode(1);
    });
});
