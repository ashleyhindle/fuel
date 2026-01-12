<?php

declare(strict_types=1);

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Support\Carbon;

test('can create task with attributes', function (): void {
    $task = Task::create([
        'short_id' => 'f-abc123',
        'title' => 'Test Task',
        'description' => 'Test description',
        'type' => 'feature',
        'status' => TaskStatus::Open->value,
        'priority' => 1,
        'complexity' => 'simple',
        'labels' => ['api', 'urgent'],
        'blocked_by' => ['f-xyz789'],
    ]);

    expect($task)->toBeInstanceOf(Task::class);
    expect($task->short_id)->toBe('f-abc123');
    expect($task->title)->toBe('Test Task');
    expect($task->description)->toBe('Test description');
    expect($task->type)->toBe('feature');
    expect($task->status)->toBe(TaskStatus::Open);
    expect($task->priority)->toBe(1);
    expect($task->complexity)->toBe('simple');
    expect($task->labels)->toBe(['api', 'urgent']);
    expect($task->blocked_by)->toBe(['f-xyz789']);
    expect($task->epic_id)->toBeNull();
});

test('casts attributes correctly', function (): void {
    $task = Task::create([
        'short_id' => 'f-test01',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => '2', // String should be cast to integer
        'labels' => ['api', 'urgent'],
        'blocked_by' => ['f-xyz789'],
    ]);

    expect($task->priority)->toBeInt();
    expect($task->priority)->toBe(2);
    expect($task->labels)->toBeArray();
    expect($task->labels)->toBe(['api', 'urgent']);
    expect($task->blocked_by)->toBeArray();
    expect($task->blocked_by)->toBe(['f-xyz789']);
});

test('timestamps are Carbon instances', function (): void {
    $task = Task::create([
        'short_id' => 'f-test02',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 2,
    ]);

    expect($task->created_at)->toBeInstanceOf(Carbon::class);
    expect($task->updated_at)->toBeInstanceOf(Carbon::class);
});

test('isBlocked returns true when blocked_by is set', function (): void {
    $task = Task::create([
        'short_id' => 'f-test03',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 2,
        'blocked_by' => ['f-xyz789'],
    ]);

    expect($task->isBlocked())->toBeTrue();
});

test('isBlocked returns false when blocked_by is empty', function (): void {
    $task = Task::create([
        'short_id' => 'f-test04',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 2,
        'blocked_by' => [],
    ]);

    expect($task->isBlocked())->toBeFalse();
});

test('isBlocked returns false when blocked_by is null', function (): void {
    $task = Task::create([
        'short_id' => 'f-test05',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 2,
        'blocked_by' => null,
    ]);

    expect($task->isBlocked())->toBeFalse();
});

test('isCompleted returns true when status is closed', function (): void {
    $task = Task::create([
        'short_id' => 'f-test06',
        'title' => 'Test Task',
        'status' => TaskStatus::Closed->value,
        'type' => 'task',
        'priority' => 2,
    ]);

    expect($task->isCompleted())->toBeTrue();
});

test('isCompleted returns false when status is not closed', function (): void {
    $openTask = Task::create([
        'short_id' => 'f-test07',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 2,
    ]);
    expect($openTask->isCompleted())->toBeFalse();

    $inProgressTask = Task::create([
        'short_id' => 'f-test08',
        'title' => 'Test Task',
        'status' => TaskStatus::InProgress->value,
        'type' => 'task',
        'priority' => 2,
    ]);
    expect($inProgressTask->isCompleted())->toBeFalse();

    $cancelledTask = Task::create([
        'short_id' => 'f-test09',
        'title' => 'Test Task',
        'status' => TaskStatus::Cancelled->value,
        'type' => 'task',
        'priority' => 2,
    ]);
    expect($cancelledTask->isCompleted())->toBeFalse();
});

