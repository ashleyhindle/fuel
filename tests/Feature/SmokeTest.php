<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('runs a basic command flow', function (): void {
    $cwd = $this->testDir;

    Artisan::call('init', ['--cwd' => $cwd]);

    expect(file_exists($cwd.'/.fuel/agent.db'))->toBeTrue();

    Artisan::call('add', [
        'title' => 'Smoke task',
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $task = json_decode(Artisan::output(), true);

    expect($task)->toBeArray();
    expect($task)->toHaveKey('id');

    $taskId = $task['id'];

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

    Artisan::call('status', [
        '--cwd' => $cwd,
        '--json' => true,
    ]);
    $status = json_decode(Artisan::output(), true);

    expect($status)->toHaveKeys(['open', 'in_progress', 'review', 'closed', 'blocked', 'total']);
});
