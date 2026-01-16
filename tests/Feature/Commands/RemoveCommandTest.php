<?php

use App\Commands\RemoveCommand;
use App\Enums\TaskStatus;
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
        $this->taskService = app(TaskService::class);
    });

    it('soft deletes a task by setting status to cancelled', function (): void {
        $task = $this->taskService->create(['title' => 'Task to delete']);

        Artisan::call('remove', ['id' => $task->short_id]);
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
            'id' => $task->short_id,
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
            'id' => $task->short_id,
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
        $this->artisan('remove', ['id' => 'f-nonexistent'])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('supports partial ID matching for tasks', function (): void {
        $task = $this->taskService->create(['title' => 'Task to delete']);

        // Use partial ID (first 5 chars after f-)
        $partialId = substr((string) $task->short_id, 2, 5);

        $this->artisan('remove', ['id' => $partialId])
            ->expectsOutputToContain('Deleted task:')
            ->assertExitCode(0);

        // Verify task still exists but is cancelled (soft delete)
        $deletedTask = $this->taskService->find($task->short_id);
        expect($deletedTask)->not->toBeNull();
        expect($deletedTask->status)->toBe(TaskStatus::Cancelled);
    });
});
