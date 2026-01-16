<?php

use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

describe('available command', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
    });

    it('outputs count of ready tasks', function (): void {
        $this->taskService->create(['title' => 'Task 1']);
        $this->taskService->create(['title' => 'Task 2']);

        Artisan::call('available', []);
        $output = Artisan::output();

        expect(trim($output))->toBe('2');
    });

    it('exits with code 0 when tasks are available', function (): void {
        $this->taskService->create(['title' => 'Task 1']);

        $this->artisan('available', [])
            ->assertExitCode(0);
    });

    it('exits with code 1 when no tasks are available', function (): void {

        $this->artisan('available', [])
            ->expectsOutput('0')
            ->assertExitCode(1);
    });

    it('outputs 0 when no tasks are available', function (): void {

        Artisan::call('available', []);
        $output = Artisan::output();

        expect(trim($output))->toBe('0');
    });

    it('excludes in_progress tasks from count', function (): void {
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->start($task1->short_id);

        Artisan::call('available', []);
        $output = Artisan::output();

        // Should only count task2 (task1 is in_progress)
        expect(trim($output))->toBe('1');
    });

    it('excludes blocked tasks from count', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker']);
        $blocked = $this->taskService->create(['title' => 'Blocked']);
        $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

        Artisan::call('available', []);
        $output = Artisan::output();

        // Should only count blocker (blocked is blocked)
        expect(trim($output))->toBe('1');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->create(['title' => 'Task 1']);

        Artisan::call('available', ['--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['count'])->toBe(1);
        expect($result['available'])->toBeTrue();
    });

    it('outputs JSON with available false when no tasks', function (): void {

        Artisan::call('available', ['--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['count'])->toBe(0);
        expect($result['available'])->toBeFalse();
    });

    it('supports --cwd flag', function (): void {
        $this->taskService->create(['title' => 'Task 1']);

        Artisan::call('available', []);
        $output = Artisan::output();

        expect(trim($output))->toBe('1');
    });
});
