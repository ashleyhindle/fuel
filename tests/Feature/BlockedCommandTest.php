<?php

require_once __DIR__.'/Concerns/CommandTestSetup.php';

beforeEach($beforeEach);

afterEach($afterEach);

// Blocked Command Tests
describe('blocked command', function (): void {
    it('shows empty when no blocked tasks', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Unblocked task']);

        $this->artisan('blocked', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No blocked tasks.')
            ->assertExitCode(0);
    });

    it('blocked includes tasks with open blockers', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        $this->artisan('blocked', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Blocked task')
            ->doesntExpectOutputToContain('Blocker task')
            ->assertExitCode(0);
    });

    it('blocked excludes tasks when blocker is closed', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        // Close the blocker
        $this->taskService->done($blocker['id']);

        $this->artisan('blocked', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No blocked tasks.')
            ->doesntExpectOutputToContain('Blocked task')
            ->assertExitCode(0);
    });

    it('blocked outputs JSON when --json flag is provided', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        Artisan::call('blocked', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain($blocked['id']);
        expect($output)->toContain('Blocked task');
        expect($output)->not->toContain('Blocker task');
    });

    it('blocked filters by size when --size option is provided', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blockedSmall = $this->taskService->create(['title' => 'Small blocked task', 'size' => 's']);
        $blockedLarge = $this->taskService->create(['title' => 'Large blocked task', 'size' => 'l']);

        // Add dependencies
        $this->taskService->addDependency($blockedSmall['id'], $blocker['id']);
        $this->taskService->addDependency($blockedLarge['id'], $blocker['id']);

        $this->artisan('blocked', ['--cwd' => $this->tempDir, '--size' => 's'])
            ->expectsOutputToContain('Small blocked task')
            ->doesntExpectOutputToContain('Large blocked task')
            ->assertExitCode(0);
    });
});
