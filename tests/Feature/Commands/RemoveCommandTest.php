<?php

use App\Commands\RemoveCommand;
use App\Enums\TaskStatus;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// =============================================================================
// remove Command Tests
// =============================================================================

describe('remove command', function (): void {
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

    it('soft deletes a task by setting status to cancelled', function (): void {
        $task = $this->taskService->create(['title' => 'Task to delete']);

        Artisan::call('remove', ['ids' => [$task->short_id]]);
        $output = Artisan::output();

        expect($output)->toContain('Deleted task:');
        expect($output)->toContain($task->short_id);

        // Verify task still exists but is cancelled (soft delete)
        $deletedTask = $this->taskService->find($task->short_id);
        expect($deletedTask)->not->toBeNull();
        expect($deletedTask->status)->toBe(TaskStatus::Cancelled);
    });

    it('outputs JSON when --json flag is used for task deletion', function (): void {
        $task = $this->taskService->create(['title' => 'Task to delete']);

        Artisan::call('remove', [
            'ids' => [$task->short_id],
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toHaveKeys(['short_id', 'deleted']);
        expect($result['short_id'])->toBe($task->short_id);
        expect($result['deleted'])->toBeArray();
        expect($result['deleted']['short_id'])->toBe($task->short_id);
    });

    it('soft deletes in non-interactive mode', function (): void {
        $task = $this->taskService->create(['title' => 'Task to delete']);

        // Create command instance and set input to non-interactive
        $command = $this->app->make(RemoveCommand::class);
        $command->setLaravel($this->app);

        $input = new ArrayInput([
            'ids' => [$task->short_id],
        ], $command->getDefinition());
        $input->setInteractive(false);

        $bufferedOutput = new BufferedOutput;
        $output = new OutputStyle($input, $bufferedOutput);
        $command->setInput($input);
        $command->setOutput($output);

        $exitCode = $command->handle(
            $this->taskService
        );

        expect($exitCode)->toBe(0);
        // Verify task still exists but is cancelled (soft delete)
        $deletedTask = $this->taskService->find($task->short_id);
        expect($deletedTask)->not->toBeNull();
        expect($deletedTask->status)->toBe(TaskStatus::Cancelled);
    });

    it('returns error when task not found', function (): void {
        $this->artisan('remove', ['ids' => ['f-nonexistent']])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('supports partial ID matching for tasks', function (): void {
        $task = $this->taskService->create(['title' => 'Task to delete']);

        // Use partial ID (first 5 chars after f-)
        $partialId = substr((string) $task->short_id, 2, 5);

        $this->artisan('remove', ['ids' => [$partialId]])
            ->expectsOutputToContain('Deleted task:')
            ->assertExitCode(0);

        // Verify task still exists but is cancelled (soft delete)
        $deletedTask = $this->taskService->find($task->short_id);
        expect($deletedTask)->not->toBeNull();
        expect($deletedTask->status)->toBe(TaskStatus::Cancelled);
    });

    it('deletes multiple tasks', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $task3 = $this->taskService->create(['title' => 'Task 3']);

        $this->artisan('remove', [
            'ids' => [$task1->short_id, $task2->short_id, $task3->short_id],
        ])
            ->expectsOutputToContain('Deleted task:')
            ->assertExitCode(0);

        // Verify all tasks are cancelled (soft delete)
        expect($this->taskService->find($task1->short_id)->status)->toBe(TaskStatus::Cancelled);
        expect($this->taskService->find($task2->short_id)->status)->toBe(TaskStatus::Cancelled);
        expect($this->taskService->find($task3->short_id)->status)->toBe(TaskStatus::Cancelled);
    });

    it('outputs multiple tasks as JSON array when multiple IDs provided', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        Artisan::call('remove', [
            'ids' => [$task1->short_id, $task2->short_id],
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveCount(2);
        expect($result[0]['status'])->toBe('cancelled');
        expect($result[1]['status'])->toBe('cancelled');
        expect(collect($result)->pluck('short_id')->toArray())->toContain($task1->short_id, $task2->short_id);
    });

    it('outputs single task as object when one ID provided with --json', function (): void {
        $task = $this->taskService->create(['title' => 'Single task']);

        Artisan::call('remove', [
            'ids' => [$task->short_id],
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveKeys(['short_id', 'deleted']);
        expect($result['short_id'])->toBe($task->short_id);
    });

    it('handles partial success when some tasks are not found', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        $this->artisan('remove', [
            'ids' => [$task1->short_id, 'f-nonexistent', $task2->short_id],
        ])
            ->expectsOutputToContain('Deleted task:')
            ->expectsOutputToContain("Task 'f-nonexistent'")
            ->assertExitCode(1); // Should fail because some tasks failed

        // Verify successful tasks are deleted
        expect($this->taskService->find($task1->short_id)->status)->toBe(TaskStatus::Cancelled);
        expect($this->taskService->find($task2->short_id)->status)->toBe(TaskStatus::Cancelled);
    });
});