test('isInProgress returns true when status is in_progress', function (): void {
    $task = Task::create([
        'short_id' => 'f-test10',
        'title' => 'Test Task',
        'status' => TaskStatus::InProgress->value,
        'type' => 'task',
        'priority' => 2,
    ]);

    expect($task->isInProgress())->toBeTrue();
});

test('isInProgress returns false when status is not in_progress', function (): void {
    $openTask = Task::create([
        'short_id' => 'f-test11',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 2,
    ]);
    expect($openTask->isInProgress())->toBeFalse();

    $closedTask = Task::create([
        'short_id' => 'f-test12',
        'title' => 'Test Task',
        'status' => TaskStatus::Closed->value,
        'type' => 'task',
        'priority' => 2,
    ]);
    expect($closedTask->isInProgress())->toBeFalse();
});

// Note: isFailed() always returns false - tasks cannot have 'failed' status
test('isFailed always returns false for tasks', function (): void {
    $openTask = Task::create([
        'short_id' => 'f-test13',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 2,
    ]);
    expect($openTask->isFailed())->toBeFalse();

    $closedTask = Task::create([
        'short_id' => 'f-test14',
        'title' => 'Test Task',
        'status' => TaskStatus::Closed->value,
        'type' => 'task',
        'priority' => 2,
    ]);
    expect($closedTask->isFailed())->toBeFalse();
});

// Note: isPending() always returns false - tasks use 'open' status, not 'pending'
test('isPending always returns false for tasks', function (): void {
    $openTask = Task::create([
        'short_id' => 'f-test15',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 2,
    ]);
    expect($openTask->isPending())->toBeFalse();

    $closedTask = Task::create([
        'short_id' => 'f-test16',
        'title' => 'Test Task',
        'status' => TaskStatus::Closed->value,
        'type' => 'task',
        'priority' => 2,
    ]);
    expect($closedTask->isPending())->toBeFalse();
});

test('getLabelsArray returns array of labels', function (): void {
    $task = Task::create([
        'short_id' => 'f-test17',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 2,
        'labels' => ['api', 'urgent', 'testing'],
    ]);

    expect($task->getLabelsArray())->toBe(['api', 'urgent', 'testing']);
});

test('getLabelsArray returns empty array when labels is null', function (): void {
    $task = Task::create([
        'short_id' => 'f-test18',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 2,
        'labels' => null,
    ]);

    expect($task->getLabelsArray())->toBe([]);
});

test('getLabelsArray returns empty array when labels is empty array', function (): void {
    $task = Task::create([
        'short_id' => 'f-test19',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 2,
        'labels' => [],
    ]);

    expect($task->getLabelsArray())->toBe([]);
});

test('getBlockedByArray returns array of task IDs', function (): void {
    $task = Task::create([
        'short_id' => 'f-test20',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 2,
        'blocked_by' => ['f-abc123', 'f-xyz789', 'f-def456'],
    ]);

    expect($task->getBlockedByArray())->toBe(['f-abc123', 'f-xyz789', 'f-def456']);
});

test('getBlockedByArray returns empty array when blocked_by is null', function (): void {
    $task = Task::create([
        'short_id' => 'f-test21',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 2,
        'blocked_by' => null,
    ]);

    expect($task->getBlockedByArray())->toBe([]);
});

test('getBlockedByArray returns empty array when blocked_by is empty array', function (): void {
    $task = Task::create([
        'short_id' => 'f-test22',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 2,
        'blocked_by' => [],
    ]);

    expect($task->getBlockedByArray())->toBe([]);
});

test('findByPartialId finds task by numeric ID', function (): void {
    $task = Task::create([
        'short_id' => 'f-test23',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 1,
    ]);

    // Find by numeric ID (as string, which is how it comes from CLI)
    $found = Task::findByPartialId((string) $task->id);

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($task->id);
    expect($found->short_id)->toBe('f-test23');
});

test('findByPartialId finds task by full short_id', function (): void {
    $task = Task::create([
        'short_id' => 'f-test24',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 1,
    ]);

    $found = Task::findByPartialId('f-test24');

    expect($found)->not->toBeNull();
    expect($found->short_id)->toBe('f-test24');
});

