<?php

declare(strict_types=1);

use App\Models\Task;
use Illuminate\Support\Carbon;

function makeTask(array $attributes): Task
{
    $task = new Task;
    $task->setRawAttributes($attributes, true);

    return $task;
}

test('can be instantiated with attributes', function (): void {
    $data = [
        'id' => 1,
        'short_id' => 'f-abc123',
        'title' => 'Test Task',
        'description' => 'Test description',
        'type' => 'feature',
        'status' => 'pending',
        'priority' => 1,
        'complexity' => 'simple',
        'labels' => '["api","urgent"]',
        'blocked_by' => '["f-xyz789"]',
        'epic_id' => 'e-epic01',
        'created_at' => '2024-01-01 12:00:00',
        'updated_at' => '2024-01-01 12:00:00',
        'completed_at' => null,
        'started_at' => null,
    ];

    $task = makeTask($data);

    expect($task)->toBeInstanceOf(Task::class);
    expect($task->id)->toBe(1);
    expect($task->short_id)->toBe('f-abc123');
    expect($task->title)->toBe('Test Task');
    expect($task->description)->toBe('Test description');
    expect($task->type)->toBe('feature');
    expect($task->status)->toBe('pending');
    expect($task->priority)->toBe(1);
    expect($task->complexity)->toBe('simple');
    expect($task->labels)->toBe(['api', 'urgent']);
    expect($task->blocked_by)->toBe(['f-xyz789']);
    expect($task->epic_id)->toBe('e-epic01');
});

test('magic __get provides access to properties', function (): void {
    $task = makeTask([
        'id' => 1,
        'short_id' => 'f-abc123',
        'title' => 'Test Task',
        'description' => 'Test description',
        'type' => 'feature',
        'status' => 'pending',
        'priority' => 1,
        'complexity' => 'simple',
        'labels' => '["api","urgent"]',
        'blocked_by' => '["f-xyz789"]',
        'epic_id' => 'e-epic01',
        'created_at' => '2024-01-01 12:00:00',
        'updated_at' => '2024-01-01 12:00:00',
        'completed_at' => null,
        'started_at' => null,
    ]);

    expect($task->id)->toBe(1);
    expect($task->short_id)->toBe('f-abc123');
    expect($task->title)->toBe('Test Task');
    expect($task->description)->toBe('Test description');
    expect($task->type)->toBe('feature');
    expect($task->status)->toBe('pending');
    expect($task->priority)->toBe(1);
    expect($task->complexity)->toBe('simple');
    expect($task->labels)->toBe(['api', 'urgent']);
    expect($task->blocked_by)->toBe(['f-xyz789']);
    expect($task->epic_id)->toBe('e-epic01');
    expect($task->created_at)->toBeInstanceOf(Carbon::class);
    expect($task->created_at->toDateTimeString())->toBe('2024-01-01 12:00:00');
    expect($task->updated_at)->toBeInstanceOf(Carbon::class);
    expect($task->updated_at->toDateTimeString())->toBe('2024-01-01 12:00:00');
    expect($task->completed_at)->toBeNull();
    expect($task->started_at)->toBeNull();
});

test('isBlocked returns true when blocked_by is set', function (): void {
    $task = makeTask(['blocked_by' => 'f-xyz789']);
    expect($task->isBlocked())->toBeTrue();
});

test('isBlocked returns false when blocked_by is null', function (): void {
    $task = makeTask(['blocked_by' => null]);
    expect($task->isBlocked())->toBeFalse();
});

test('isBlocked returns false when blocked_by is empty string', function (): void {
    $task = makeTask(['blocked_by' => '']);
    expect($task->isBlocked())->toBeFalse();
});

test('isCompleted returns true when status is completed', function (): void {
    $task = makeTask(['status' => 'completed']);
    expect($task->isCompleted())->toBeTrue();
});

test('isCompleted returns false when status is not completed', function (): void {
    $pendingTask = makeTask(['status' => 'pending']);
    expect($pendingTask->isCompleted())->toBeFalse();

    $inProgressTask = makeTask(['status' => 'in_progress']);
    expect($inProgressTask->isCompleted())->toBeFalse();

    $failedTask = makeTask(['status' => 'failed']);
    expect($failedTask->isCompleted())->toBeFalse();
});

