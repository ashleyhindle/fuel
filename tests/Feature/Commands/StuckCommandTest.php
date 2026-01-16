<?php

use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

uses()->group('feature');
// =============================================================================
// stuck Command Tests
// =============================================================================

describe('stuck command', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
        $this->runService = app(RunService::class);
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
        ]);
        $this->runService->logRun($successTask->short_id, ['agent' => 'test', 'exit_code' => 0]);

        $failedTask = $this->taskService->create(['title' => 'Failed task']);
        $this->taskService->update($failedTask->short_id, [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_output' => 'Error: Something went wrong',
        ]);
        $this->runService->logRun($failedTask->short_id, ['agent' => 'test', 'exit_code' => 1]);

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
            'consumed_output' => 'Error message here',
        ]);
        $this->runService->logRun($task->short_id, ['agent' => 'test', 'exit_code' => 42]);

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
            'consumed_output' => $longOutput,
        ]);
        $this->runService->logRun($task->short_id, ['agent' => 'test', 'exit_code' => 1]);

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
        ]);
        $this->runService->logRun($task->short_id, ['agent' => 'test', 'exit_code' => 0]);

        Artisan::call('stuck', []);
        $output = Artisan::output();

        expect($output)->not->toContain('Successful task');
        expect($output)->toContain('No stuck tasks found');
    });

    it('excludes tasks without consumed flag', function (): void {

        $task = $this->taskService->create(['title' => 'Unconsumed task']);
        // Create a run with exit code but don't mark task as consumed
        $this->runService->logRun($task->short_id, ['agent' => 'test', 'exit_code' => 1]);

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
            'consumed_output' => 'Error output',
        ]);
        $this->runService->logRun($task->short_id, ['agent' => 'test', 'exit_code' => 1]);

        Artisan::call('stuck', ['--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toHaveCount(1);
        expect($data[0]['short_id'])->toBe($task->short_id);
        expect($data[0]['consumed'])->toBeTrue();
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
        ]);
        $this->runService->logRun($task1->short_id, ['agent' => 'test', 'exit_code' => 1]);

        sleep(1);

        $task2 = $this->taskService->create(['title' => 'Second stuck task']);
        $this->taskService->update($task2->short_id, [
            'consumed' => true,
            'consumed_at' => date('c'),
        ]);
        $this->runService->logRun($task2->short_id, ['agent' => 'test', 'exit_code' => 2]);

        Artisan::call('stuck', []);
        $output = Artisan::output();

        // Most recent should appear first
        $pos1 = strpos($output, 'First stuck task');
        $pos2 = strpos($output, 'Second stuck task');
        expect($pos2)->toBeLessThan($pos1);
    });

    it('excludes tasks with done status even if they have non-zero exit codes', function (): void {

        $doneTask = $this->taskService->create(['title' => 'Completed task']);
        $this->taskService->update($doneTask->short_id, [
            'consumed' => true,
            'consumed_at' => date('c'),
            'status' => 'done',
        ]);
        $this->runService->logRun($doneTask->short_id, ['agent' => 'test', 'exit_code' => -1]);

        Artisan::call('stuck', []);
        $output = Artisan::output();

        expect($output)->not->toContain('Completed task');
        expect($output)->toContain('No stuck tasks found');
    });

    it('excludes tasks with cancelled status even if they have non-zero exit codes', function (): void {

        $cancelledTask = $this->taskService->create(['title' => 'Cancelled task']);
        $this->taskService->update($cancelledTask->short_id, [
            'consumed' => true,
            'consumed_at' => date('c'),
            'status' => 'cancelled',
        ]);
        $this->runService->logRun($cancelledTask->short_id, ['agent' => 'test', 'exit_code' => 1]);

        Artisan::call('stuck', []);
        $output = Artisan::output();

        expect($output)->not->toContain('Cancelled task');
        expect($output)->toContain('No stuck tasks found');
    });
});
