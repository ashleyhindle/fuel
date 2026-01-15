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
