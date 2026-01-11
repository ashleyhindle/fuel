<?php

use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/Concerns/CommandTestSetup.php';

beforeEach($beforeEach);

afterEach($afterEach);

describe('available command', function (): void {
    it('outputs count of ready tasks', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task 1']);
        $this->taskService->create(['title' => 'Task 2']);

        Artisan::call('available', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect(trim($output))->toBe('2');
    });

    it('exits with code 0 when tasks are available', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task 1']);

        $this->artisan('available', ['--cwd' => $this->tempDir])
            ->assertExitCode(0);
    });

    it('exits with code 1 when no tasks are available', function (): void {
        $this->taskService->initialize();

        $this->artisan('available', ['--cwd' => $this->tempDir])
            ->expectsOutput('0')
            ->assertExitCode(1);
    });

    it('outputs 0 when no tasks are available', function (): void {
        $this->taskService->initialize();

        Artisan::call('available', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect(trim($output))->toBe('0');
    });

    it('excludes in_progress tasks from count', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->start($task1['id']);

        Artisan::call('available', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        // Should only count task2 (task1 is in_progress)
        expect(trim($output))->toBe('1');
    });

    it('excludes blocked tasks from count', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker']);
        $blocked = $this->taskService->create(['title' => 'Blocked']);
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        Artisan::call('available', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        // Should only count blocker (blocked is blocked)
        expect(trim($output))->toBe('1');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task 1']);

        Artisan::call('available', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['count'])->toBe(1);
        expect($result['available'])->toBeTrue();
    });

    it('outputs JSON with available false when no tasks', function (): void {
        $this->taskService->initialize();

        Artisan::call('available', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['count'])->toBe(0);
        expect($result['available'])->toBeFalse();
    });

    it('supports --cwd flag', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task 1']);

        Artisan::call('available', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect(trim($output))->toBe('1');
    });
});
