<?php

use App\Services\FuelContext;
use App\Services\DatabaseService;
use App\Services\TaskService;
use App\Services\RunService;
use App\Services\BacklogService;
use Illuminate\Support\Facades\Artisan;

// Defer Command Tests
describe('defer command', function (): void {
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

    it('defers task to backlog', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $this->taskService->initialize();
        $task = $this->taskService->create([
            'title' => 'Task to defer',
            'description' => 'Task description',
            'priority' => 2,
        ]);

        Artisan::call('defer', [
            'id' => $task['id'],
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Deferred task:');
        expect($output)->toContain($task['id']);
        expect($output)->toContain('Task to defer');
        expect($output)->toContain('Added to backlog: b-');

        // Verify task removed
        expect($this->taskService->find($task['id']))->toBeNull();

        // Verify added to backlog
        $backlogService->initialize();
        $all = $backlogService->all();
        expect($all->count())->toBe(1);
        $backlogItem = $all->first();
        expect($backlogItem['title'])->toBe('Task to defer');
        expect($backlogItem['description'])->toBe('Task description');
        // Backlog items don't have priority
        expect($backlogItem)->not->toHaveKey('priority');
    });

    it('defers task with partial ID', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task to defer']);
        $partialId = substr((string) $task['id'], 2, 3);

        Artisan::call('defer', [
            'id' => $partialId,
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Deferred task:');
        expect($output)->toContain('Added to backlog: b-');

        // Verify task removed
        expect($this->taskService->find($task['id']))->toBeNull();
    });

    it('outputs JSON when --json flag is used', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON task']);

        Artisan::call('defer', [
            'id' => $task['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['task_id'])->toBe($task['id']);
        expect($result['backlog_item']['id'])->toStartWith('b-');
        expect($result['backlog_item']['title'])->toBe('JSON task');
    });

    it('returns error when task not found', function (): void {
        $this->artisan('defer', [
            'id' => 'f-nonexistent',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('returns error when ID is not a task', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item = $backlogService->add('Backlog item');

        // When deferring a backlog item ID, it first tries to find it as a task
        // Since it doesn't exist in tasks, it returns "Task not found"
        $this->artisan('defer', [
            'id' => $item['id'],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain(sprintf("Task '%s' not found", $item['id']))
            ->assertExitCode(1);
    });
});
