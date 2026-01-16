<?php

use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// Blocked Command Tests
describe('blocked command', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
    });

    it('shows empty when no blocked tasks', function (): void {
        $this->taskService->create(['title' => 'Unblocked task']);

        $this->artisan('blocked', [])
            ->expectsOutputToContain('No blocked tasks.')
            ->assertExitCode(0);
    });

    it('blocked includes tasks with open blockers', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

        $this->artisan('blocked', [])
            ->expectsOutputToContain('Blocked task')
            ->doesntExpectOutputToContain('Blocker task')
            ->assertExitCode(0);
    });

    it('blocked excludes tasks when blocker is done', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

        // Close the blocker
        $this->taskService->done($blocker->short_id);

        $this->artisan('blocked', [])
            ->expectsOutputToContain('No blocked tasks.')
            ->doesntExpectOutputToContain('Blocked task')
            ->assertExitCode(0);
    });

    it('blocked outputs JSON when --json flag is provided', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

        Artisan::call('blocked', ['--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain($blocked->short_id);
        expect($output)->toContain('Blocked task');
        expect($output)->not->toContain('Blocker task');
    });
});
