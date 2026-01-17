<?php

use App\Enums\TaskStatus;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

describe('selfguided:continue command', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
    });

    it('signals continue for a selfguided task without reopening (onSuccess does that)', function (): void {
        $task = $this->taskService->create([
            'title' => 'Selfguided task',
            'type' => 'selfguided',
            'selfguided_iteration' => 5,
            'selfguided_stuck_count' => 3,
        ]);
        // Task stays in_progress to simulate being in a run
        $this->taskService->start($task->short_id);

        $this->artisan('selfguided:continue', ['id' => $task->short_id])
            ->expectsOutputToContain('Continuing selfguided task after iteration 6')
            ->assertExitCode(0);

        // Command no longer changes status/iteration/stuck_count - onSuccess() does that
        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::InProgress);
        expect($updated->selfguided_iteration)->toBe(5); // Unchanged - onSuccess increments
        expect($updated->selfguided_stuck_count)->toBe(3); // Unchanged - onSuccess resets
    });

    it('reports iteration 1 when selfguided_iteration is null', function (): void {
        $task = $this->taskService->create([
            'title' => 'New selfguided task',
            'type' => 'selfguided',
        ]);
        $this->taskService->start($task->short_id);

        $this->artisan('selfguided:continue', ['id' => $task->short_id])
            ->expectsOutputToContain('Continuing selfguided task after iteration 1')
            ->assertExitCode(0);

        // Iteration not changed by command - onSuccess does that
        // Note: selfguided_iteration is cast to integer, so null becomes 0
        $updated = $this->taskService->find($task->short_id);
        expect($updated->selfguided_iteration)->toBe(0);
    });

    it('creates needs-human task when max iterations reached', function (): void {
        $task = $this->taskService->create([
            'title' => 'Task at max iterations',
            'type' => 'selfguided',
            'selfguided_iteration' => 49,
        ]);
        $this->taskService->done($task->short_id);

        $this->artisan('selfguided:continue', ['id' => $task->short_id])
            ->expectsOutputToContain('Max iterations (50) reached')
            ->expectsOutputToContain('Created needs-human task')
            ->assertExitCode(0);

        $allTasks = $this->taskService->all();
        $needsHumanTask = $allTasks->first(fn ($t): bool => str_starts_with((string) $t->title, 'Max iterations reached'));

        expect($needsHumanTask)->not->toBeNull();
        expect($needsHumanTask->labels)->toContain('needs-human');

        // Verify dependency was added
        $updated = $this->taskService->find($task->short_id);
        expect($updated->blocked_by)->toContain($needsHumanTask->short_id);
    });

    it('appends notes to task description when provided', function (): void {
        $task = $this->taskService->create([
            'title' => 'Task with notes',
            'description' => 'Original description',
            'type' => 'selfguided',
            'selfguided_iteration' => 2,
        ]);
        $this->taskService->done($task->short_id);

        $this->artisan('selfguided:continue', [
            'id' => $task->short_id,
            '--notes' => 'Made some progress on feature X',
        ])
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->description)->toContain('Original description');
        expect($updated->description)->toContain('--- Iteration 3 notes ---');
        expect($updated->description)->toContain('Made some progress on feature X');
    });

    it('stores commit hash on latest run when --commit is provided', function (): void {
        $task = $this->taskService->create([
            'title' => 'Selfguided task with commit',
            'type' => 'selfguided',
            'selfguided_iteration' => 1,
        ]);
        $this->taskService->done($task->short_id);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'model' => 'test-model',
        ]);

        $commitHash = 'abc123def456';
        $this->artisan('selfguided:continue', [
            'id' => $task->short_id,
            '--commit' => $commitHash,
        ])
            ->assertExitCode(0);

        $latestRun = $runService->getLatestRun($task->short_id);
        expect($latestRun)->not->toBeNull();
        expect($latestRun->commit_hash)->toBe($commitHash);
    });

    it('continues without --commit and leaves run commit_hash null', function (): void {
        $task = $this->taskService->create([
            'title' => 'Selfguided task without commit',
            'type' => 'selfguided',
            'selfguided_iteration' => 2,
        ]);
        $this->taskService->done($task->short_id);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'model' => 'test-model',
        ]);

        $this->artisan('selfguided:continue', ['id' => $task->short_id])
            ->assertExitCode(0);

        $latestRun = $runService->getLatestRun($task->short_id);
        expect($latestRun)->not->toBeNull();
        expect($latestRun->commit_hash)->toBeNull();
    });

    it('fails when task is not selfguided', function (): void {
        $task = $this->taskService->create([
            'title' => 'Regular task',
            'agent' => 'haiku',
        ]);
        $this->taskService->done($task->short_id);

        $this->artisan('selfguided:continue', ['id' => $task->short_id])
            ->expectsOutputToContain('is not a selfguided task')
            ->assertExitCode(1);
    });

    it('fails when task does not exist', function (): void {
        $this->artisan('selfguided:continue', ['id' => 'nonexistent'])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('supports partial ID matching', function (): void {
        $task = $this->taskService->create([
            'title' => 'Partial ID task',
            'type' => 'selfguided',
            'selfguided_iteration' => 1,
        ]);
        $this->taskService->start($task->short_id);
        $partialId = substr((string) $task->short_id, 2, 3);

        $this->artisan('selfguided:continue', ['id' => $partialId])
            ->expectsOutputToContain('Continuing selfguided task after iteration 2')
            ->assertExitCode(0);

        // Iteration not changed by command
        $updated = $this->taskService->find($task->short_id);
        expect($updated->selfguided_iteration)->toBe(1);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $task = $this->taskService->create([
            'title' => 'JSON task',
            'type' => 'selfguided',
            'selfguided_iteration' => 3,
            'selfguided_stuck_count' => 2,
        ]);
        $this->taskService->start($task->short_id);

        Artisan::call('selfguided:continue', [
            'id' => $task->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['short_id'])->toBe($task->short_id);
        // Command no longer changes status/iteration/stuck_count - onSuccess does that
        expect($result['status'])->toBe('in_progress');
        expect($result['selfguided_iteration'])->toBe(3);
        expect($result['selfguided_stuck_count'])->toBe(2);
    });

    it('outputs JSON with max iterations info when limit reached', function (): void {
        $task = $this->taskService->create([
            'title' => 'Max iteration JSON task',
            'type' => 'selfguided',
            'selfguided_iteration' => 49,
        ]);
        $this->taskService->done($task->short_id);

        Artisan::call('selfguided:continue', [
            'id' => $task->short_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['status'])->toBe('max_iterations_reached');
        expect($result['iteration'])->toBe(50);
        expect($result['needs_human_task'])->toBeArray();
        expect($result['needs_human_task']['labels'])->toContain('needs-human');
    });

    it('does not reset stuck count (onSuccess does that)', function (): void {
        $task = $this->taskService->create([
            'title' => 'Stuck task',
            'type' => 'selfguided',
            'selfguided_iteration' => 10,
            'selfguided_stuck_count' => 5,
        ]);
        $this->taskService->start($task->short_id);

        $this->artisan('selfguided:continue', ['id' => $task->short_id])
            ->assertExitCode(0);

        // Command no longer resets stuck count - onSuccess does that
        $updated = $this->taskService->find($task->short_id);
        expect($updated->selfguided_stuck_count)->toBe(5);
    });

    it('handles task without selfguided type', function (): void {
        $task = $this->taskService->create([
            'title' => 'Task without selfguided type',
        ]);
        $this->taskService->done($task->short_id);

        $this->artisan('selfguided:continue', ['id' => $task->short_id])
            ->expectsOutputToContain("type='task'")
            ->assertExitCode(1);
    });
});
