<?php

use App\Services\TaskService;

uses()->group('commands');

describe('consume --once kanban board', function (): void {
    beforeEach(function (): void {
        // Use TestCase's infrastructure - just get the services we need
        $this->taskService = $this->app->make(TaskService::class);
        $this->runService = makeRunService();
    });

    it('shows empty board when no tasks', function (): void {
        expect(runCommand('consume', ['--once' => true]))
            ->toContain('Ready')
            ->toContain('In Progress')
            ->toContain('No tasks');
    });

    it('shows ready tasks in Ready column', function (): void {
        $this->taskService->create(['title' => 'Ready task']);

        expect(runCommand('consume', ['--once' => true]))
            ->toContain('Ready task');
    });

    it('shows blocked task count in footer', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

        expect(runCommand('consume', ['--once' => true]))
            ->toContain('b: blocked (1)')
            ->toContain('Blocker task');
    });

    it('shows in progress tasks in In Progress column', function (): void {
        $task = $this->taskService->create(['title' => 'In progress task']);
        $this->taskService->start($task->short_id);

        expect(runCommand('consume', ['--once' => true]))
            ->toContain('In Progress (1)')
            ->toContain('In progress task');
    });

    it('shows done task count in footer', function (): void {
        $task = $this->taskService->create(['title' => 'Completed task']);
        $this->taskService->done($task->short_id);

        expect(runCommand('consume', ['--once' => true]))
            ->toContain('d: done (1)');
    });

    it('shows review tasks in Review column', function (): void {
        $task = $this->taskService->create(['title' => 'Review task']);
        $this->taskService->update($task->short_id, ['status' => 'review']);

        expect(runCommand('consume', ['--once' => true]))
            ->toContain('Review (1)')
            ->toContain('Review task');
    });

    it('does not show review tasks in other columns', function (): void {
        $reviewTask = $this->taskService->create(['title' => 'Review task']);
        $this->taskService->update($reviewTask->short_id, ['status' => 'review']);

        expect(runCommand('consume', ['--once' => true]))
            ->toContain('Review (1)')
            ->toContain('Review task')
            ->toContain('Ready (0)')
            ->toContain('In Progress (0)');
    });

    it('shows failed icon for consumed tasks with non-zero exit code', function (): void {
        $stuckTask = $this->taskService->create(['title' => 'Stuck task']);
        $this->taskService->update($stuckTask->short_id, [
            'consumed' => true,
        ]);
        $this->runService->logRun($stuckTask->short_id, ['agent' => 'test', 'exit_code' => 1]);

        expect(runCommand('consume', ['--once' => true]))
            ->toContain('🪫')
            ->toContain('Stuck task');
    });

    it('does not show failed icon for consumed tasks with zero exit code', function (): void {
        $successTask = $this->taskService->create(['title' => 'Success task']);
        $this->taskService->update($successTask->short_id, [
            'consumed' => true,
        ]);
        $this->runService->logRun($successTask->short_id, ['agent' => 'test', 'exit_code' => 0]);

        expect(runCommand('consume', ['--once' => true]))
            ->toContain('Success task')
            ->not->toContain('🪫');
    });

    it('shows stuck emoji for in_progress tasks with non-running PID', function (): void {
        $stuckTask = $this->taskService->create(['title' => 'Stuck PID task']);
        $this->taskService->update($stuckTask->short_id, [
            'status' => 'in_progress',
            'consume_pid' => 99999, // Non-existent PID
        ]);

        expect(runCommand('consume', ['--once' => true]))
            ->toContain('🪫')
            ->toContain('Stuck PID task');
    });

    it('does not show stuck emoji for in_progress tasks with running PID', function (): void {
        $runningTask = $this->taskService->create(['title' => 'Running task']);
        // Use current process PID which should be running
        $currentPid = getmypid();
        $this->taskService->update($runningTask->short_id, [
            'status' => 'in_progress',
            'consume_pid' => $currentPid,
        ]);

        expect(runCommand('consume', ['--once' => true]))
            ->toContain('Running task')
            ->not->toContain('🪫');
    });

    it('shows complexity character in task display', function (): void {
        $this->taskService->create(['title' => 'Trivial task', 'complexity' => 'trivial']);
        $this->taskService->create(['title' => 'Simple task', 'complexity' => 'simple']);
        $this->taskService->create(['title' => 'Moderate task', 'complexity' => 'moderate']);
        $this->taskService->create(['title' => 'Complex task', 'complexity' => 'complex']);

        expect(runCommand('consume', ['--once' => true]))
            ->toContain('Trivial task')
            ->toContain('Simple task')
            ->toContain('Moderate task')
            ->toContain('Complex task')
            ->toContain(' t ─╯')
            ->toContain(' s ─╯')
            ->toContain(' m ─╯')
            ->toContain(' c ─╯');
    });

    it('defaults to simple complexity when complexity is missing', function (): void {
        $this->taskService->create(['title' => 'Task without complexity']);

        expect(runCommand('consume', ['--once' => true]))
            ->toContain('Task without complexity')
            ->toContain(' s ─╯');
    });

    it('shows footer with keyboard shortcuts', function (): void {
        expect(runCommand('consume', ['--once' => true]))
            ->toContain('Shift+Tab')
            ->toContain('b: blocked')
            ->toContain('d: done')
            ->toContain('q: exit');
    });
});
