<?php

use App\Services\DatabaseService;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);
    $this->dbPath = $this->tempDir.'/.fuel/agent.db';
    $this->databaseService = new DatabaseService($this->dbPath);
    $this->databaseService->initialize();

    $this->taskService = makeTaskService($this->databaseService);
});

afterEach(function (): void {
    // Clean up temp files
    $fuelDir = $this->tempDir.'/.fuel';
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }

    // Clean up WAL and SHM files if present
    if (file_exists($this->dbPath.'-wal')) {
        unlink($this->dbPath.'-wal');
    }

    if (file_exists($this->dbPath.'-shm')) {
        unlink($this->dbPath.'-shm');
    }

    if (is_dir($fuelDir)) {
        rmdir($fuelDir);
    }

    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

it('creates a task with hash-based ID', function (): void {
    $task = $this->taskService->create(['title' => 'Test task']);

    expect($task->short_id)->toStartWith('f-');
    expect(strlen((string) $task->short_id))->toBe(8); // f- + 6 chars
    expect($task->title)->toBe('Test task');
    expect($task->status)->toBe(App\Enums\TaskStatus::Open->value);
    expect($task->created_at)->not->toBeNull();
    expect($task->updated_at)->not->toBeNull();
});

it('creates a task with default schema fields', function (): void {
    $task = $this->taskService->create(['title' => 'Test task']);

    // Verify all schema fields are present
    expect($task->toArray())->toHaveKeys(['id', 'title', 'status', 'description', 'type', 'priority', 'labels', 'complexity', 'blocked_by', 'created_at', 'updated_at']);
    expect($task->description)->toBeNull();
    expect($task->type)->toBe('task');
    expect($task->priority)->toBe(2);
    expect($task->labels)->toBe([]);
    expect($task->complexity)->toBe('simple');
    expect($task->blocked_by)->toBe([]);
});

it('creates a task with custom schema fields', function (): void {
    $task = $this->taskService->create([
        'title' => 'Bug fix',
        'description' => 'Fix the critical bug',
        'type' => 'bug',
        'priority' => 4,
        'labels' => ['critical', 'backend'],
    ]);

    expect($task->title)->toBe('Bug fix');
    expect($task->description)->toBe('Fix the critical bug');
    expect($task->type)->toBe('bug');
    expect($task->priority)->toBe(4);
    expect($task->labels)->toBe(['critical', 'backend']);
});

it('validates task type enum', function (): void {
    // Valid types
    $validTypes = ['bug', 'feature', 'task', 'epic', 'chore', 'docs', 'test', 'refactor'];
    foreach ($validTypes as $type) {
        $task = $this->taskService->create(['title' => 'Test', 'type' => $type]);
        expect($task->type)->toBe($type);
    }

    // Invalid type
    $this->taskService->create(['title' => 'Test', 'type' => 'invalid']);
})->throws(RuntimeException::class, 'Invalid task type');

it('validates priority range', function (): void {
    // Valid priorities
    for ($priority = 0; $priority <= 4; $priority++) {
        $task = $this->taskService->create(['title' => 'Test', 'priority' => $priority]);
        expect($task->priority)->toBe($priority);
    }

    // Invalid priorities
    $this->taskService->create(['title' => 'Test', 'priority' => -1]);
})->throws(RuntimeException::class, 'Invalid priority');

it('validates priority is integer', function (): void {
    $this->taskService->create(['title' => 'Test', 'priority' => 'high']);
})->throws(RuntimeException::class, 'Invalid priority');

it('validates labels is an array', function (): void {
    $this->taskService->create(['title' => 'Test', 'labels' => 'not-an-array']);
})->throws(RuntimeException::class, 'Labels must be an array');

it('validates all labels are strings', function (): void {
    $this->taskService->create(['title' => 'Test', 'labels' => [1, 2, 3]]);
})->throws(RuntimeException::class, 'All labels must be strings');

it('validates task complexity enum', function (): void {
    // Valid complexities
    $validComplexities = ['trivial', 'simple', 'moderate', 'complex'];
    foreach ($validComplexities as $complexity) {
        $task = $this->taskService->create(['title' => 'Test', 'complexity' => $complexity]);
        expect($task->complexity)->toBe($complexity);
    }

    // Invalid complexity
    $this->taskService->create(['title' => 'Test', 'complexity' => 'invalid']);
})->throws(RuntimeException::class, 'Invalid task complexity');

it('validates task status enum', function (): void {
    $task = $this->taskService->create(['title' => 'Test task']);

    // Valid statuses
    $validStatuses = ['open', 'in_progress', 'review', 'closed', 'cancelled', 'someday'];
    foreach ($validStatuses as $status) {
        $updated = $this->taskService->update($task->short_id, ['status' => $status]);
        expect($updated->status)->toBe($status);
    }

    // Invalid status
    $this->taskService->update($task->short_id, ['status' => 'invalid']);
})->throws(RuntimeException::class, 'Invalid status');

it('defaults complexity to simple when not provided', function (): void {
    $task = $this->taskService->create(['title' => 'Test task']);

    expect($task->complexity)->toBe('simple');
});

it('finds task by exact ID', function (): void {
    $created = $this->taskService->create(['title' => 'Test task']);

    $found = $this->taskService->find($created->short_id);

    expect($found)->not->toBeNull();
    expect($found->short_id)->toBe($created->short_id);
});

it('finds task by partial ID', function (): void {
    $created = $this->taskService->create(['title' => 'Test task']);

    // Extract just the hash part (after 'f-')
    $hashPart = substr((string) $created->short_id, 2, 2); // Just first 2 chars of hash

    $found = $this->taskService->find($hashPart);

    expect($found)->not->toBeNull();
    expect($found->short_id)->toBe($created->short_id);
});

it('finds task by partial ID with f- prefix', function (): void {
    $created = $this->taskService->create(['title' => 'Test task']);

    // Use partial hash with f- prefix
    $hashPart = substr((string) $created->short_id, 2, 3); // First 3 chars of hash
    $partialId = 'f-'.$hashPart;

    $found = $this->taskService->find($partialId);

    expect($found)->not->toBeNull();
    expect($found->short_id)->toBe($created->short_id);
});

it('finds task by partial ID matching full ID prefix', function (): void {
    $created = $this->taskService->create(['title' => 'Test task']);

    // Use partial ID that matches the start of the full ID
    $partialId = substr((string) $created->short_id, 0, 5); // First 5 chars: "f-d60"

    $found = $this->taskService->find($partialId);

    expect($found)->not->toBeNull();
    expect($found->short_id)->toBe($created->short_id);
});

it('finds old format task by partial ID with fuel- prefix', function (): void {
    // Manually create a task with old 'fuel-' prefix format via direct SQL
    $oldFormatId = 'fuel-'.substr(uniqid('', true), 0, 6);
    $now = now()->toIso8601String();

    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status, type, priority, complexity, labels, blocked_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$oldFormatId, 'Old format task', 'open', 'task', 2, 'simple', '[]', '[]', $now, $now]
    );

    // Try to find using partial ID with fuel- prefix
    $hashPart = substr($oldFormatId, 5, 3); // First 3 chars of hash
    $partialId = 'fuel-'.$hashPart;

    $found = $this->taskService->find($partialId);

    expect($found)->not->toBeNull();
    expect($found->short_id)->toBe($oldFormatId);
});

