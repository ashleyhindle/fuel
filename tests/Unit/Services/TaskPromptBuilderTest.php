<?php

declare(strict_types=1);

use App\Models\Epic;
use App\Models\Task;
use App\Services\TaskPromptBuilder;

beforeEach(function (): void {
    $this->builder = $this->app->make(TaskPromptBuilder::class);
});

describe('TaskPromptBuilder closing protocol', function (): void {
    it('includes standalone closing protocol for tasks without epic_id', function (): void {
        $task = Task::create([
            'short_id' => 'f-standalone',
            'title' => 'Standalone Task',
            'status' => 'open',
        ]);

        $prompt = $this->builder->build($task, '/test/cwd');

        // Should include standard closing protocol with git commit
        expect($prompt)->toContain('== CLOSING PROTOCOL ==');
        expect($prompt)->toContain('git commit -m "feat/fix: description"');
        expect($prompt)->toContain('./fuel done f-standalone --commit=<hash>');

        // Should NOT include epic task closing protocol
        expect($prompt)->not->toContain('== CLOSING PROTOCOL (EPIC TASK) ==');
        expect($prompt)->not->toContain('DO NOT commit');
    });

    it('includes same closing protocol for tasks with epic_id (each task commits)', function (): void {
        // Create an epic first
        $epic = Epic::create([
            'short_id' => 'e-test123',
            'title' => 'Test Epic',
            'description' => 'Test epic description',
        ]);

        // Create a task linked to the epic
        $task = Task::create([
            'short_id' => 'f-epictask',
            'title' => 'Epic Task',
            'status' => 'open',
            'epic_id' => $epic->id,
        ]);

        $prompt = $this->builder->build($task, '/test/cwd');

        // Should include standard closing protocol with git commit (same as standalone)
        expect($prompt)->toContain('== CLOSING PROTOCOL ==');
        expect($prompt)->toContain('git commit -m "feat/fix: description"');
        expect($prompt)->toContain('./fuel done f-epictask --commit=<hash>');

        // Should NOT include old "do not commit" messaging
        expect($prompt)->not->toContain('DO NOT commit');
        expect($prompt)->not->toContain('== CLOSING PROTOCOL (EPIC TASK) ==');
    });

    it('includes epic context section for tasks with epic_id', function (): void {
        $epic = Epic::create([
            'short_id' => 'e-context1',
            'title' => 'Context Epic',
            'description' => 'Epic with full context',
        ]);

        $task = Task::create([
            'short_id' => 'f-withctx',
            'title' => 'Task with Epic Context',
            'status' => 'open',
            'epic_id' => $epic->id,
        ]);

        $prompt = $this->builder->build($task, '/test/cwd');

        // Should include epic context section
        expect($prompt)->toContain('== EPIC CONTEXT ==');
        expect($prompt)->toContain('Epic: e-context1');
        expect($prompt)->toContain('Epic Title: Context Epic');
        expect($prompt)->toContain('Epic Description: Epic with full context');
    });
});

describe('TaskPromptBuilder commit prompt', function (): void {
    it('builds commit prompt with epic context', function (): void {
        $epic = Epic::create([
            'short_id' => 'e-commit1',
            'title' => 'Feature Epic',
            'description' => 'A feature to implement',
        ]);

        $task1 = Task::create([
            'short_id' => 'f-task1',
            'title' => 'First Task',
            'status' => 'done',
            'epic_id' => $epic->id,
        ]);

        $task2 = Task::create([
            'short_id' => 'f-task2',
            'title' => 'Second Task',
            'status' => 'done',
            'epic_id' => $epic->id,
        ]);

        $commitTask = Task::create([
            'short_id' => 'f-commit',
            'title' => 'Commit: Feature Epic',
            'status' => 'open',
            'labels' => ['epic-commit'],
            'epic_id' => $epic->id,
        ]);

        $prompt = $this->builder->buildCommitPrompt(
            $commitTask,
            $epic,
            [$task1, $task2],
            '/test/cwd'
        );

        // Should include commit task assignment
        expect($prompt)->toContain('You are assigned the COMMIT task: f-commit');
        expect($prompt)->toContain('organize and commit the staged changes for epic e-commit1');

        // Should include epic context
        expect($prompt)->toContain('Title: Feature Epic');
        expect($prompt)->toContain('Description: A feature to implement');

        // Should include completed tasks
        expect($prompt)->toContain('f-task1: First Task');
        expect($prompt)->toContain('f-task2: Second Task');

        // Should include commit instructions
        expect($prompt)->toContain('ORGANIZE INTO COMMITS');
        expect($prompt)->toContain('feat:, fix:, refactor:, docs:, test:, chore:');
        expect($prompt)->toContain('./fuel done f-commit --commit=<last-commit-hash>');
    });

    it('handles empty description in commit prompt', function (): void {
        $epic = Epic::create([
            'short_id' => 'e-nodesc',
            'title' => 'Epic Without Description',
            'description' => null,
        ]);

        $task = Task::create([
            'short_id' => 'f-work1',
            'title' => 'Work Task',
            'status' => 'done',
            'epic_id' => $epic->id,
        ]);

        $commitTask = Task::create([
            'short_id' => 'f-commit2',
            'title' => 'Commit: Epic Without Description',
            'status' => 'open',
            'epic_id' => $epic->id,
        ]);

        $prompt = $this->builder->buildCommitPrompt(
            $commitTask,
            $epic,
            [$task],
            '/test/cwd'
        );

        // Should handle null description gracefully
        expect($prompt)->toContain('Title: Epic Without Description');
        expect($prompt)->toContain('Description:'); // Empty description is fine
    });
});
