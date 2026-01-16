<?php

use App\Enums\TaskStatus;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

describe('start command', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
    });

    it('sets status to in_progress', function (): void {
        $task = $this->taskService->create(['title' => 'Task to start']);

        $this->artisan('start', ['id' => $task->short_id])
            ->expectsOutputToContain('Started task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::InProgress);
    });

    it('excludes task from ready() output', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        // Start task1
        $this->artisan('start', ['id' => $task1->short_id])
            ->assertExitCode(0);

        // Task1 should not appear in ready output
        $this->artisan('ready', [])
            ->expectsOutputToContain('Task 2')
            ->doesntExpectOutputToContain('Task 1')
            ->assertExitCode(0);
    });

    it('supports partial ID matching', function (): void {
        $task = $this->taskService->create(['title' => 'Partial ID task']);
        $partialId = substr((string) $task->short_id, 2, 3); // Just 3 chars of the hash

        $this->artisan('start', ['id' => $partialId])
            ->expectsOutputToContain('Started task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task->short_id);
        expect($updated->status)->toBe(TaskStatus::InProgress);
    });

    it('returns JSON when --json flag used', function (): void {
        $task = $this->taskService->create(['title' => 'JSON start task']);

        Artisan::call('start', ['id' => $task->short_id, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['short_id'])->toBe($task->short_id);
        expect($result['status'])->toBe('in_progress');
        expect($result['title'])->toBe('JSON start task');
    });

    it('handles invalid IDs gracefully', function (): void {

        $this->artisan('start', ['id' => 'nonexistent'])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('outputs JSON error for invalid ID with --json flag', function (): void {

        Artisan::call('start', ['id' => 'nonexistent', '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveKey('error');
        expect($result['error'])->toContain('not found');
    });
});