it('finds old format task by partial hash only', function (): void {
    // Manually create a task with old 'fuel-' prefix format via direct SQL
    $oldFormatId = 'fuel-'.substr(uniqid('', true), 0, 6);
    $now = now()->toIso8601String();

    $this->databaseService->query(
        'INSERT INTO tasks (short_id, title, status, type, priority, complexity, labels, blocked_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$oldFormatId, 'Old format task', 'open', 'task', 2, 'simple', '[]', '[]', $now, $now]
    );

    // Try to find using just the hash part
    $hashPart = substr($oldFormatId, 5, 3); // First 3 chars of hash

    $found = $this->taskService->find($hashPart);

    expect($found)->not->toBeNull();
    expect($found->short_id)->toBe($oldFormatId);
});

it('throws exception for ambiguous partial ID', function (): void {
    $this->taskService->create(['title' => 'Task 1']);
    $this->taskService->create(['title' => 'Task 2']);

    // Try to find with just 'f' prefix - should be ambiguous
    $this->taskService->find('f');
})->throws(RuntimeException::class, 'Ambiguous task ID');

it('returns null for non-existent task', function (): void {
    $found = $this->taskService->find('nonexistent');

    expect($found)->toBeNull();
});

it('marks task as done', function (): void {
    $created = $this->taskService->create(['title' => 'Test task']);

    $done = $this->taskService->done($created->short_id);

    expect($done->status)->toBe(App\Enums\TaskStatus::Closed->value);
    expect($done->updated_at)->not->toBeNull();

    // Verify it's actually persisted
    $reloaded = $this->taskService->find($created->short_id);
    expect($reloaded->status)->toBe(App\Enums\TaskStatus::Closed->value);
});

