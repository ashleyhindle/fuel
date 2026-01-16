<?php

use App\Enums\TaskStatus;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// Close Command Tests
describe('close command', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
    });

    it('marks a task as done with reason "closed"', function (): void {
        $task = $this->taskService->create(['title' => 'To close']);

        $this->artisan('close', ['ids' => [$task->short_id]])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain('Reason: closed')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Done);
        expect($updated->reason)->toBe('closed');
    });

    it('supports partial ID matching', function (): void {
        $task = $this->taskService->create(['title' => 'Partial ID task']);
        $partialId = substr((string) $task->short_id, 2, 3);

        $this->artisan('close', ['ids' => [$partialId]])
            ->expectsOutputToContain('Completed task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Done);
        expect($updated->reason)->toBe('closed');
    });

    it('shows error for non-existent task', function (): void {
        $this->artisan('close', ['ids' => ['nonexistent']])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $task = $this->taskService->create(['title' => 'JSON close task']);

        Artisan::call('close', [
            'ids' => [$task->short_id],
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['status'])->toBe('done');
        expect($result['reason'])->toBe('closed');
    });

    it('outputs reason in JSON when --json flag is used', function (): void {
        $task = $this->taskService->create(['title' => 'JSON task with reason']);

        Artisan::call('close', [
            'ids' => [$task->short_id],
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['status'])->toBe('done');
        expect($result['reason'])->toBe('closed');
    });

    it('marks task as done with --commit flag', function (): void {
        $task = $this->taskService->create(['title' => 'Task with commit']);
        $commitHash = 'abc123def456';

        $this->artisan('close', [
            'ids' => [$task->short_id],
            '--commit' => $commitHash,
        ])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain('Reason: closed')
            ->expectsOutputToContain('Commit: '.$commitHash)
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Done);
        expect($updated->reason)->toBe('closed');
        expect($updated->commit_hash)->toBe($commitHash);
    });

    it('outputs commit hash in JSON when --commit flag is used with --json', function (): void {
        $task = $this->taskService->create(['title' => 'JSON task with commit']);
        $commitHash = 'xyz789abc123';

        Artisan::call('close', [
            'ids' => [$task->short_id],
            '--commit' => $commitHash,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['status'])->toBe('done');
        expect($result['reason'])->toBe('closed');
        expect($result['commit_hash'])->toBe($commitHash);
    });

    it('marks multiple tasks as done with reason "closed"', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $task3 = $this->taskService->create(['title' => 'Task 3']);

        $this->artisan('close', [
            'ids' => [$task1->short_id, $task2->short_id, $task3->short_id],
        ])
            ->expectsOutputToContain('Completed task:')
            ->assertExitCode(0);

        expect($this->taskService->find($task1->short_id)->status)->toBe(TaskStatus::Done);
        expect($this->taskService->find($task1->short_id)->reason)->toBe('closed');
        expect($this->taskService->find($task2->short_id)->status)->toBe(TaskStatus::Done);
        expect($this->taskService->find($task2->short_id)->reason)->toBe('closed');
        expect($this->taskService->find($task3->short_id)->status)->toBe(TaskStatus::Done);
        expect($this->taskService->find($task3->short_id)->reason)->toBe('closed');
    });

    it('outputs multiple tasks as JSON array when multiple IDs provided', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        Artisan::call('close', [
            'ids' => [$task1->short_id, $task2->short_id],
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveCount(2);
        expect($result[0]['status'])->toBe('done');
        expect($result[0]['reason'])->toBe('closed');
        expect($result[1]['status'])->toBe('done');
        expect($result[1]['reason'])->toBe('closed');
        expect(collect($result)->pluck('short_id')->toArray())->toContain($task1->short_id, $task2->short_id);
    });

    it('handles partial failures when marking multiple tasks', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        $this->artisan('close', [
            'ids' => [$task1->short_id, 'nonexistent', $task2->short_id],
        ])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);

        expect($this->taskService->find($task1->short_id)->status)->toBe(TaskStatus::Done);
        expect($this->taskService->find($task1->short_id)->reason)->toBe('closed');
        expect($this->taskService->find($task2->short_id)->status)->toBe(TaskStatus::Done);
        expect($this->taskService->find($task2->short_id)->reason)->toBe('closed');
    });

    it('always sets reason to "closed" regardless of other options', function (): void {
        $task = $this->taskService->create(['title' => 'Task to close']);

        $this->artisan('close', [
            'ids' => [$task->short_id],
            '--commit' => 'abc123',
        ])
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->reason)->toBe('closed');
    });
});
