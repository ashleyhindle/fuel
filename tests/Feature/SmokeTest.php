<?php

declare(strict_types=1);

use App\Models\Review;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

it('runs a basic command flow', function (): void {
    $cwd = $this->testDir;

    Artisan::call('init', []);

    expect(file_exists($cwd.'/.fuel/agent.db'))->toBeTrue();

    // init already calls guidelines --add, so AGENTS.md should exist
    expect(file_exists($cwd.'/AGENTS.md'))->toBeTrue();

    $inspireExit = Artisan::call('inspire');
    expect($inspireExit)->toBe(0);

    $healthExit = Artisan::call('health', [
        '--json' => true,
    ]);
    expect($healthExit)->toBe(0);

    Artisan::call('add', [
        'title' => 'Smoke task',
        '--json' => true,
    ]);
    $task = json_decode(Artisan::output(), true);

    expect($task)->toBeArray();
    expect($task)->toHaveKey('short_id');

    $taskId = $task['short_id'];

    $taskService = app(TaskService::class);
    $runService = app(RunService::class);

    $runService->logRun($taskId, [
        'agent' => 'claude',
        'model' => 'sonnet',
        'started_at' => now()->subSeconds(30)->toIso8601String(),
        'session_id' => 'sess-smoke-123',
    ]);
    $runService->updateLatestRun($taskId, [
        'ended_at' => now()->toIso8601String(),
        'exit_code' => 0,
        'output' => 'Smoke run output',
        'cost_usd' => 0.01,
    ]);

    // Create a review record for review:show and reviews tests
    $taskModel = $taskService->find($taskId);
    $review = Review::create([
        'short_id' => 'r-smoke1',
        'task_id' => $taskModel->id,
        'agent' => 'claude',
        'status' => 'completed',
        'issues' => ['Test issue 1', 'Test issue 2'],
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);

    Artisan::call('review:show', [
        'id' => $review->short_id,
        '--json' => true,
    ]);
    $reviewShown = json_decode(Artisan::output(), true);
    expect($reviewShown['short_id'] ?? null)->toBe('r-smoke1');
    expect($reviewShown['status'] ?? null)->toBe('completed');

    Artisan::call('runs', [
        'id' => $taskId,
        '--json' => true,
    ]);
    $runs = json_decode(Artisan::output(), true);

    expect($runs)->toBeArray();
    expect($runs[0]['run_id'] ?? null)->not->toBeNull();

    Artisan::call('summary', [
        'id' => $taskId,
        '--json' => true,
    ]);
    $summary = json_decode(Artisan::output(), true);

    expect($summary)->toHaveKeys(['task', 'runs']);

    Artisan::call('ready', [
        '--json' => true,
    ]);
    $ready = json_decode(Artisan::output(), true);

    expect(array_column($ready, 'short_id'))->toContain($taskId);

    $availableExit = Artisan::call('available', [
    ]);
    expect($availableExit)->toBe(0);

    Artisan::call('tasks', [
        '--json' => true,
    ]);
    $tasks = json_decode(Artisan::output(), true);

    expect(array_column($tasks, 'short_id'))->toContain($taskId);

    Artisan::call('tree', [
        '--json' => true,
    ]);
    $tree = json_decode(Artisan::output(), true);

    expect($tree)->toBeArray();

    Artisan::call('add', [
        'title' => 'Blocker task',
        '--json' => true,
    ]);
    $blockerTask = json_decode(Artisan::output(), true);
    $blockerId = $blockerTask['short_id'];

    Artisan::call('add', [
        'title' => 'Blocked task',
        '--json' => true,
    ]);
    $blockedTask = json_decode(Artisan::output(), true);
    $blockedId = $blockedTask['short_id'];

    Artisan::call('dep:add', [
        'from' => $blockedId,
        'to' => $blockerId,
        '--json' => true,
    ]);
    $dependency = json_decode(Artisan::output(), true);

    expect($dependency['short_id'] ?? null)->toBe($blockedId);

    Artisan::call('blocked', [
        '--json' => true,
    ]);
    $blocked = json_decode(Artisan::output(), true);

    expect(array_column($blocked, 'short_id'))->toContain($blockedId);

    Artisan::call('dep:remove', [
        'from' => $blockedId,
        'to' => $blockerId,
        '--json' => true,
    ]);
    $dependencyRemoved = json_decode(Artisan::output(), true);

    expect($dependencyRemoved['short_id'] ?? null)->toBe($blockedId);

    Artisan::call('update', [
        'id' => $taskId,
        '--json' => true,
        '--title' => 'Smoke task updated',
    ]);
    $updated = json_decode(Artisan::output(), true);

    expect($updated['title'] ?? null)->toBe('Smoke task updated');

    Artisan::call('start', [
        'id' => $taskId,
        '--json' => true,
    ]);
    $started = json_decode(Artisan::output(), true);

    expect($started['status'] ?? null)->toBe('in_progress');

    Artisan::call('show', [
        'id' => $taskId,
        '--json' => true,
    ]);
    $shown = json_decode(Artisan::output(), true);

    expect($shown['short_id'] ?? null)->toBe($taskId);

    // Test consume --once for kanban board view
    $consumeOutput = runCommand('consume', [
        '--once' => true,
    ]);

    expect($consumeOutput)->toContain('Ready');
    expect($consumeOutput)->toContain('In Progress');

    Artisan::call('done', [
        'ids' => [$taskId],
        '--json' => true,
    ]);
    $done = json_decode(Artisan::output(), true);

    expect($done['status'] ?? null)->toBe('done');

    Artisan::call('completed', [
        '--json' => true,
    ]);
    $completed = json_decode(Artisan::output(), true);

    expect($completed)->toBeArray();
    expect(array_column($completed, 'short_id'))->toContain($taskId);

    Artisan::call('reopen', [
        'ids' => [$taskId],
        '--json' => true,
    ]);
    $reopened = json_decode(Artisan::output(), true);

    expect($reopened['status'] ?? null)->toBe('open');

    Artisan::call('status', [
        '--json' => true,
    ]);
    $status = json_decode(Artisan::output(), true);

    expect($status)->toHaveKeys(['open', 'in_progress', 'review', 'done', 'blocked', 'total']);

    Artisan::call('add', [
        'title' => 'Defer me',
        '--json' => true,
    ]);
    $deferTask = json_decode(Artisan::output(), true);

    Artisan::call('defer', [
        'id' => $deferTask['short_id'],
        '--json' => true,
    ]);
    $deferred = json_decode(Artisan::output(), true);

    $deferredTaskId = $deferred['task_id'] ?? null;
    expect($deferredTaskId)->not->toBeNull();

    Artisan::call('backlog', [
        '--json' => true,
    ]);
    $backlog = json_decode(Artisan::output(), true);
    expect(array_column($backlog, 'short_id'))->toContain($deferredTaskId);

    Artisan::call('promote', [
        'ids' => [$deferredTaskId],
        '--json' => true,
        '--priority' => 2,
        '--type' => 'task',
        '--complexity' => 'simple',
    ]);
    $promoted = json_decode(Artisan::output(), true);
    $promotedTaskId = $promoted['short_id'] ?? null;
    expect($promotedTaskId)->not->toBeNull();

    Artisan::call('remove', [
        'id' => $blockedId,
        '--json' => true,
    ]);
    $removed = json_decode(Artisan::output(), true);
    expect($removed['short_id'] ?? null)->toBe($blockedId);

    Artisan::call('epic:add', [
        'title' => 'Smoke epic',
        '--description' => 'Smoke epic description',
        '--json' => true,
    ]);
    $epic = json_decode(Artisan::output(), true);
    $epicId = $epic['short_id'];

    Artisan::call('add', [
        'title' => 'Epic linked task',
        '--json' => true,
        '--epic' => $epicId,
    ]);
    $epicLinkedTask = json_decode(Artisan::output(), true);
    $epicLinkedTaskId = $epicLinkedTask['short_id'];

    Artisan::call('epic:show', [
        'id' => $epicId,
        '--json' => true,
    ]);
    $epicShown = json_decode(Artisan::output(), true);
    expect($epicShown['short_id'] ?? null)->toBe($epicId);

    Artisan::call('epics', [
        '--json' => true,
    ]);
    $epics = json_decode(Artisan::output(), true);
    expect(array_column($epics, 'short_id'))->toContain($epicId);

    Artisan::call('epic:approve', [
        'ids' => [$epicId],
        '--json' => true,
    ]);
    $epicApproved = json_decode(Artisan::output(), true);
    expect($epicApproved['short_id'] ?? null)->toBe($epicId);

    Artisan::call('epic:reviewed', [
        'id' => $epicId,
        '--json' => true,
    ]);
    $epicReviewed = json_decode(Artisan::output(), true);
    expect($epicReviewed['short_id'] ?? null)->toBe($epicId);

    Artisan::call('epic:reject', [
        'id' => $epicId,
        '--json' => true,
        '--reason' => 'Needs changes',
    ]);
    $epicRejected = json_decode(Artisan::output(), true);
    expect($epicRejected['short_id'] ?? null)->toBe($epicId);

    Artisan::call('human', [
        '--json' => true,
    ]);
    $human = json_decode(Artisan::output(), true);
    expect($human)->toHaveKeys(['tasks', 'epics']);

    // Mark epic linked task done with commit hash for epic:review test
    Artisan::call('start', [
        'id' => $epicLinkedTaskId,
    ]);
    Artisan::call('done', [
        'ids' => [$epicLinkedTaskId],
        '--json' => true,
        '--commit' => 'abc1234',
    ]);

    Artisan::call('epic:review', [
        'epicId' => $epicId,
        '--json' => true,
    ]);
    $epicReview = json_decode(Artisan::output(), true);
    expect($epicReview)->toHaveKey('epic');
    expect($epicReview['epic']['short_id'] ?? null)->toBe($epicId);

    Artisan::call('epic:delete', [
        'id' => $epicId,
        '--json' => true,
    ]);
    $epicDeleted = json_decode(Artisan::output(), true);
    expect($epicDeleted['short_id'] ?? null)->toBe($epicId);

    $qExit = Artisan::call('q', [
        'title' => 'Quick task',
    ]);
    expect($qExit)->toBe(0);
    expect(str_starts_with(trim(Artisan::output()), 'f-'))->toBeTrue();

    Artisan::call('add', [
        'title' => 'Retry me',
        '--json' => true,
    ]);
    $retryTask = json_decode(Artisan::output(), true);
    $retryTaskId = $retryTask['short_id'];

    $taskService->update($retryTaskId, [
        'status' => 'in_progress',
        'consumed' => true,
        'consumed_at' => now()->toIso8601String(),
        'consumed_exit_code' => 1,
        'consumed_output' => 'Smoke retry output',
        'consume_pid' => null,
    ]);

    Artisan::call('retry', [
        'ids' => [$retryTaskId],
        '--json' => true,
    ]);
    $retried = json_decode(Artisan::output(), true);
    expect($retried['status'] ?? null)->toBe('open');

    Artisan::call('done', [
        'ids' => [$taskId, $epicLinkedTaskId],
        '--json' => true,
    ]);

    Artisan::call('db', [
        '--json' => true,
    ]);
    $db = json_decode(Artisan::output(), true);
    expect($db['success'] ?? null)->toBeTrue();

    $statsExit = Artisan::call('stats', [
    ]);
    expect($statsExit)->toBe(0);

    Artisan::call('reviews', [
        '--json' => true,
    ]);
    $reviews = json_decode(Artisan::output(), true);
    expect($reviews)->toBeArray();
    expect(array_column($reviews, 'short_id'))->toContain('r-smoke1');

    Artisan::call('stuck', [
        '--json' => true,
    ]);
    $stuck = json_decode(Artisan::output(), true);
    expect($stuck)->toBeArray();
});
