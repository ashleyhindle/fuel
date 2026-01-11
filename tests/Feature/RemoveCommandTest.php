<?php

use App\Commands\RemoveCommand;
use App\Services\BacklogService;
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

        expect($result)->toHaveKeys(['id', 'type', 'deleted']);
        expect($result['id'])->toBe($task['id']);
        expect($result['type'])->toBe('task');
        expect($result['deleted'])->toBeArray();
        expect($result['deleted']['id'])->toBe($task['id']);
    });

    it('outputs JSON when --json flag is used for backlog deletion', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item = $backlogService->add('Backlog item to delete', 'Description');

        Artisan::call('remove', [
            'id' => $item['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
            '--force' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toHaveKeys(['id', 'type', 'deleted']);
        expect($result['id'])->toBe($item['id']);
        expect($result['type'])->toBe('backlog');
        expect($result['deleted'])->toBeArray();
        expect($result['deleted']['id'])->toBe($item['id']);
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
            $this->app->make(BacklogService::class),
            $this->app->make(DatabaseService::class)
        );

        expect($exitCode)->toBe(0);
        // Verify task was deleted (should not exist anymore)
        expect($this->taskService->find($task['id']))->toBeNull();
    });

    it('skips confirmation for backlog deletion in non-interactive mode', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item = $backlogService->add('Backlog item to delete', 'Description');

        // Create command instance and set input to non-interactive
        $command = $this->app->make(RemoveCommand::class);
        $command->setLaravel($this->app);

        $input = new ArrayInput([
            'id' => $item['id'],
            '--cwd' => $this->tempDir,
        ], $command->getDefinition());
        $input->setInteractive(false);

        $bufferedOutput = new BufferedOutput;
        $output = new OutputStyle($input, $bufferedOutput);
        $command->setInput($input);
        $command->setOutput($output);

        $exitCode = $command->handle(
            $this->app->make(FuelContext::class),
            $this->app->make(TaskService::class),
            $backlogService,
            $this->app->make(DatabaseService::class)
        );

        expect($exitCode)->toBe(0);
        // Verify backlog item was deleted (should not exist anymore)
        expect($backlogService->find($item['id']))->toBeNull();
    });
});

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

    it('deletes a backlog item with --force flag', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item = $backlogService->add('Backlog item to delete', 'Description');

        Artisan::call('remove', ['id' => $item['id'], '--force' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Deleted backlog item:');
        expect($output)->toContain($item['id']);

        // Verify backlog item is deleted
        expect($backlogService->find($item['id']))->toBeNull();
    });

    it('outputs JSON when --json flag is used for task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task to delete']);

        Artisan::call('remove', [
            'id' => $task['id'],
            '--force' => true,
            '--json' => true,
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('"type": "task"');
        expect($output)->toContain('"id": "'.$task['id'].'"');
        expect($output)->toContain('"deleted"');
    });

    it('outputs JSON when --json flag is used for backlog item', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item = $backlogService->add('Backlog item to delete', 'Description');

        Artisan::call('remove', [
            'id' => $item['id'],
            '--force' => true,
            '--json' => true,
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('"type": "backlog"');
        expect($output)->toContain('"id": "'.$item['id'].'"');
        expect($output)->toContain('"deleted"');
    });

    it('returns error when task not found', function (): void {
        $this->artisan('remove', ['id' => 'f-nonexistent', '--force' => true, '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('returns error when backlog item not found', function (): void {
        $this->artisan('remove', ['id' => 'b-nonexistent', '--force' => true, '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Backlog item 'b-nonexistent' not found")
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

    it('supports partial ID matching for backlog items', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item = $backlogService->add('Backlog item to delete', 'Description');

        // Use partial ID (first 5 chars after b-)
        $partialId = substr((string) $item['id'], 2, 5);

        Artisan::call('remove', ['id' => $partialId, '--force' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Deleted backlog item:');

        // Verify backlog item is deleted
        expect($backlogService->find($item['id']))->toBeNull();
    });

});
