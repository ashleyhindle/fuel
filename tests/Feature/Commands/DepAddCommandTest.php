<?php

use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// =============================================================================
// dep:add Command Tests
// =============================================================================
describe('dep:add command', function (): void {
    beforeEach(function (): void {
        $this->taskService = $this->app->make(TaskService::class);
    });

    it('adds dependency via CLI', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        $this->artisan('dep:add', [
            'from' => $blocked->short_id,
            'to' => $blocker->short_id,
        ])
            ->expectsOutputToContain('Added dependency')
            ->assertExitCode(0);

        // Verify blocker was added to blocked_by array
        $updated = $this->taskService->find($blocked->short_id);
        expect($updated->blocked_by)->toHaveCount(1);
        expect($updated->blocked_by)->toContain($blocker->short_id);
    });

    it('dep:add outputs JSON when --json flag is used', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        Artisan::call('dep:add', [
            'from' => $blocked->short_id,
            'to' => $blocker->short_id,
            '--json' => true,
        ]);

        $output = Artisan::output();
        expect($output)->toContain($blocked->short_id);
        expect($output)->toContain('blocked_by');
    });

    it('dep:add shows error for non-existent task', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);

        $this->artisan('dep:add', [
            'from' => 'nonexistent',
            'to' => $blocker->short_id,
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('dep:add shows error for cycle detection', function (): void {
        $taskA = $this->taskService->create(['title' => 'Task A']);
        $taskB = $this->taskService->create(['title' => 'Task B']);

        // A depends on B
        $this->taskService->addDependency($taskA->short_id, $taskB->short_id);

        // Try to make B depend on A (cycle)
        $this->artisan('dep:add', [
            'from' => $taskB->short_id,
            'to' => $taskA->short_id,
        ])
            ->expectsOutputToContain('Circular dependency')
            ->assertExitCode(1);
    });

    it('dep:add supports partial ID matching', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Use partial IDs (just the hash part)
        $blockerPartial = substr((string) $blocker->short_id, 2, 3);
        $blockedPartial = substr((string) $blocked->short_id, 2, 3);

        $this->artisan('dep:add', [
            'from' => $blockedPartial,
            'to' => $blockerPartial,
        ])
            ->expectsOutputToContain('Added dependency')
            ->assertExitCode(0);

        // Verify blocker was added to blocked_by array using full ID
        $updated = $this->taskService->find($blocked->short_id);
        expect($updated->blocked_by)->toHaveCount(1);
    });
});
