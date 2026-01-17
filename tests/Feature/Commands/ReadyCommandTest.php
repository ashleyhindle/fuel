<?php

use App\Services\TaskService;

// Ready Command Tests
describe('ready command', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
    });

    it('shows no tasks when empty', function (): void {

        $this->artisan('ready', [])
            ->expectsOutput('No open tasks.')
            ->assertExitCode(0);
    });

    it('shows open tasks', function (): void {
        $this->taskService->create(['title' => 'Task one']);
        $this->taskService->create(['title' => 'Task two']);

        $this->artisan('ready', [])
            ->expectsOutputToContain('Task one')
            ->expectsOutputToContain('Task two')
            ->assertExitCode(0);
    });

    it('excludes done tasks', function (): void {
        $this->taskService->create(['title' => 'Open task']);

        $done = $this->taskService->create(['title' => 'Closed task']);
        $this->taskService->done($done->short_id);

        $this->artisan('ready', [])
            ->expectsOutputToContain('Open task')
            ->doesntExpectOutputToContain('Closed task')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->create(['title' => 'JSON task']);

        $this->artisan('ready', ['--json' => true])
            ->expectsOutputToContain('"title": "JSON task"')
            ->assertExitCode(0);
    });

    it('outputs empty array as JSON when no tasks', function (): void {

        $this->artisan('ready', ['--json' => true])
            ->expectsOutput('[]')
            ->assertExitCode(0);
    });

    it('shows infinity symbol for selfguided tasks', function (): void {
        $this->taskService->create(['title' => 'Normal task']);
        $this->taskService->create(['title' => 'Selfguided task', 'type' => 'selfguided']);

        $this->artisan('ready', [])
            ->expectsOutputToContain('Normal task')
            ->expectsOutputToContain('∞ Selfguided task')
            ->assertExitCode(0);
    });

    it('does not show infinity symbol in JSON output', function (): void {
        $this->taskService->create(['title' => 'Selfguided task', 'type' => 'selfguided']);

        $this->artisan('ready', ['--json' => true])
            ->expectsOutputToContain('"title": "Selfguided task"')
            ->doesntExpectOutputToContain('∞')
            ->assertExitCode(0);
    });
});

describe('ready command with dependencies', function (): void {
    beforeEach(function (): void {
        $this->taskService = app(TaskService::class);
    });

    it('ready excludes tasks with open blockers', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

        $this->artisan('ready', [])
            ->expectsOutputToContain('Blocker task')
            ->doesntExpectOutputToContain('Blocked task')
            ->assertExitCode(0);
    });

    it('ready includes tasks when blocker is done', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

        // Close the blocker
        $this->taskService->done($blocker->short_id);

        $this->artisan('ready', [])
            ->expectsOutputToContain('Blocked task')
            ->doesntExpectOutputToContain('Blocker task')
            ->assertExitCode(0);
    });
});
