<?php

use App\Commands\RemoveCommand;
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

        $databaseService = new DatabaseService($context->getDatabasePath());
        $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);

        $this->app->singleton(TaskService::class, fn (): TaskService => new TaskService($databaseService));

        $this->app->singleton(RunService::class, fn (): RunService => new RunService($databaseService));

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

    it('deletes a task with --force flag', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task to delete']);

        Artisan::call('remove', ['id' => $task['id'], '--force' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Deleted task:');
        expect($output)->toContain($task['id']);

        // Verify task is deleted
        expect($this->taskService->find($task['id']))->toBeNull();
    });

    it('outputs JSON when --json flag is used for task deletion', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task to delete']);

        Artisan::call('remove', [
            'id' => $task['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
            '--force' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toHaveKeys(['id', 'deleted']);
        expect($result['id'])->toBe($task['id']);
        expect($result['deleted'])->toBeArray();
        expect($result['deleted']['id'])->toBe($task['id']);
    });

    it('skips confirmation for task deletion in non-interactive mode', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task to delete']);

        // Create command instance and set input to non-interactive
        $command = $this->app->make(RemoveCommand::class);
        $command->setLaravel($this->app);

        $input = new ArrayInput([
            'id' => $task['id'],
            '--cwd' => $this->tempDir,
        ], $command->getDefinition());
        $input->setInteractive(false);

        $bufferedOutput = new BufferedOutput;
        $output = new OutputStyle($input, $bufferedOutput);
        $command->setInput($input);
        $command->setOutput($output);

        $exitCode = $command->handle(
            $this->app->make(FuelContext::class),
            $this->taskService,
            $this->app->make(DatabaseService::class)
        );

        expect($exitCode)->toBe(0);
        // Verify task was deleted (should not exist anymore)
        expect($this->taskService->find($task['id']))->toBeNull();
    });

    it('returns error when task not found', function (): void {
        $this->artisan('remove', ['id' => 'f-nonexistent', '--force' => true, '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('supports partial ID matching for tasks', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task to delete']);

        // Use partial ID (first 5 chars after f-)
        $partialId = substr((string) $task['id'], 2, 5);

        $this->artisan('remove', ['id' => $partialId, '--force' => true, '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Deleted task:')
            ->assertExitCode(0);

        // Verify task is deleted
        expect($this->taskService->find($task['id']))->toBeNull();
    });
});
