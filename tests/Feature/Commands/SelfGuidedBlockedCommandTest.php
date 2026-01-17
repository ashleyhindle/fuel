<?php

use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// =============================================================================
// selfguided:blocked Command Tests
// =============================================================================
describe('selfguided:blocked command', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
    });

    it('creates needs-human task and adds dependency', function (): void {
        $selfguidedTask = $this->taskService->create([
            'title' => 'Self-guided task',
            'type' => 'selfguided',
        ]);

        Artisan::call('selfguided:blocked', [
            'id' => $selfguidedTask->short_id,
            '--reason' => 'Need credentials',
        ]);

        $output = Artisan::output();
        expect($output)->toContain('Created needs-human task');

        // Verify needs-human task was created
        $tasks = $this->taskService->all();
        $needsHumanTask = $tasks->filter(fn ($task): bool => str_contains((string) $task->title, 'Blocked: Need credentials'))->first();

        expect($needsHumanTask)->not->toBeNull();
        expect($needsHumanTask->labels)->toContain('needs-human');
        expect($needsHumanTask->description)->toContain('Need credentials');

        // Verify dependency was added
        $updatedSelfguidedTask = $this->taskService->find($selfguidedTask->short_id);
        expect($updatedSelfguidedTask->blocked_by)->toHaveCount(1);
        expect($updatedSelfguidedTask->blocked_by)->toContain($needsHumanTask->short_id);
    });

    it('uses task title when no reason provided', function (): void {
        $selfguidedTask = $this->taskService->create([
            'title' => 'My selfguided task',
            'type' => 'selfguided',
        ]);

        $this->artisan('selfguided:blocked', [
            'id' => $selfguidedTask->short_id,
        ])
            ->expectsOutputToContain('Created needs-human task')
            ->assertExitCode(0);

        // Verify needs-human task title uses original task title
        $tasks = $this->taskService->all();
        $needsHumanTask = $tasks->filter(fn ($task): bool => str_contains((string) $task->title, 'Blocked: My selfguided task'))->first();

        expect($needsHumanTask)->not->toBeNull();
    });

    it('outputs JSON when --json flag is used', function (): void {
        $selfguidedTask = $this->taskService->create([
            'title' => 'Self-guided task',
            'type' => 'selfguided',
        ]);

        Artisan::call('selfguided:blocked', [
            'id' => $selfguidedTask->short_id,
            '--reason' => 'Need approval',
            '--json' => true,
        ]);

        $output = Artisan::output();
        expect($output)->toContain('needs_human_task');
        expect($output)->toContain('selfguided_task');
        expect($output)->toContain('blocked');
    });

    it('shows error for non-existent task', function (): void {
        $this->artisan('selfguided:blocked', [
            'id' => 'nonexistent',
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('shows error for non-selfguided task', function (): void {
        $regularTask = $this->taskService->create([
            'title' => 'Regular task',
        ]);

        $this->artisan('selfguided:blocked', [
            'id' => $regularTask->short_id,
        ])
            ->expectsOutputToContain('not a self-guided task')
            ->assertExitCode(1);
    });

    it('supports partial ID matching', function (): void {
        $selfguidedTask = $this->taskService->create([
            'title' => 'Self-guided task',
            'type' => 'selfguided',
        ]);

        $partialId = substr((string) $selfguidedTask->short_id, 2, 3);

        $this->artisan('selfguided:blocked', [
            'id' => $partialId,
            '--reason' => 'Testing partial ID',
        ])
            ->expectsOutputToContain('Created needs-human task')
            ->assertExitCode(0);
    });
});