it('throws exception when marking non-existent task as done', function (): void {
    $this->taskService->done('nonexistent');
})->throws(RuntimeException::class, "Task 'nonexistent' not found");

it('returns only open tasks from ready()', function (): void {
    $this->taskService->create(['title' => 'Open task']);

    $closed = $this->taskService->create(['title' => 'To be closed']);
    $this->taskService->done($closed->short_id);

    $ready = $this->taskService->ready();

    expect($ready->count())->toBe(1);
    expect($ready->first()->title)->toBe('Open task');
});

it('generates unique IDs', function (): void {
    $ids = [];
    for ($i = 0; $i < 10; $i++) {
        $task = $this->taskService->create(['title' => 'Task '.$i]);
        $ids[] = $task->short_id;
    }

    expect(count(array_unique($ids)))->toBe(10);
});

it('generates unique IDs with collision detection', function (): void {
    // Create many tasks to exercise collision detection
    $ids = [];
    for ($i = 0; $i < 100; $i++) {
        $task = $this->taskService->create(['title' => 'Task '.$i]);
        $ids[] = $task->short_id;
    }

    // All IDs should be unique
    expect(count(array_unique($ids)))->toBe(100);
});

it('generateId works when called directly without parameters', function (): void {
    // Should work without parameters (backward compatibility)
    $id = $this->taskService->generateId();

    expect($id)->toStartWith('f-');
    expect(strlen((string) $id))->toBe(8); // f- + 6 chars
});

it('throws exception when creating task without title', function (): void {
    $this->taskService->create([]);
})->throws(RuntimeException::class, 'Task title is required');

it('returns empty collection when no tasks exist', function (): void {
    $tasks = $this->taskService->all();

    expect($tasks)->toBeEmpty();
});

it('returns empty collection from ready() when no open tasks', function (): void {
    $ready = $this->taskService->ready();

    expect($ready)->toBeEmpty();
});

// =============================================================================
// Dependency Management Tests
// =============================================================================

it('creates task with empty blocked_by array by default', function (): void {
    $task = $this->taskService->create(['title' => 'Task with no deps']);

    expect($task->blocked_by ?? [])->toBeEmpty();
});

