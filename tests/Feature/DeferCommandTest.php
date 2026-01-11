<?php

use App\Services\BacklogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/Concerns/CommandTestSetup.php';

beforeEach($beforeEach);

afterEach($afterEach);

// Defer Command Tests
describe('defer command', function (): void {
    it('defers task to backlog', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $this->taskService->initialize();
        $task = $this->taskService->create([
            'title' => 'Task to defer',
            'description' => 'Task description',
            'priority' => 2,
        ]);

        Artisan::call('defer', [
            'id' => $task['id'],
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Deferred task:');
        expect($output)->toContain($task['id']);
        expect($output)->toContain('Task to defer');
        expect($output)->toContain('Added to backlog: b-');

        // Verify task removed
        expect($this->taskService->find($task['id']))->toBeNull();

        // Verify added to backlog
        $backlogService->initialize();
        $all = $backlogService->all();
        expect($all->count())->toBe(1);
        $backlogItem = $all->first();
        expect($backlogItem['title'])->toBe('Task to defer');
        expect($backlogItem['description'])->toBe('Task description');
        // Backlog items don't have priority
        expect($backlogItem)->not->toHaveKey('priority');
    });

    it('defers task with partial ID', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task to defer']);
        $partialId = substr((string) $task['id'], 2, 3);

        Artisan::call('defer', [
            'id' => $partialId,
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Deferred task:');
        expect($output)->toContain('Added to backlog: b-');

        // Verify task removed
        expect($this->taskService->find($task['id']))->toBeNull();
    });

    it('outputs JSON when --json flag is used', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON task']);

        Artisan::call('defer', [
            'id' => $task['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['task_id'])->toBe($task['id']);
        expect($result['backlog_item']['id'])->toStartWith('b-');
        expect($result['backlog_item']['title'])->toBe('JSON task');
    });

    it('returns error when task not found', function (): void {
        $this->artisan('defer', [
            'id' => 'f-nonexistent',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('returns error when ID is not a task', function (): void {
        $backlogService = $this->app->make(BacklogService::class);
        $backlogService->initialize();

        $item = $backlogService->add('Backlog item');

        // When deferring a backlog item ID, it first tries to find it as a task
        // Since it doesn't exist in tasks, it returns "Task not found"
        $this->artisan('defer', [
            'id' => $item['id'],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain(sprintf("Task '%s' not found", $item['id']))
            ->assertExitCode(1);
    });
});