test('findByPartialId finds task by partial short_id', function (): void {
    $task = Task::create([
        'short_id' => 'f-test25',
        'title' => 'Test Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 1,
    ]);

    // Find by partial short_id (without f- prefix)
    $found = Task::findByPartialId('test25');

    expect($found)->not->toBeNull();
    expect($found->short_id)->toBe('f-test25');
});

test('findByPartialId throws exception on ambiguous match', function (): void {
    Task::create([
        'short_id' => 'f-aaa111',
        'title' => 'Test Task 1',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 1,
    ]);

    Task::create([
        'short_id' => 'f-aaa222',
        'title' => 'Test Task 2',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 1,
    ]);

    // This should throw an exception because 'aaa' matches both
    expect(fn () => Task::findByPartialId('aaa'))
        ->toThrow(\RuntimeException::class, "Ambiguous task ID 'aaa'");
});

test('findByPartialId returns null for non-existent ID', function (): void {
    $found = Task::findByPartialId('f-notexist');
    expect($found)->toBeNull();

    $found = Task::findByPartialId('999999');
    expect($found)->toBeNull();
});

test('ready scope returns tasks that are open and not blocked', function (): void {
    // Create an open task that is not blocked
    $readyTask = Task::create([
        'short_id' => 'f-ready01',
        'title' => 'Ready Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 1,
        'blocked_by' => [],
    ]);

    // Create an open task that is blocked
    $blockedTask = Task::create([
        'short_id' => 'f-blocked01',
        'title' => 'Blocked Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 1,
        'blocked_by' => ['f-other'],
    ]);

    // Create a closed task
    $closedTask = Task::create([
        'short_id' => 'f-closed01',
        'title' => 'Closed Task',
        'status' => TaskStatus::Closed->value,
        'type' => 'task',
        'priority' => 1,
    ]);

    $readyTasks = Task::ready()->get();
    $readyIds = $readyTasks->pluck('short_id')->toArray();

    expect($readyIds)->toContain('f-ready01');
    expect($readyIds)->not->toContain('f-blocked01');
    expect($readyIds)->not->toContain('f-closed01');
});

test('blocked scope returns tasks that are blocked', function (): void {
    // Create a blocked task
    $blockedTask1 = Task::create([
        'short_id' => 'f-blocked02',
        'title' => 'Blocked Task 1',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 1,
        'blocked_by' => ['f-other'],
    ]);

    // Create another blocked task
    $blockedTask2 = Task::create([
        'short_id' => 'f-blocked03',
        'title' => 'Blocked Task 2',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 1,
        'blocked_by' => ['f-another', 'f-third'],
    ]);

    // Create a non-blocked task
    $notBlockedTask = Task::create([
        'short_id' => 'f-notblocked01',
        'title' => 'Not Blocked Task',
        'status' => TaskStatus::Open->value,
        'type' => 'task',
        'priority' => 1,
        'blocked_by' => [],
    ]);

    $blockedTasks = Task::blocked()->get();

    expect($blockedTasks->pluck('short_id')->toArray())->toContain('f-blocked02', 'f-blocked03');
    expect($blockedTasks->pluck('short_id')->toArray())->not->toContain('f-notblocked01');
});

test('fromArray compatibility method works', function (): void {
    // Test the backward compatibility method
    $task = Task::fromArray([
        'id' => 1,
        'short_id' => 'f-compat01',
        'title' => 'Compat Task',
        'description' => 'Test description',
        'type' => 'feature',
        'status' => TaskStatus::Open->value,
        'priority' => 1,
        'complexity' => 'simple',
        'labels' => '["api","urgent"]',
        'blocked_by' => '["f-xyz789"]',
        'epic_id' => 'e-epic01',
    ]);

    expect($task)->toBeInstanceOf(Task::class);
    expect($task->short_id)->toBe('f-compat01');
    expect($task->title)->toBe('Compat Task');
});
