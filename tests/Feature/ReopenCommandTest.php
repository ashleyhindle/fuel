<?php

use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/Concerns/CommandTestSetup.php';

beforeEach($beforeEach);

afterEach($afterEach);

describe('reopen command', function (): void {
    it('reopens a closed task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'To reopen']);
        $this->taskService->done($task['id']);

        $this->artisan('reopen', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('open');
    });

    it('supports partial ID matching', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Partial ID task']);
        $this->taskService->done($task['id']);
        $partialId = substr((string) $task['id'], 2, 3); // Just 3 chars of the hash

        $this->artisan('reopen', ['ids' => [$partialId], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('open');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON reopen task']);
        $this->taskService->done($task['id']);

        Artisan::call('reopen', [
            'ids' => [$task['id']],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['id'])->toBe($task['id']);
        expect($result['status'])->toBe('open');
        expect($result['title'])->toBe('JSON reopen task');
    });

    it('removes reason when reopening a task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task with reason']);
        $this->taskService->done($task['id'], 'Fixed the bug');

        $closedTask = $this->taskService->find($task['id']);
        expect($closedTask['reason'])->toBe('Fixed the bug');

        $this->artisan('reopen', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->assertExitCode(0);

        $reopenedTask = $this->taskService->find($task['id']);
        expect($reopenedTask['status'])->toBe('open');
        expect($reopenedTask)->not->toHaveKey('reason');
    });

    it('clears consumed fields when reopening a task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task with consumed fields']);
        $this->taskService->done($task['id']);

        // Manually add consumed fields (simulating a consumed task)
        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_at' => '2026-01-07T10:00:00+00:00',
            'consumed_exit_code' => 1,
            'consumed_output' => 'Some error output',
        ]);

        $closedTask = $this->taskService->find($task['id']);
        expect($closedTask['consumed'])->toBeTrue();
        expect($closedTask['consumed_at'])->toBe('2026-01-07T10:00:00+00:00');
        expect($closedTask['consumed_exit_code'])->toBe(1);
        expect($closedTask['consumed_output'])->toBe('Some error output');

        $this->artisan('reopen', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->assertExitCode(0);

        $reopenedTask = $this->taskService->find($task['id']);
        expect($reopenedTask['status'])->toBe('open');
        expect($reopenedTask)->not->toHaveKey('consumed');
        expect($reopenedTask)->not->toHaveKey('consumed_at');
        expect($reopenedTask)->not->toHaveKey('consumed_exit_code');
        expect($reopenedTask)->not->toHaveKey('consumed_output');
    });

    it('fails when task is not found', function (): void {
        $this->taskService->initialize();

        $this->artisan('reopen', ['ids' => ['nonexistent'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('fails when task is open', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Open task']);

        $this->artisan('reopen', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('is not closed, in_progress, or review')
            ->assertExitCode(1);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('open');
    });

    it('reopens an in_progress task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'In progress task']);
        $this->taskService->start($task['id']);

        $this->artisan('reopen', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('open');
    });

    it('reopens multiple tasks', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $task3 = $this->taskService->create(['title' => 'Task 3']);
        $this->taskService->done($task1['id']);
        $this->taskService->done($task2['id']);
        $this->taskService->done($task3['id']);

        $this->artisan('reopen', [
            'ids' => [$task1['id'], $task2['id'], $task3['id']],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        expect($this->taskService->find($task1['id'])['status'])->toBe('open');
        expect($this->taskService->find($task2['id'])['status'])->toBe('open');
        expect($this->taskService->find($task3['id'])['status'])->toBe('open');
    });

    it('outputs multiple tasks as JSON array when multiple IDs provided', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->done($task1['id']);
        $this->taskService->done($task2['id']);

        Artisan::call('reopen', [
            'ids' => [$task1['id'], $task2['id']],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveCount(2);
        expect($result[0]['status'])->toBe('open');
        expect($result[1]['status'])->toBe('open');
        expect(collect($result)->pluck('id')->toArray())->toContain($task1['id'], $task2['id']);
    });

    it('handles partial failures when reopening multiple tasks', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->done($task1['id']);
        $this->taskService->done($task2['id']);

        $this->artisan('reopen', [
            'ids' => [$task1['id'], 'nonexistent', $task2['id']],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Reopened task:')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);

        // Task1 should be reopened
        expect($this->taskService->find($task1['id'])['status'])->toBe('open');
        // Task2 should be reopened
        expect($this->taskService->find($task2['id'])['status'])->toBe('open');
    });

    it('supports partial IDs when reopening multiple tasks', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->done($task1['id']);
        $this->taskService->done($task2['id']);

        $partialId1 = substr((string) $task1['id'], 2, 3);
        $partialId2 = substr((string) $task2['id'], 2, 3);

        $this->artisan('reopen', [
            'ids' => [$partialId1, $partialId2],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        expect($this->taskService->find($task1['id'])['status'])->toBe('open');
        expect($this->taskService->find($task2['id'])['status'])->toBe('open');
    });
});
