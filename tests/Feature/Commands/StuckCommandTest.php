<?php

use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

uses()->group('feature');
// =============================================================================
// stuck Command Tests
// =============================================================================

describe('stuck command', function (): void {
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

    it('shows no stuck tasks when empty', function (): void {
        Artisan::call('stuck', []);

        expect(Artisan::output())->toContain('No stuck tasks found');
    });

    it('shows only consumed tasks with non-zero exit codes', function (): void {

        // Create tasks with different consumed states
        $successTask = $this->taskService->create(['title' => 'Success task']);
        $this->taskService->update($successTask->short_id, [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 0,
        ]);

        $failedTask = $this->taskService->create(['title' => 'Failed task']);
        $this->taskService->update($failedTask->short_id, [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 1,
            'consumed_output' => 'Error: Something went wrong',
        ]);

        $notConsumedTask = $this->taskService->create(['title' => 'Not consumed task']);

        Artisan::call('stuck', []);
        $output = Artisan::output();

        expect($output)->toContain('Failed task');
        expect($output)->not->toContain('Success task');
        expect($output)->not->toContain('Not consumed task');
    });

    it('shows exit code and output for stuck tasks', function (): void {

        $task = $this->taskService->create(['title' => 'Stuck task']);
        $this->taskService->update($task->short_id, [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 42,
            'consumed_output' => 'Error message here',
        ]);

        Artisan::call('stuck', []);
        $output = Artisan::output();

        expect($output)->toContain('Stuck task');
        expect($output)->toContain('Reason:');
        expect($output)->toContain('Exit code 42');
        expect($output)->toContain('Error message here');
    });

    it('truncates long output', function (): void {

        $longOutput = str_repeat('x', 600); // 600 characters
        $task = $this->taskService->create(['title' => 'Task with long output']);
        $this->taskService->update($task->short_id, [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 1,
            'consumed_output' => $longOutput,
        ]);

        Artisan::call('stuck', []);
        $output = Artisan::output();

        expect($output)->toContain('Task with long output');
        expect($output)->toContain('...');
        // Should be truncated to ~500 chars
        expect(strlen($output))->toBeLessThan(700);
    });

    it('excludes tasks with zero exit code', function (): void {

        $task = $this->taskService->create(['title' => 'Successful task']);
        $this->taskService->update($task->short_id, [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 0,
        ]);

        Artisan::call('stuck', []);
        $output = Artisan::output();

        expect($output)->not->toContain('Successful task');
        expect($output)->toContain('No stuck tasks found');
    });

    it('excludes tasks without consumed flag', function (): void {

        $task = $this->taskService->create(['title' => 'Unconsumed task']);
        $this->taskService->update($task->short_id, [
            'consumed_exit_code' => 1,
        ]);

        Artisan::call('stuck', []);
        $output = Artisan::output();

        expect($output)->not->toContain('Unconsumed task');
    });

    it('excludes tasks without exit code', function (): void {

        $task = $this->taskService->create(['title' => 'Task without exit code']);
        $this->taskService->update($task->short_id, [
            'consumed' => true,
            'consumed_at' => date('c'),
        ]);

        Artisan::call('stuck', []);
        $output = Artisan::output();

        expect($output)->not->toContain('Task without exit code');
    });

    it('outputs JSON when --json flag is used', function (): void {

        $task = $this->taskService->create(['title' => 'Stuck task']);
        $this->taskService->update($task->short_id, [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 1,
            'consumed_output' => 'Error output',
        ]);

        Artisan::call('stuck', ['--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toHaveCount(1);
        expect($data[0]['short_id'])->toBe($task->short_id);
        expect($data[0]['consumed'])->toBeTrue();
        expect($data[0]['consumed_exit_code'])->toBe(1);
        expect($data[0]['consumed_output'])->toBe('Error output');
    });

    it('outputs empty array as JSON when no stuck tasks', function (): void {

        Artisan::call('stuck', ['--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toBeEmpty();
    });

    it('sorts stuck tasks by consumed_at descending', function (): void {

        $task1 = $this->taskService->create(['title' => 'First stuck task']);
        $this->taskService->update($task1->short_id, [
            'consumed' => true,
            'consumed_at' => date('c', time() - 100),
            'consumed_exit_code' => 1,
        ]);

        sleep(1);

        $task2 = $this->taskService->create(['title' => 'Second stuck task']);
        $this->taskService->update($task2->short_id, [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 2,
        ]);

        Artisan::call('stuck', []);
        $output = Artisan::output();

        // Most recent should appear first
        $pos1 = strpos($output, 'First stuck task');
        $pos2 = strpos($output, 'Second stuck task');
        expect($pos2)->toBeLessThan($pos1);
    });
});
