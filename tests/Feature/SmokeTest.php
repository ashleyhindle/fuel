<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

it('runs a basic command flow', function (): void {
    $cwd = $this->testDir;

    Artisan::call('init', ['--cwd' => $cwd]);

    expect(file_exists($cwd.'/.fuel/agent.db'))->toBeTrue();

    Artisan::call('guidelines', [
        '--add' => true,
        '--cwd' => $cwd,
    ]);

    expect(file_exists($cwd.'/AGENTS.md'))->toBeTrue();

    $consumeExit = Artisan::call('consume', [
        '--cwd' => $cwd,
        '--dryrun' => true,
        '--skip-review' => true,
    ]);

    expect($consumeExit)->toBe(0);

    $inspireExit = Artisan::call('inspire');
    expect($inspireExit)->toBe(0);

    $healthExit = Artisan::call('health', [
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    expect($healthExit)->toBe(0);

    Artisan::call('add', [
        'title' => 'Smoke task',
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $task = json_decode(Artisan::output(), true);

    expect($task)->toBeArray();
    expect($task)->toHaveKey('id');

    $taskId = $task['id'];

    $databaseService = app(DatabaseService::class);
    $databaseService->setDatabasePath($cwd.'/.fuel/agent.db');

    $taskService = app(TaskService::class);
    $taskService->setDatabasePath($cwd.'/.fuel/agent.db');

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

    Artisan::call('runs', [
        'id' => $taskId,
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $runs = json_decode(Artisan::output(), true);

    expect($runs)->toBeArray();
    expect($runs[0]['run_id'] ?? null)->not->toBeNull();

    Artisan::call('summary', [
        'id' => $taskId,
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $summary = json_decode(Artisan::output(), true);

    expect($summary)->toHaveKeys(['task', 'runs']);

    Artisan::call('ready', [
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $ready = json_decode(Artisan::output(), true);

    expect(array_column($ready, 'id'))->toContain($taskId);

    $availableExit = Artisan::call('available', [
        '--cwd' => $cwd,
    ]);
    expect($availableExit)->toBe(0);

    Artisan::call('tasks', [
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $tasks = json_decode(Artisan::output(), true);

    expect(array_column($tasks, 'id'))->toContain($taskId);

    Artisan::call('tree', [
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $tree = json_decode(Artisan::output(), true);

    expect($tree)->toBeArray();

    Artisan::call('add', [
        'title' => 'Blocker task',
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $blockerTask = json_decode(Artisan::output(), true);
    $blockerId = $blockerTask['id'];

    Artisan::call('add', [
        'title' => 'Blocked task',
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $blockedTask = json_decode(Artisan::output(), true);
    $blockedId = $blockedTask['id'];

    Artisan::call('dep:add', [
        'from' => $blockedId,
        'to' => $blockerId,
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $dependency = json_decode(Artisan::output(), true);

    expect($dependency['id'] ?? null)->toBe($blockedId);

    Artisan::call('blocked', [
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $blocked = json_decode(Artisan::output(), true);

    expect(array_column($blocked, 'id'))->toContain($blockedId);

    Artisan::call('dep:remove', [
        'from' => $blockedId,
        'to' => $blockerId,
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $dependencyRemoved = json_decode(Artisan::output(), true);

    expect($dependencyRemoved['id'] ?? null)->toBe($blockedId);

    Artisan::call('update', [
        'id' => $taskId,
        '--cwd' => $cwd,
        '--json' => true,
        '--title' => 'Smoke task updated',
    ]);
    $updated = json_decode(Artisan::output(), true);

    expect($updated['title'] ?? null)->toBe('Smoke task updated');

    Artisan::call('start', [
        'id' => $taskId,
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $started = json_decode(Artisan::output(), true);

    expect($started['status'] ?? null)->toBe('in_progress');

    Artisan::call('show', [
        'id' => $taskId,
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $shown = json_decode(Artisan::output(), true);

    expect($shown['id'] ?? null)->toBe($taskId);

    Artisan::call('board', [
        '--cwd' => $cwd,
        '--json' => true,
        '--once' => true,
    ]);
    $board = json_decode(Artisan::output(), true);

    expect($board)->toHaveKeys(['ready', 'in_progress', 'review', 'blocked', 'human', 'done']);

    Artisan::call('done', [
        'ids' => [$taskId],
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $done = json_decode(Artisan::output(), true);

    expect($done['status'] ?? null)->toBe('closed');

    Artisan::call('completed', [
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $completed = json_decode(Artisan::output(), true);

    expect($completed)->toBeArray();
    expect(array_column($completed, 'id'))->toContain($taskId);

    Artisan::call('reopen', [
        'ids' => [$taskId],
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $reopened = json_decode(Artisan::output(), true);

    expect($reopened['status'] ?? null)->toBe('open');

    Artisan::call('status', [
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $status = json_decode(Artisan::output(), true);

    expect($status)->toHaveKeys(['open', 'in_progress', 'review', 'closed', 'blocked', 'total']);

    Artisan::call('add', [
        'title' => 'Defer me',
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $deferTask = json_decode(Artisan::output(), true);

    Artisan::call('defer', [
        'id' => $deferTask['id'],
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $deferred = json_decode(Artisan::output(), true);

    $deferredTaskId = $deferred['task_id'] ?? null;
    expect($deferredTaskId)->not->toBeNull();

    Artisan::call('backlog', [
        '--json' => true,
    ]);
    $backlog = json_decode(Artisan::output(), true);
    expect(array_column($backlog, 'id'))->toContain($deferredTaskId);

    Artisan::call('promote', [
        'ids' => [$deferredTaskId],
        '--cwd' => $cwd,
        '--json' => true,
        '--priority' => 2,
        '--type' => 'task',
        '--complexity' => 'simple',
    ]);
    $promoted = json_decode(Artisan::output(), true);
    $promotedTaskId = $promoted['id'] ?? null;
    expect($promotedTaskId)->not->toBeNull();

    Artisan::call('remove', [
        'id' => $blockedId,
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $removed = json_decode(Artisan::output(), true);
    expect($removed['id'] ?? null)->toBe($blockedId);

    Artisan::call('epic:add', [
        'title' => 'Smoke epic',
        '--description' => 'Smoke epic description',
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $epic = json_decode(Artisan::output(), true);
    $epicId = $epic['id'];

    Artisan::call('add', [
        'title' => 'Epic linked task',
        '--cwd' => $cwd,
        '--json' => true,
        '--epic' => $epicId,
    ]);
    $epicLinkedTask = json_decode(Artisan::output(), true);
    $epicLinkedTaskId = $epicLinkedTask['id'];

    Artisan::call('epic:show', [
        'id' => $epicId,
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $epicShown = json_decode(Artisan::output(), true);
    expect($epicShown['id'] ?? null)->toBe($epicId);

    Artisan::call('epics', [
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $epics = json_decode(Artisan::output(), true);
    expect(array_column($epics, 'id'))->toContain($epicId);

    Artisan::call('epic:approve', [
        'ids' => [$epicId],
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $epicApproved = json_decode(Artisan::output(), true);
    expect($epicApproved['id'] ?? null)->toBe($epicId);

    Artisan::call('epic:reviewed', [
        'id' => $epicId,
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $epicReviewed = json_decode(Artisan::output(), true);
    expect($epicReviewed['id'] ?? null)->toBe($epicId);

    Artisan::call('epic:reject', [
        'id' => $epicId,
        '--cwd' => $cwd,
        '--json' => true,
        '--reason' => 'Needs changes',
    ]);
    $epicRejected = json_decode(Artisan::output(), true);
    expect($epicRejected['id'] ?? null)->toBe($epicId);

    Artisan::call('human', [
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $human = json_decode(Artisan::output(), true);
    expect($human)->toHaveKeys(['tasks', 'epics']);

    Artisan::call('epic:delete', [
        'id' => $epicId,
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $epicDeleted = json_decode(Artisan::output(), true);
    expect($epicDeleted['id'] ?? null)->toBe($epicId);

    $qExit = Artisan::call('q', [
        'title' => 'Quick task',
        '--cwd' => $cwd,
    ]);
    expect($qExit)->toBe(0);
    expect(str_starts_with(trim(Artisan::output()), 'f-'))->toBeTrue();

    Artisan::call('add', [
        'title' => 'Retry me',
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $retryTask = json_decode(Artisan::output(), true);
    $retryTaskId = $retryTask['id'];

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
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $retried = json_decode(Artisan::output(), true);
    expect($retried['status'] ?? null)->toBe('open');

    Artisan::call('done', [
        'ids' => [$taskId, $epicLinkedTaskId],
        '--cwd' => $cwd,
        '--json' => true,
    ]);

    Artisan::call('db', [
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $db = json_decode(Artisan::output(), true);
    expect($db['success'] ?? null)->toBeTrue();

    Artisan::call('stuck', [
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $stuck = json_decode(Artisan::output(), true);
    expect($stuck)->toBeArray();
});
