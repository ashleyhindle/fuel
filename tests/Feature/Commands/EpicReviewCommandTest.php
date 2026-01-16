<?php

declare(strict_types=1);

use App\Services\EpicService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

describe('epic:review command', function (): void {
    beforeEach(function (): void {
        $this->taskService = $this->app->make(TaskService::class);
    });

    it('displays epic review with tasks', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Test Epic', 'Test Description');

        // Create some tasks linked to the epic
        $task1 = $taskService->create([
            'title' => 'Task 1',
            'description' => 'First task',
            'type' => 'feature',
            'priority' => 1,
            'epic_id' => $epic->short_id,
        ]);

        $task2 = $taskService->create([
            'title' => 'Task 2',
            'description' => 'Second task',
            'type' => 'bug',
            'priority' => 2,
            'epic_id' => $epic->short_id,
        ]);

        Artisan::call('epic:review', ['epicId' => $epic->short_id, '--no-prompt' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Epic Review:');
        expect($output)->toContain($epic->short_id);
        expect($output)->toContain('Test Epic');
        expect($output)->toContain('Test Description');
        expect($output)->toContain('Task 1');
        expect($output)->toContain('Task 2');
    });

    it('shows error when epic not found', function (): void {
        Artisan::call('epic:review', ['epicId' => 'e-nonexistent', '--no-prompt' => true]);
        $output = Artisan::output();

        expect($output)->toContain("Epic 'e-nonexistent' not found");
    });

    it('outputs JSON when --json flag is used', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('JSON Epic', 'JSON Description');

        $task = $taskService->create([
            'title' => 'JSON Task',
            'type' => 'feature',
            'priority' => 1,
            'epic_id' => $epic->short_id,
        ]);

        Artisan::call('epic:review', ['epicId' => $epic->short_id, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveKey('epic');
        expect($data)->toHaveKey('tasks');
        expect($data)->toHaveKey('commits');
        expect($data)->toHaveKey('git_stats');
        expect($data)->toHaveKey('commit_messages');
        expect($data['epic']['short_id'])->toBe($epic->short_id);
        expect($data['epic']['title'])->toBe('JSON Epic');
        expect($data['tasks'])->toHaveCount(1);
        expect($data['tasks'][0]['title'])->toBe('JSON Task');
    });

    it('shows commit information when tasks have commit hashes', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Commit Epic', 'Epic with commits');

        $task = $taskService->create([
            'title' => 'Task with commit',
            'type' => 'feature',
            'priority' => 1,
            'epic_id' => $epic->short_id,
        ]);

        // Mark task as done with a commit hash
        $taskService->done($task->short_id, null, 'abc1234567890123456789012345678901234567');

        Artisan::call('epic:review', ['epicId' => $epic->short_id, '--no-prompt' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Commits');
        expect($output)->toContain('abc1234567890123456789012345678901234567');
    });

    it('supports partial ID matching', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Partial ID Epic');

        // Use partial ID (without e- prefix)
        $partialId = substr((string) $epic->short_id, 2);

        Artisan::call('epic:review', ['epicId' => $partialId, '--no-prompt' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Epic Review:');
        expect($output)->toContain($epic->short_id);
        expect($output)->toContain('Partial ID Epic');
    });

    it('handles epic with no tasks', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Empty Epic', 'No tasks yet');

        Artisan::call('epic:review', ['epicId' => $epic->short_id, '--no-prompt' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Epic Review:');
        expect($output)->toContain('Empty Epic');
        expect($output)->toContain('No tasks linked to this epic');
    });

    it('handles epic with tasks but no commits', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('No Commits Epic');

        $taskService->create([
            'title' => 'Task without commit',
            'type' => 'feature',
            'priority' => 1,
            'epic_id' => $epic->short_id,
        ]);

        Artisan::call('epic:review', ['epicId' => $epic->short_id, '--no-prompt' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Epic Review:');
        expect($output)->toContain('No commits associated with tasks in this epic');
    });

    it('includes diff stats in JSON output', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Stats Epic');

        $task = $taskService->create([
            'title' => 'Task with commit',
            'type' => 'feature',
            'priority' => 1,
            'epic_id' => $epic->short_id,
        ]);

        $taskService->done($task->short_id, null, 'testcommit123');

        Artisan::call('epic:review', ['epicId' => $epic->short_id, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toHaveKey('git_stats');
        expect($data)->toHaveKey('commit_messages');
    });

    it('includes full diff when --diff flag is used', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Diff Epic');

        $task = $taskService->create([
            'title' => 'Task with commit',
            'type' => 'feature',
            'priority' => 1,
            'epic_id' => $epic->short_id,
        ]);

        $taskService->done($task->short_id, null, 'testcommit456');

        Artisan::call('epic:review', ['epicId' => $epic->short_id, '--json' => true, '--diff' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toHaveKey('git_diff');
    });

    it('shows diff stats in text output when tasks have commits', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Stats Text Epic');

        $task = $taskService->create([
            'title' => 'Task with commit',
            'type' => 'feature',
            'priority' => 1,
            'epic_id' => $epic->short_id,
        ]);

        $taskService->done($task->short_id, null, 'abc1234567890123456789012345678901234567');

        Artisan::call('epic:review', ['epicId' => $epic->short_id, '--no-prompt' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Diff Stats:');
    });

    it('shows full diff in text output when --diff flag is used', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->app->make(TaskService::class);

        $epic = $epicService->createEpic('Diff Text Epic');

        $task = $taskService->create([
            'title' => 'Task with commit',
            'type' => 'feature',
            'priority' => 1,
            'epic_id' => $epic->short_id,
        ]);

        $taskService->done($task->short_id, null, 'def1234567890123456789012345678901234567');

        Artisan::call('epic:review', ['epicId' => $epic->short_id, '--no-prompt' => true, '--diff' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Full Diff:');
    });
});