test('isInProgress returns true when status is in_progress', function (): void {
    $task = makeTask(['status' => 'in_progress']);
    expect($task->isInProgress())->toBeTrue();
});

test('isInProgress returns false when status is not in_progress', function (): void {
    $pendingTask = makeTask(['status' => 'pending']);
    expect($pendingTask->isInProgress())->toBeFalse();

    $completedTask = makeTask(['status' => 'completed']);
    expect($completedTask->isInProgress())->toBeFalse();

    $failedTask = makeTask(['status' => 'failed']);
    expect($failedTask->isInProgress())->toBeFalse();
});

test('isFailed returns true when status is failed', function (): void {
    $task = makeTask(['status' => 'failed']);
    expect($task->isFailed())->toBeTrue();
});

test('isFailed returns false when status is not failed', function (): void {
    $pendingTask = makeTask(['status' => 'pending']);
    expect($pendingTask->isFailed())->toBeFalse();

    $inProgressTask = makeTask(['status' => 'in_progress']);
    expect($inProgressTask->isFailed())->toBeFalse();

    $completedTask = makeTask(['status' => 'completed']);
    expect($completedTask->isFailed())->toBeFalse();
});

test('isPending returns true when status is pending', function (): void {
    $task = makeTask(['status' => 'pending']);
    expect($task->isPending())->toBeTrue();
});

test('isPending returns false when status is not pending', function (): void {
    $inProgressTask = makeTask(['status' => 'in_progress']);
    expect($inProgressTask->isPending())->toBeFalse();

    $completedTask = makeTask(['status' => 'completed']);
    expect($completedTask->isPending())->toBeFalse();

    $failedTask = makeTask(['status' => 'failed']);
    expect($failedTask->isPending())->toBeFalse();
});

test('getLabelsArray returns array of labels', function (): void {
    $task = makeTask(['labels' => 'api,urgent,testing']);
    expect($task->getLabelsArray())->toBe(['api', 'urgent', 'testing']);
});

test('getLabelsArray returns empty array when labels is null', function (): void {
    $task = makeTask(['labels' => null]);
    expect($task->getLabelsArray())->toBe([]);
});

test('getLabelsArray returns empty array when labels is empty string', function (): void {
    $task = makeTask(['labels' => '']);
    expect($task->getLabelsArray())->toBe([]);
});

test('getLabelsArray handles labels with spaces', function (): void {
    $task = makeTask(['labels' => 'api, urgent, testing']);
    expect($task->getLabelsArray())->toBe(['api', 'urgent', 'testing']);
});

test('getLabelsArray returns single label', function (): void {
    $task = makeTask(['labels' => 'api']);
    expect($task->getLabelsArray())->toBe(['api']);
});

test('getBlockedByArray returns array of task IDs', function (): void {
    $task = makeTask(['blocked_by' => 'f-abc123,f-xyz789,f-def456']);
    expect($task->getBlockedByArray())->toBe(['f-abc123', 'f-xyz789', 'f-def456']);
});

test('getBlockedByArray returns empty array when blocked_by is null', function (): void {
    $task = makeTask(['blocked_by' => null]);
    expect($task->getBlockedByArray())->toBe([]);
});

test('getBlockedByArray returns empty array when blocked_by is empty string', function (): void {
    $task = makeTask(['blocked_by' => '']);
    expect($task->getBlockedByArray())->toBe([]);
});

test('getBlockedByArray handles blocked_by with spaces', function (): void {
    $task = makeTask(['blocked_by' => 'f-abc123, f-xyz789, f-def456']);
    expect($task->getBlockedByArray())->toBe(['f-abc123', 'f-xyz789', 'f-def456']);
});

test('getBlockedByArray returns single task ID', function (): void {
    $task = makeTask(['blocked_by' => 'f-abc123']);
    expect($task->getBlockedByArray())->toBe(['f-abc123']);
});