it('adds blocker to blocked_by array', function (): void {
    $blocker = $this->taskService->create(['title' => 'Blocker task']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

    $updated = $this->taskService->find($blocked->short_id);
    expect($updated->blocked_by)->toHaveCount(1);
    expect($updated->blocked_by[0])->toBe($blocker->short_id);
});

it('removes a dependency between tasks', function (): void {
    $blocker = $this->taskService->create(['title' => 'Blocker task']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    $this->taskService->addDependency($blocked->short_id, $blocker->short_id);
    $this->taskService->removeDependency($blocked->short_id, $blocker->short_id);

    $updated = $this->taskService->find($blocked->short_id);
    expect($updated->blocked_by ?? [])->toBeEmpty();
});

it('throws exception when adding dependency to non-existent task', function (): void {
    $blocker = $this->taskService->create(['title' => 'Blocker task']);

    $this->taskService->addDependency('nonexistent', $blocker->short_id);
})->throws(RuntimeException::class, 'not found');

it('throws exception when adding dependency on non-existent task', function (): void {
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    $this->taskService->addDependency($blocked->short_id, 'nonexistent');
})->throws(RuntimeException::class, 'not found');

it('throws exception when removing non-existent dependency', function (): void {
    $task1 = $this->taskService->create(['title' => 'Task 1']);
    $task2 = $this->taskService->create(['title' => 'Task 2']);

    // No dependency exists between these tasks
    $this->taskService->removeDependency($task1->short_id, $task2->short_id);
})->throws(RuntimeException::class, 'No dependency exists');

// =============================================================================
// Cycle Detection Tests
// =============================================================================

it('detects simple cycle (A depends on B, B depends on A)', function (): void {
    $taskA = $this->taskService->create(['title' => 'Task A']);
    $taskB = $this->taskService->create(['title' => 'Task B']);

    // A depends on B (B blocks A)
    $this->taskService->addDependency($taskA->short_id, $taskB->short_id);

    // Try to make B depend on A (A blocks B) - should detect cycle
    $this->taskService->addDependency($taskB->short_id, $taskA->short_id);
})->throws(RuntimeException::class, 'Circular dependency detected');

it('detects complex cycle (A->B->C->A)', function (): void {
    $taskA = $this->taskService->create(['title' => 'Task A']);
    $taskB = $this->taskService->create(['title' => 'Task B']);
    $taskC = $this->taskService->create(['title' => 'Task C']);

    // A depends on B (B blocks A)
    $this->taskService->addDependency($taskA->short_id, $taskB->short_id);
    // B depends on C (C blocks B)
    $this->taskService->addDependency($taskB->short_id, $taskC->short_id);

    // Try to make C depend on A (A blocks C) - creates cycle A->B->C->A
    $this->taskService->addDependency($taskC->short_id, $taskA->short_id);
})->throws(RuntimeException::class, 'Circular dependency detected');

it('allows valid non-cyclic dependencies', function (): void {
    $taskA = $this->taskService->create(['title' => 'Task A']);
    $taskB = $this->taskService->create(['title' => 'Task B']);
    $taskC = $this->taskService->create(['title' => 'Task C']);

    // Linear chain: A depends on B, B depends on C (C blocks B blocks A)
    $this->taskService->addDependency($taskA->short_id, $taskB->short_id);
    $this->taskService->addDependency($taskB->short_id, $taskC->short_id);

    // Verify the chain was created
    $updatedA = $this->taskService->find($taskA->short_id);
    $updatedB = $this->taskService->find($taskB->short_id);

    expect($updatedA->blocked_by)->toHaveCount(1);
    expect($updatedA->blocked_by[0])->toBe($taskB->short_id);
    expect($updatedB->blocked_by)->toHaveCount(1);
    expect($updatedB->blocked_by[0])->toBe($taskC->short_id);
});

// =============================================================================
// Ready Work with Dependencies Tests
// =============================================================================

it('excludes blocked tasks from ready()', function (): void {
    $blocker = $this->taskService->create(['title' => 'Blocker task']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
    $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

    $ready = $this->taskService->ready();

    // Only the blocker should be ready (blocked task has open dependency)
    expect($ready)->toHaveCount(1);
    expect($ready->first()->short_id)->toBe($blocker->short_id);
});

it('includes tasks when blocker is closed', function (): void {
    $blocker = $this->taskService->create(['title' => 'Blocker task']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    // Blocked task depends on blocker
    $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

    // Close the blocker
    $this->taskService->done($blocker->short_id);

    $ready = $this->taskService->ready();

    // Only the blocked task should be ready now (blocker is closed)
    expect($ready)->toHaveCount(1);
    expect($ready->first()->short_id)->toBe($blocked->short_id);
});

it('returns task with no blockers in ready()', function (): void {
    $task = $this->taskService->create(['title' => 'Independent task']);

    $ready = $this->taskService->ready();

    expect($ready)->toHaveCount(1);
    expect($ready->first()->short_id)->toBe($task->short_id);
});

it('excludes tasks with needs-human label from ready()', function (): void {
    $normalTask = $this->taskService->create(['title' => 'Normal task']);
    $needsHumanTask = $this->taskService->create([
        'title' => 'Needs human task',
        'labels' => ['needs-human'],
    ]);
    $multiLabelTask = $this->taskService->create([
        'title' => 'Task with multiple labels',
        'labels' => ['bug', 'needs-human', 'urgent'],
    ]);

    $ready = $this->taskService->ready();

    // Only the normal task should be ready
    expect($ready)->toHaveCount(1);
    expect($ready->first()->short_id)->toBe($normalTask->short_id);
    expect($ready->pluck('short_id'))->not->toContain($needsHumanTask->short_id);
    expect($ready->pluck('short_id'))->not->toContain($multiLabelTask->short_id);
});

// =============================================================================
// blocked() Method Tests
// =============================================================================

it('returns only blocked tasks from blocked()', function (): void {
    $blocker = $this->taskService->create(['title' => 'Blocker task']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);
    $unblocked = $this->taskService->create(['title' => 'Unblocked task']);

    // Blocked task depends on blocker
    $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

    $blockedTasks = $this->taskService->blocked();

    // Only the blocked task should be returned
    expect($blockedTasks)->toHaveCount(1);
    expect($blockedTasks->first()->short_id)->toBe($blocked->short_id);
});

it('excludes tasks when blocker is closed from blocked()', function (): void {
    $blocker = $this->taskService->create(['title' => 'Blocker task']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    // Blocked task depends on blocker
    $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

    // Close the blocker
    $this->taskService->done($blocker->short_id);

    $blockedTasks = $this->taskService->blocked();

    // No tasks should be blocked now (blocker is closed)
    expect($blockedTasks)->toHaveCount(0);
});

it('returns empty collection from blocked() when no blocked tasks', function (): void {
    $this->taskService->create(['title' => 'Unblocked task']);

    $blockedTasks = $this->taskService->blocked();

    expect($blockedTasks)->toHaveCount(0);
});

it('excludes in_progress tasks from blocked()', function (): void {
    $blocker = $this->taskService->create(['title' => 'Blocker task']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    // Blocked task depends on blocker
    $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

    // Mark blocked task as in_progress
    $this->taskService->start($blocked->short_id);

    $blockedTasks = $this->taskService->blocked();

    // in_progress tasks should not appear in blocked()
    expect($blockedTasks)->toHaveCount(0);
});

// =============================================================================
// Blocker Queries Tests
// =============================================================================

it('returns open blockers for a task', function (): void {
    $blocker1 = $this->taskService->create(['title' => 'Blocker 1']);
    $blocker2 = $this->taskService->create(['title' => 'Blocker 2']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    // Add two blockers
    $this->taskService->addDependency($blocked->short_id, $blocker1->short_id);
    $this->taskService->addDependency($blocked->short_id, $blocker2->short_id);

    // Close one blocker
    $this->taskService->done($blocker1->short_id);

    $blockers = $this->taskService->getBlockers($blocked->short_id);

    // Only blocker2 should be returned (blocker1 is closed)
    expect($blockers)->toHaveCount(1);
    expect($blockers->first()->short_id)->toBe($blocker2->short_id);
});

it('returns empty collection when no blockers', function (): void {
    $task = $this->taskService->create(['title' => 'Independent task']);

    $blockers = $this->taskService->getBlockers($task->short_id);

    expect($blockers)->toBeEmpty();
});

// =============================================================================
// Update Method Tests
// =============================================================================

it('preserves complexity when updating task without providing complexity', function (): void {
    $task = $this->taskService->create([
        'title' => 'Test task',
        'complexity' => 'moderate',
    ]);

    // Update without providing complexity
    $updated = $this->taskService->update($task->short_id, [
        'title' => 'Updated title',
    ]);

    expect($updated->complexity)->toBe('moderate');
    expect($updated->title)->toBe('Updated title');

    // Verify it's persisted
    $reloaded = $this->taskService->find($task->short_id);
    expect($reloaded->complexity)->toBe('moderate');
});

it('preserves arbitrary fields when updating a task', function (): void {
    $task = $this->taskService->create(['title' => 'Test task']);

    // Update with arbitrary fields (like consume command does)
    $updated = $this->taskService->update($task->short_id, [
        'consumed' => true,
        'consumed_at' => '2026-01-07T13:51:11+00:00',
        'consumed_exit_code' => 1,
        'consumed_output' => 'Some agent output here',
    ]);

    expect($updated->consumed)->toBeTrue();
    expect($updated->consumed_at)->toBe('2026-01-07T13:51:11+00:00');
    expect($updated->consumed_exit_code)->toBe(1);
    expect($updated->consumed_output)->toBe('Some agent output here');

    // Verify it's persisted
    $reloaded = $this->taskService->find($task->short_id);
    expect($reloaded->consumed)->toBeTrue();
    expect($reloaded->consumed_exit_code)->toBe(1);
    expect($reloaded->consumed_output)->toBe('Some agent output here');
});

// =============================================================================
// Delete Method Tests
// =============================================================================

it('deletes a task', function (): void {
    $task = $this->taskService->create(['title' => 'Task to delete']);

    $deleted = $this->taskService->delete($task->short_id);

    expect($deleted->short_id)->toBe($task->short_id);
    expect($this->taskService->find($task->short_id))->toBeNull();
});

it('throws exception when deleting non-existent task', function (): void {
    $this->taskService->delete('nonexistent');
})->throws(RuntimeException::class, "Task 'nonexistent' not found");

// =============================================================================
// Consume Fields Persistence Tests
// =============================================================================

it('persists consume_pid field correctly', function (): void {
    $task = $this->taskService->create(['title' => 'Task with PID']);

    $updated = $this->taskService->update($task->short_id, [
        'consume_pid' => 12345,
    ]);

    expect($updated->consume_pid)->toBe(12345);

    $reloaded = $this->taskService->find($task->short_id);
    expect($reloaded->consume_pid)->toBe(12345);
});

it('persists all consume fields together', function (): void {
    $task = $this->taskService->create(['title' => 'Task with consume fields']);

    $updated = $this->taskService->update($task->short_id, [
        'consumed' => true,
        'consumed_at' => '2026-01-10T10:00:00+00:00',
        'consumed_exit_code' => 0,
        'consumed_output' => 'Success output',
        'consume_pid' => 99999,
    ]);

    expect($updated->consumed)->toBeTrue();
    expect($updated->consumed_at)->toBe('2026-01-10T10:00:00+00:00');
    expect($updated->consumed_exit_code)->toBe(0);
    expect($updated->consumed_output)->toBe('Success output');
    expect($updated->consume_pid)->toBe(99999);

    $reloaded = $this->taskService->find($task->short_id);
    expect($reloaded->consumed)->toBeTrue();
    expect($reloaded->consumed_at)->toBe('2026-01-10T10:00:00+00:00');
    expect($reloaded->consumed_exit_code)->toBe(0);
    expect($reloaded->consumed_output)->toBe('Success output');
    expect($reloaded->consume_pid)->toBe(99999);
});

// =============================================================================
// Last Review Issues Tests
// =============================================================================

it('sets last review issues on a task', function (): void {
    $task = $this->taskService->create(['title' => 'Task for review']);

    $updated = $this->taskService->setLastReviewIssues($task->short_id, [
        'uncommitted_changes',
        'tests_failing',
    ]);

    expect($updated->last_review_issues)->toBe(['uncommitted_changes', 'tests_failing']);

    $reloaded = $this->taskService->find($task->short_id);
    expect($reloaded->last_review_issues)->toBe(['uncommitted_changes', 'tests_failing']);
});

it('clears last review issues when set to null', function (): void {
    $task = $this->taskService->create(['title' => 'Task for review']);

    // Set issues first
    $this->taskService->setLastReviewIssues($task->short_id, ['uncommitted_changes']);

    // Now clear them
    $updated = $this->taskService->setLastReviewIssues($task->short_id, null);

    expect($updated->last_review_issues ?? null)->toBeNull();

    $reloaded = $this->taskService->find($task->short_id);
    expect($reloaded->last_review_issues ?? null)->toBeNull();
});

it('clears last review issues when task is marked done', function (): void {
    $task = $this->taskService->create(['title' => 'Task for review']);

    // Set issues first
    $this->taskService->setLastReviewIssues($task->short_id, ['uncommitted_changes', 'tests_failing']);

    // Mark as done
    $done = $this->taskService->done($task->short_id);

    // Issues should be cleared
    expect($done->last_review_issues ?? null)->toBeNull();

    $reloaded = $this->taskService->find($task->short_id);
    expect($reloaded->last_review_issues ?? null)->toBeNull();
});

it('preserves last review issues when task is reopened', function (): void {
    $task = $this->taskService->create(['title' => 'Task for review']);

    // Start and consume the task
    $this->taskService->start($task->short_id);
    $this->taskService->update($task->short_id, ['consumed' => true]);

    // Set issues
    $this->taskService->setLastReviewIssues($task->short_id, ['uncommitted_changes']);

    // Reopen the task
    $this->taskService->reopen($task->short_id);

    // Issues should still be present
    $reloaded = $this->taskService->find($task->short_id);
    expect($reloaded->last_review_issues)->toBe(['uncommitted_changes']);
});

it('returns task without last_review_issues key when field is null', function (): void {
    $task = $this->taskService->create(['title' => 'Task without issues']);

    // Field should not be present when null
    expect(isset($task->last_review_issues))->toBeFalse();

    $reloaded = $this->taskService->find($task->short_id);
    expect(isset($reloaded->last_review_issues))->toBeFalse();
});

// =============================================================================
// Backlog Management Tests (status=someday)
// =============================================================================

it('backlog() returns only tasks with status=someday', function (): void {
    $openTask = $this->taskService->create(['title' => 'Open task']);
    $somedayTask1 = $this->taskService->create(['title' => 'Someday task 1']);
    $this->taskService->update($somedayTask1->short_id, ['status' => 'someday']);
    $somedayTask2 = $this->taskService->create(['title' => 'Someday task 2']);
    $this->taskService->update($somedayTask2->short_id, ['status' => 'someday']);
    $closedTask = $this->taskService->create(['title' => 'Closed task']);
    $this->taskService->done($closedTask->short_id);

    $backlog = $this->taskService->backlog();

    expect($backlog)->toHaveCount(2);
    expect($backlog->pluck('title')->toArray())->toContain('Someday task 1');
    expect($backlog->pluck('title')->toArray())->toContain('Someday task 2');
    expect($backlog->pluck('title')->toArray())->not->toContain('Open task');
    expect($backlog->pluck('title')->toArray())->not->toContain('Closed task');
});

it('backlog() returns empty collection when no someday tasks exist', function (): void {
    $this->taskService->create(['title' => 'Open task']);
    $closed = $this->taskService->create(['title' => 'Closed task']);
    $this->taskService->done($closed->short_id);

    $backlog = $this->taskService->backlog();

    expect($backlog)->toBeEmpty();
});

it('backlog() orders tasks by created_at', function (): void {
    $task1 = $this->taskService->create(['title' => 'First someday']);
    $this->taskService->update($task1->short_id, ['status' => 'someday']);

    sleep(1); // Ensure different timestamps

    $task2 = $this->taskService->create(['title' => 'Second someday']);
    $this->taskService->update($task2->short_id, ['status' => 'someday']);

    $backlog = $this->taskService->backlog();

    expect($backlog)->toHaveCount(2);
    expect($backlog->first()->title)->toBe('First someday');
    expect($backlog->last()->title)->toBe('Second someday');
});

it('promote() changes task status from someday to open', function (): void {
    $task = $this->taskService->create(['title' => 'Future idea']);
    $this->taskService->update($task->short_id, ['status' => 'someday']);

    $promoted = $this->taskService->promote($task->short_id);

    expect($promoted->status)->toBe(App\Enums\TaskStatus::Open->value);
    expect($promoted->title)->toBe('Future idea');

    // Verify it's persisted
    $reloaded = $this->taskService->find($task->short_id);
    expect($reloaded->status)->toBe(App\Enums\TaskStatus::Open->value);
});

it('promote() works with partial ID', function (): void {
    $task = $this->taskService->create(['title' => 'Future feature']);
    $this->taskService->update($task->short_id, ['status' => 'someday']);
    $partialId = substr((string) $task->short_id, 2, 3);

    $promoted = $this->taskService->promote($partialId);

    expect($promoted->short_id)->toBe($task->short_id);
    expect($promoted->status)->toBe(App\Enums\TaskStatus::Open->value);
});

it('promote() throws exception when task not found', function (): void {
    $this->taskService->promote('f-nonexistent');
})->throws(RuntimeException::class, "Task 'f-nonexistent' not found");

it('promote() throws exception when task status is not someday', function (): void {
    $task = $this->taskService->create(['title' => 'Open task']);

    $this->taskService->promote($task->short_id);
})->throws(RuntimeException::class, 'is not a backlog item');

it('defer() changes task status to someday', function (): void {
    $task = $this->taskService->create(['title' => 'Task to defer']);

    $deferred = $this->taskService->defer($task->short_id);

    expect($deferred->status)->toBe(App\Enums\TaskStatus::Someday->value);
    expect($deferred->title)->toBe('Task to defer');

    // Verify it's persisted
    $reloaded = $this->taskService->find($task->short_id);
    expect($reloaded->status)->toBe(App\Enums\TaskStatus::Someday->value);
});

it('defer() works with partial ID', function (): void {
    $task = $this->taskService->create(['title' => 'Task to defer']);
    $partialId = substr((string) $task->short_id, 2, 3);

    $deferred = $this->taskService->defer($partialId);

    expect($deferred->short_id)->toBe($task->short_id);
    expect($deferred->status)->toBe(App\Enums\TaskStatus::Someday->value);
});

it('defer() works on already someday tasks (idempotent)', function (): void {
    $task = $this->taskService->create(['title' => 'Already someday']);
    $this->taskService->update($task->short_id, ['status' => 'someday']);

    $deferred = $this->taskService->defer($task->short_id);

    expect($deferred->status)->toBe(App\Enums\TaskStatus::Someday->value);
});

it('defer() throws exception when task not found', function (): void {
    $this->taskService->defer('f-nonexistent');
})->throws(RuntimeException::class, "Task 'f-nonexistent' not found");

it('defer() preserves task metadata when changing status', function (): void {
    $task = $this->taskService->create([
        'title' => 'Complex task',
        'description' => 'Detailed description',
        'type' => 'feature',
        'priority' => 3,
        'labels' => ['backend', 'urgent'],
        'complexity' => 'moderate',
    ]);

    $deferred = $this->taskService->defer($task->short_id);

    expect($deferred->status)->toBe(App\Enums\TaskStatus::Someday->value);
    expect($deferred->title)->toBe('Complex task');
    expect($deferred->description)->toBe('Detailed description');
    expect($deferred->type)->toBe('feature');
    expect($deferred->priority)->toBe(3);
    expect($deferred->labels)->toBe(['backend', 'urgent']);
    expect($deferred->complexity)->toBe('moderate');
});
