<?php

use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/Concerns/CommandTestSetup.php';

beforeEach($beforeEach);

afterEach($afterEach);

describe('start command', function (): void {
    it('sets status to in_progress', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task to start']);

        $this->artisan('start', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Started task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('in_progress');
    });

    it('excludes task from ready() output', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        // Start task1
        $this->artisan('start', ['id' => $task1['id'], '--cwd' => $this->tempDir])
            ->assertExitCode(0);

        // Task1 should not appear in ready output
        $this->artisan('ready', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Task 2')
            ->doesntExpectOutputToContain('Task 1')
            ->assertExitCode(0);
    });

    it('supports partial ID matching', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Partial ID task']);
        $partialId = substr((string) $task['id'], 2, 3); // Just 3 chars of the hash

        $this->artisan('start', ['id' => $partialId, '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Started task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('in_progress');
    });

    it('returns JSON when --json flag used', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON start task']);

        Artisan::call('start', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['id'])->toBe($task['id']);
        expect($result['status'])->toBe('in_progress');
        expect($result['title'])->toBe('JSON start task');
    });

    it('handles invalid IDs gracefully', function (): void {
        $this->taskService->initialize();

        $this->artisan('start', ['id' => 'nonexistent', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('outputs JSON error for invalid ID with --json flag', function (): void {
        $this->taskService->initialize();

        Artisan::call('start', ['id' => 'nonexistent', '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveKey('error');
        expect($result['error'])->toContain('not found');
    });
});
