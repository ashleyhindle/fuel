<?php

use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// Status Command Tests
describe('status command', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
    });

    it('shows zero counts when no tasks exist', function (): void {

        $this->artisan('status', [])
            ->expectsOutputToContain('Board Summary')
            ->expectsOutputToContain('Ready')
            ->expectsOutputToContain('In_progress')
            ->expectsOutputToContain('Review')
            ->expectsOutputToContain('Done')
            ->expectsOutputToContain('Blocked')
            ->expectsOutputToContain('Human')
            ->assertExitCode(0);
    });

    it('counts tasks by status correctly', function (): void {
        $open1 = $this->taskService->create(['title' => 'Open task 1']);
        $open2 = $this->taskService->create(['title' => 'Open task 2']);
        $inProgress = $this->taskService->create(['title' => 'In progress task']);
        $closed1 = $this->taskService->create(['title' => 'Closed task 1']);
        $closed2 = $this->taskService->create(['title' => 'Closed task 2']);

        $this->taskService->start($inProgress->short_id);
        $this->taskService->done($closed1->short_id);
        $this->taskService->done($closed2->short_id);

        $this->artisan('status', [])
            ->expectsOutputToContain('Board Summary')
            ->expectsOutputToContain('Ready')
            ->expectsOutputToContain('In_progress')
            ->expectsOutputToContain('Done')
            ->assertExitCode(0);
    });

    it('counts blocked tasks correctly', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked1 = $this->taskService->create(['title' => 'Blocked task 1']);
        $blocked2 = $this->taskService->create(['title' => 'Blocked task 2']);

        // Add dependencies
        $this->taskService->addDependency($blocked1->short_id, $blocker->short_id);
        $this->taskService->addDependency($blocked2->short_id, $blocker->short_id);

        $this->artisan('status', [])
            ->expectsOutputToContain('Blocked')
            ->assertExitCode(0);
    });

    it('does not count tasks as blocked when blocker is done', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add dependency
        $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

        // Close the blocker
        $this->taskService->done($blocker->short_id);

        Artisan::call('status', ['--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['board']['blocked'])->toBe(0);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->create(['title' => 'Open task']);

        $inProgress = $this->taskService->create(['title' => 'In progress task']);
        $done = $this->taskService->create(['title' => 'Closed task']);

        $this->taskService->start($inProgress->short_id);
        $this->taskService->done($done->short_id);

        Artisan::call('status', ['--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveKey('board');
        expect($result['board'])->toHaveKeys(['ready', 'in_progress', 'review', 'blocked', 'human', 'done']);
        expect($result['board']['ready'])->toBe(1);
        expect($result['board']['in_progress'])->toBe(1);
        expect($result['board']['done'])->toBe(1);
        expect($result['board']['blocked'])->toBe(0);
        expect($result['board']['human'])->toBe(0);
    });

    it('shows correct ready count for open tasks', function (): void {
        $this->taskService->create(['title' => 'Task 1']);
        $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->create(['title' => 'Task 3']);

        Artisan::call('status', ['--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['board']['ready'])->toBe(3);
        expect($result['board']['in_progress'])->toBe(0);
        expect($result['board']['done'])->toBe(0);
    });

    it('handles empty state with JSON output', function (): void {

        Artisan::call('status', ['--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['board']['ready'])->toBe(0);
        expect($result['board']['in_progress'])->toBe(0);
        expect($result['board']['review'])->toBe(0);
        expect($result['board']['done'])->toBe(0);
        expect($result['board']['blocked'])->toBe(0);
        expect($result['board']['human'])->toBe(0);
    });

    it('counts only open tasks as blocked', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blockedOpen = $this->taskService->create(['title' => 'Blocked open task']);
        $blockedInProgress = $this->taskService->create(['title' => 'Blocked in progress task']);

        // Add dependencies
        $this->taskService->addDependency($blockedOpen->short_id, $blocker->short_id);
        $this->taskService->addDependency($blockedInProgress->short_id, $blocker->short_id);

        // Set one to in_progress
        $this->taskService->start($blockedInProgress->short_id);

        Artisan::call('status', ['--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        // Only open tasks should be counted as blocked
        expect($result['board']['blocked'])->toBe(1);
    });

    it('categorizes needs-human tasks correctly', function (): void {
        $this->taskService->create(['title' => 'Regular task']);
        $this->taskService->create(['title' => 'Human task', 'labels' => ['needs-human']]);

        Artisan::call('status', ['--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['board']['ready'])->toBe(1);
        expect($result['board']['human'])->toBe(1);
        expect($result['board']['blocked'])->toBe(0);
    });
});
