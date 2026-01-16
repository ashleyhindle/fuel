<?php

use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;

// =============================================================================
// selfguided:blocked Command Tests
// =============================================================================
describe('selfguided:blocked command', function (): void {
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

    it('creates needs-human task and adds dependency', function (): void {
        $selfguidedTask = $this->taskService->create([
            'title' => 'Self-guided task',
            'agent' => 'selfguided',
        ]);

        Artisan::call('selfguided:blocked', [
            'id' => $selfguidedTask->short_id,
            '--reason' => 'Need credentials',
        ]);

        $output = Artisan::output();
        expect($output)->toContain('Created needs-human task');

        // Verify needs-human task was created
        $tasks = $this->taskService->all();
        $needsHumanTask = $tasks->filter(fn ($task): bool => str_contains((string) $task->title, 'Blocked: Need credentials'))->first();

        expect($needsHumanTask)->not->toBeNull();
        expect($needsHumanTask->labels)->toContain('needs-human');
        expect($needsHumanTask->description)->toContain('Need credentials');

        // Verify dependency was added
        $updatedSelfguidedTask = $this->taskService->find($selfguidedTask->short_id);
        expect($updatedSelfguidedTask->blocked_by)->toHaveCount(1);
        expect($updatedSelfguidedTask->blocked_by)->toContain($needsHumanTask->short_id);
    });

    it('uses task title when no reason provided', function (): void {
        $selfguidedTask = $this->taskService->create([
            'title' => 'My selfguided task',
            'agent' => 'selfguided',
        ]);

        $this->artisan('selfguided:blocked', [
            'id' => $selfguidedTask->short_id,
        ])
            ->expectsOutputToContain('Created needs-human task')
            ->assertExitCode(0);

        // Verify needs-human task title uses original task title
        $tasks = $this->taskService->all();
        $needsHumanTask = $tasks->filter(fn ($task): bool => str_contains((string) $task->title, 'Blocked: My selfguided task'))->first();

        expect($needsHumanTask)->not->toBeNull();
    });

    it('outputs JSON when --json flag is used', function (): void {
        $selfguidedTask = $this->taskService->create([
            'title' => 'Self-guided task',
            'agent' => 'selfguided',
        ]);

        Artisan::call('selfguided:blocked', [
            'id' => $selfguidedTask->short_id,
            '--reason' => 'Need approval',
            '--json' => true,
        ]);

        $output = Artisan::output();
        expect($output)->toContain('needs_human_task');
        expect($output)->toContain('selfguided_task');
        expect($output)->toContain('blocked');
    });

    it('shows error for non-existent task', function (): void {
        $this->artisan('selfguided:blocked', [
            'id' => 'nonexistent',
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('shows error for non-selfguided task', function (): void {
        $regularTask = $this->taskService->create([
            'title' => 'Regular task',
        ]);

        $this->artisan('selfguided:blocked', [
            'id' => $regularTask->short_id,
        ])
            ->expectsOutputToContain('not a self-guided task')
            ->assertExitCode(1);
    });

    it('supports partial ID matching', function (): void {
        $selfguidedTask = $this->taskService->create([
            'title' => 'Self-guided task',
            'agent' => 'selfguided',
        ]);

        $partialId = substr((string) $selfguidedTask->short_id, 2, 3);

        $this->artisan('selfguided:blocked', [
            'id' => $partialId,
            '--reason' => 'Testing partial ID',
        ])
            ->expectsOutputToContain('Created needs-human task')
            ->assertExitCode(0);
    });
});
