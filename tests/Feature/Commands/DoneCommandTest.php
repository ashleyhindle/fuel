<?php

use App\Enums\TaskStatus;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// Done Command Tests
describe('done command', function (): void {
    beforeEach(function (): void {
        $this->taskService = $this->app->make(TaskService::class);
    });

    it('marks a task as done', function (): void {
        $task = $this->taskService->create(['title' => 'To complete']);

        $this->artisan('done', ['ids' => [$task->short_id]])
            ->expectsOutputToContain('Completed task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Done);
    });

    it('supports partial ID matching', function (): void {
        $task = $this->taskService->create(['title' => 'Partial ID task']);
        $partialId = substr((string) $task->short_id, 2, 3); // Just 3 chars of the hash

        $this->artisan('done', ['ids' => [$partialId]])
            ->expectsOutputToContain('Completed task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Done);
    });

    it('shows error for non-existent task', function (): void {

        $this->artisan('done', ['ids' => ['nonexistent']])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $task = $this->taskService->create(['title' => 'JSON done task']);

        $this->artisan('done', ['ids' => [$task->short_id], '--json' => true])
            ->expectsOutputToContain('"status": "done"')
            ->assertExitCode(0);
    });

    it('outputs JSON error for non-existent task with --json flag', function (): void {

        $this->artisan('done', ['ids' => ['nonexistent'], '--json' => true])
            ->expectsOutputToContain('"error":')
            ->assertExitCode(1);
    });

    it('marks task as done with --reason flag', function (): void {
        $task = $this->taskService->create(['title' => 'Task with reason']);

        $this->artisan('done', [
            'ids' => [$task->short_id],
            '--reason' => 'Fixed the bug',
        ])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain('Reason: Fixed the bug')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Done);
        expect($updated->reason)->toBe('Fixed the bug');
    });

    it('outputs reason in JSON when --reason flag is used with --json', function (): void {
        $task = $this->taskService->create(['title' => 'JSON task with reason']);

        Artisan::call('done', [
            'ids' => [$task->short_id],
            '--reason' => 'Completed successfully',
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['status'])->toBe('done');
        expect($result['reason'])->toBe('Completed successfully');
    });

    it('does not add reason field when --reason is not provided', function (): void {
        $task = $this->taskService->create(['title' => 'Task without reason']);

        $this->artisan('done', ['ids' => [$task->short_id]])
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Done);
        expect($updated->reason)->toBeNull();
    });

    it('marks task as done with --commit flag', function (): void {
        $task = $this->taskService->create(['title' => 'Task with commit']);
        $commitHash = 'abc123def456';

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'model' => 'test-model',
        ]);

        $this->artisan('done', [
            'ids' => [$task->short_id],
            '--commit' => $commitHash,
        ])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain('Commit: '.$commitHash)
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Done);
        expect($updated->commit_hash)->toBe($commitHash);
    });

    it('stores commit hash on latest run when --commit is provided', function (): void {
        $task = $this->taskService->create(['title' => 'Task with run commit']);
        $commitHash = 'feed1234beef';

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'model' => 'test-model',
        ]);

        $this->artisan('done', [
            'ids' => [$task->short_id],
            '--commit' => $commitHash,
        ])
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->commit_hash)->toBe($commitHash);

        $latestRun = $runService->getLatestRun($task->short_id);
        expect($latestRun)->not->toBeNull();
        expect($latestRun->commit_hash)->toBe($commitHash);
    });

    it('outputs commit hash in JSON when --commit flag is used with --json', function (): void {
        $task = $this->taskService->create(['title' => 'JSON task with commit']);
        $commitHash = 'xyz789abc123';

        Artisan::call('done', [
            'ids' => [$task->short_id],
            '--commit' => $commitHash,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['status'])->toBe('done');
        expect($result['commit_hash'])->toBe($commitHash);
    });

    it('handles --commit when no run exists', function (): void {
        $task = $this->taskService->create(['title' => 'Task without run']);
        $commitHash = '1234deadbeef';

        $this->artisan('done', [
            'ids' => [$task->short_id],
            '--commit' => $commitHash,
        ])
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->commit_hash)->toBe($commitHash);
    });

    it('does not add commit_hash field when --commit is not provided', function (): void {
        $task = $this->taskService->create(['title' => 'Task without commit']);

        $this->artisan('done', ['ids' => [$task->short_id]])
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Done);
        expect($updated->commit_hash)->toBeNull();
    });

    it('can use both --reason and --commit flags together', function (): void {
        $task = $this->taskService->create(['title' => 'Task with both flags']);
        $commitHash = 'def456ghi789';

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'model' => 'test-model',
        ]);

        $this->artisan('done', [
            'ids' => [$task->short_id],
            '--reason' => 'Fixed the bug',
            '--commit' => $commitHash,
        ])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain('Reason: Fixed the bug')
            ->expectsOutputToContain('Commit: '.$commitHash)
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::Done);
        expect($updated->reason)->toBe('Fixed the bug');
        expect($updated->commit_hash)->toBe($commitHash);
    });

    it('marks multiple tasks as done', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $task3 = $this->taskService->create(['title' => 'Task 3']);

        $this->artisan('done', [
            'ids' => [$task1->short_id, $task2->short_id, $task3->short_id],
        ])
            ->expectsOutputToContain('Completed task:')
            ->assertExitCode(0);

        expect($this->taskService->find($task1->short_id)->status)->toBe(TaskStatus::Done);
        expect($this->taskService->find($task2->short_id)->status)->toBe(TaskStatus::Done);
        expect($this->taskService->find($task3->short_id)->status)->toBe(TaskStatus::Done);
    });

    it('outputs multiple tasks as JSON array when multiple IDs provided', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        Artisan::call('done', [
            'ids' => [$task1->short_id, $task2->short_id],
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveCount(2);
        expect($result[0]['status'])->toBe('done');
        expect($result[1]['status'])->toBe('done');
        expect(collect($result)->pluck('short_id')->toArray())->toContain($task1->short_id, $task2->short_id);
    });

    it('outputs single task as object when one ID provided with --json', function (): void {
        $task = $this->taskService->create(['title' => 'Single task']);

        Artisan::call('done', [
            'ids' => [$task->short_id],
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveKey('short_id');
        expect($result['short_id'])->toBe($task->short_id);
        expect($result['status'])->toBe('done');
    });

    it('handles partial failures when marking multiple tasks', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        $this->artisan('done', [
            'ids' => [$task1->short_id, 'nonexistent', $task2->short_id],
        ])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);

        // Task1 should be done
        expect($this->taskService->find($task1->short_id)->status)->toBe(TaskStatus::Done);
        // Task2 should be done
        expect($this->taskService->find($task2->short_id)->status)->toBe(TaskStatus::Done);
    });

    it('applies same reason to all tasks when --reason provided', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        $this->artisan('done', [
            'ids' => [$task1->short_id, $task2->short_id],
            '--reason' => 'Batch completion',
        ])
            ->assertExitCode(0);

        expect($this->taskService->find($task1->short_id)->reason)->toBe('Batch completion');
        expect($this->taskService->find($task2->short_id)->reason)->toBe('Batch completion');
    });

    it('supports partial IDs when marking multiple tasks', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $partialId1 = substr((string) $task1->short_id, 2, 3);
        $partialId2 = substr((string) $task2->short_id, 2, 3);

        $this->artisan('done', [
            'ids' => [$partialId1, $partialId2],
        ])
            ->assertExitCode(0);

        expect($this->taskService->find($task1->short_id)->status)->toBe(TaskStatus::Done);
        expect($this->taskService->find($task2->short_id)->status)->toBe(TaskStatus::Done);
    });
});
