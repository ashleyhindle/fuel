<?php

use App\Services\TaskService;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->storagePath = $this->tempDir.'/.fuel/tasks.jsonl';
    $this->taskService = new TaskService($this->storagePath);
});

afterEach(function () {
    // Clean up temp files
    $fuelDir = dirname($this->storagePath);
    if (file_exists($this->storagePath)) {
        unlink($this->storagePath);
    }
    if (file_exists($this->storagePath.'.lock')) {
        unlink($this->storagePath.'.lock');
    }
    if (file_exists($this->storagePath.'.tmp')) {
        unlink($this->storagePath.'.tmp');
    }
    if (is_dir($fuelDir)) {
        rmdir($fuelDir);
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

it('initializes storage directory and file', function () {
    $this->taskService->initialize();

    expect(file_exists($this->storagePath))->toBeTrue();
    expect(is_dir(dirname($this->storagePath)))->toBeTrue();
});

it('creates a task with hash-based ID', function () {
    $this->taskService->initialize();

    $task = $this->taskService->create(['title' => 'Test task']);

    expect($task['id'])->toStartWith('fuel-');
    expect(strlen($task['id']))->toBe(9); // fuel- + 4 chars
    expect($task['title'])->toBe('Test task');
    expect($task['status'])->toBe('open');
    expect($task['created_at'])->not->toBeNull();
    expect($task['updated_at'])->not->toBeNull();
});

it('creates a task with default schema fields', function () {
    $this->taskService->initialize();

    $task = $this->taskService->create(['title' => 'Test task']);

    // Verify all schema fields are present
    expect($task)->toHaveKeys(['id', 'title', 'status', 'description', 'type', 'priority', 'labels', 'size', 'blocked_by', 'created_at', 'updated_at']);
    expect($task['description'])->toBeNull();
    expect($task['type'])->toBe('task');
    expect($task['priority'])->toBe(2);
    expect($task['labels'])->toBe([]);
    expect($task['size'])->toBe('m');
    expect($task['blocked_by'])->toBe([]);
});

it('creates a task with custom schema fields', function () {
    $this->taskService->initialize();

    $task = $this->taskService->create([
        'title' => 'Bug fix',
        'description' => 'Fix the critical bug',
        'type' => 'bug',
        'priority' => 4,
        'labels' => ['critical', 'backend'],
        'size' => 'xl',
    ]);

    expect($task['title'])->toBe('Bug fix');
    expect($task['description'])->toBe('Fix the critical bug');
    expect($task['type'])->toBe('bug');
    expect($task['priority'])->toBe(4);
    expect($task['labels'])->toBe(['critical', 'backend']);
    expect($task['size'])->toBe('xl');
});

it('validates task type enum', function () {
    $this->taskService->initialize();

    // Valid types
    $validTypes = ['bug', 'feature', 'task', 'epic', 'chore'];
    foreach ($validTypes as $type) {
        $task = $this->taskService->create(['title' => 'Test', 'type' => $type]);
        expect($task['type'])->toBe($type);
    }

    // Invalid type
    $this->taskService->create(['title' => 'Test', 'type' => 'invalid']);
})->throws(RuntimeException::class, 'Invalid task type');

it('validates priority range', function () {
    $this->taskService->initialize();

    // Valid priorities
    for ($priority = 0; $priority <= 4; $priority++) {
        $task = $this->taskService->create(['title' => 'Test', 'priority' => $priority]);
        expect($task['priority'])->toBe($priority);
    }

    // Invalid priorities
    $this->taskService->create(['title' => 'Test', 'priority' => -1]);
})->throws(RuntimeException::class, 'Invalid priority');

it('validates priority is integer', function () {
    $this->taskService->initialize();

    $this->taskService->create(['title' => 'Test', 'priority' => 'high']);
})->throws(RuntimeException::class, 'Invalid priority');

it('validates labels is an array', function () {
    $this->taskService->initialize();

    $this->taskService->create(['title' => 'Test', 'labels' => 'not-an-array']);
})->throws(RuntimeException::class, 'Labels must be an array');

it('validates all labels are strings', function () {
    $this->taskService->initialize();

    $this->taskService->create(['title' => 'Test', 'labels' => [1, 2, 3]]);
})->throws(RuntimeException::class, 'All labels must be strings');

it('validates task size enum', function () {
    $this->taskService->initialize();

    // Valid sizes
    $validSizes = ['xs', 's', 'm', 'l', 'xl'];
    foreach ($validSizes as $size) {
        $task = $this->taskService->create(['title' => 'Test', 'size' => $size]);
        expect($task['size'])->toBe($size);
    }

    // Invalid size
    $this->taskService->create(['title' => 'Test', 'size' => 'invalid']);
})->throws(RuntimeException::class, 'Invalid task size');

it('finds task by exact ID', function () {
    $this->taskService->initialize();
    $created = $this->taskService->create(['title' => 'Test task']);

    $found = $this->taskService->find($created['id']);

    expect($found)->not->toBeNull();
    expect($found['id'])->toBe($created['id']);
});

it('finds task by partial ID', function () {
    $this->taskService->initialize();
    $created = $this->taskService->create(['title' => 'Test task']);

    // Extract just the hash part (after 'fuel-')
    $hashPart = substr($created['id'], 5, 2); // Just first 2 chars of hash

    $found = $this->taskService->find($hashPart);

    expect($found)->not->toBeNull();
    expect($found['id'])->toBe($created['id']);
});

it('throws exception for ambiguous partial ID', function () {
    $this->taskService->initialize();
    $this->taskService->create(['title' => 'Task 1']);
    $this->taskService->create(['title' => 'Task 2']);

    // Try to find with just 'fuel' prefix - should be ambiguous
    $this->taskService->find('fuel');
})->throws(RuntimeException::class, 'Ambiguous task ID');

it('returns null for non-existent task', function () {
    $this->taskService->initialize();

    $found = $this->taskService->find('nonexistent');

    expect($found)->toBeNull();
});

it('marks task as done', function () {
    $this->taskService->initialize();
    $created = $this->taskService->create(['title' => 'Test task']);

    $done = $this->taskService->done($created['id']);

    expect($done['status'])->toBe('closed');
    expect($done['updated_at'])->not->toBeNull();

    // Verify it's actually persisted
    $reloaded = $this->taskService->find($created['id']);
    expect($reloaded['status'])->toBe('closed');
});

it('throws exception when marking non-existent task as done', function () {
    $this->taskService->initialize();

    $this->taskService->done('nonexistent');
})->throws(RuntimeException::class, "Task 'nonexistent' not found");

it('returns only open tasks from ready()', function () {
    $this->taskService->initialize();
    $this->taskService->create(['title' => 'Open task']);
    $closed = $this->taskService->create(['title' => 'To be closed']);
    $this->taskService->done($closed['id']);

    $ready = $this->taskService->ready();

    expect($ready->count())->toBe(1);
    expect($ready->first()['title'])->toBe('Open task');
});

it('generates unique IDs', function () {
    $this->taskService->initialize();

    $ids = [];
    for ($i = 0; $i < 10; $i++) {
        $task = $this->taskService->create(['title' => "Task $i"]);
        $ids[] = $task['id'];
    }

    expect(count(array_unique($ids)))->toBe(10);
});

it('sorts tasks by ID when writing', function () {
    $this->taskService->initialize();

    // Create multiple tasks
    $this->taskService->create(['title' => 'Task 1']);
    $this->taskService->create(['title' => 'Task 2']);
    $this->taskService->create(['title' => 'Task 3']);

    // Read file directly and check sorting
    $content = file_get_contents($this->storagePath);
    $lines = array_filter(explode("\n", trim($content)));

    $ids = array_map(function ($line) {
        $task = json_decode($line, true);

        return $task['id'];
    }, $lines);

    $sortedIds = $ids;
    sort($sortedIds);

    expect($ids)->toBe($sortedIds);
});

it('throws exception when creating task without title', function () {
    $this->taskService->initialize();

    $this->taskService->create([]);
})->throws(RuntimeException::class, 'Task title is required');

it('can set custom storage path', function () {
    $customPath = $this->tempDir.'/custom/path/tasks.jsonl';
    $this->taskService->setStoragePath($customPath);

    expect($this->taskService->getStoragePath())->toBe($customPath);
});

it('returns empty collection when no tasks exist', function () {
    $this->taskService->initialize();

    $tasks = $this->taskService->all();

    expect($tasks)->toBeEmpty();
});

it('returns empty collection from ready() when no open tasks', function () {
    $this->taskService->initialize();

    $ready = $this->taskService->ready();

    expect($ready)->toBeEmpty();
});

// =============================================================================
// Dependency Management Tests
// =============================================================================

it('creates task with empty dependencies by default', function () {
    $this->taskService->initialize();

    $task = $this->taskService->create(['title' => 'Task with no deps']);

    expect($task['blocked_by'] ?? [])->toBeEmpty();
});

it('adds a blocks dependency between tasks', function () {
    $this->taskService->initialize();
    $blocker = $this->taskService->create(['title' => 'Blocker task']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    $this->taskService->addDependency($blocked['id'], $blocker['id']);

    $updated = $this->taskService->find($blocked['id']);
    expect($updated['blocked_by'])->toHaveCount(1);
    expect($updated['blocked_by'][0])->toBe($blocker['id']);
});

it('removes a dependency between tasks', function () {
    $this->taskService->initialize();
    $blocker = $this->taskService->create(['title' => 'Blocker task']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    $this->taskService->addDependency($blocked['id'], $blocker['id']);
    $this->taskService->removeDependency($blocked['id'], $blocker['id']);

    $updated = $this->taskService->find($blocked['id']);
    expect($updated['blocked_by'] ?? [])->toBeEmpty();
});

it('throws exception when adding dependency to non-existent task', function () {
    $this->taskService->initialize();
    $blocker = $this->taskService->create(['title' => 'Blocker task']);

    $this->taskService->addDependency('nonexistent', $blocker['id']);
})->throws(RuntimeException::class, 'not found');

it('throws exception when adding dependency on non-existent task', function () {
    $this->taskService->initialize();
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    $this->taskService->addDependency($blocked['id'], 'nonexistent');
})->throws(RuntimeException::class, 'not found');

it('throws exception when removing non-existent dependency', function () {
    $this->taskService->initialize();
    $task1 = $this->taskService->create(['title' => 'Task 1']);
    $task2 = $this->taskService->create(['title' => 'Task 2']);

    // No dependency exists between these tasks
    $this->taskService->removeDependency($task1['id'], $task2['id']);
})->throws(RuntimeException::class, 'No dependency exists');

// =============================================================================
// Cycle Detection Tests
// =============================================================================

it('detects simple cycle (A depends on B, B depends on A)', function () {
    $this->taskService->initialize();
    $taskA = $this->taskService->create(['title' => 'Task A']);
    $taskB = $this->taskService->create(['title' => 'Task B']);

    // A depends on B (B blocks A)
    $this->taskService->addDependency($taskA['id'], $taskB['id']);

    // Try to make B depend on A (A blocks B) - should detect cycle
    $this->taskService->addDependency($taskB['id'], $taskA['id']);
})->throws(RuntimeException::class, 'Circular dependency detected');

it('detects complex cycle (A->B->C->A)', function () {
    $this->taskService->initialize();
    $taskA = $this->taskService->create(['title' => 'Task A']);
    $taskB = $this->taskService->create(['title' => 'Task B']);
    $taskC = $this->taskService->create(['title' => 'Task C']);

    // A depends on B (B blocks A)
    $this->taskService->addDependency($taskA['id'], $taskB['id']);
    // B depends on C (C blocks B)
    $this->taskService->addDependency($taskB['id'], $taskC['id']);

    // Try to make C depend on A (A blocks C) - creates cycle A->B->C->A
    $this->taskService->addDependency($taskC['id'], $taskA['id']);
})->throws(RuntimeException::class, 'Circular dependency detected');

it('allows valid non-cyclic dependencies', function () {
    $this->taskService->initialize();
    $taskA = $this->taskService->create(['title' => 'Task A']);
    $taskB = $this->taskService->create(['title' => 'Task B']);
    $taskC = $this->taskService->create(['title' => 'Task C']);

    // Linear chain: A depends on B, B depends on C (C blocks B blocks A)
    $this->taskService->addDependency($taskA['id'], $taskB['id']);
    $this->taskService->addDependency($taskB['id'], $taskC['id']);

    // Verify the chain was created
    $updatedA = $this->taskService->find($taskA['id']);
    $updatedB = $this->taskService->find($taskB['id']);

    expect($updatedA['blocked_by'])->toHaveCount(1);
    expect($updatedA['blocked_by'][0])->toBe($taskB['id']);
    expect($updatedB['blocked_by'])->toHaveCount(1);
    expect($updatedB['blocked_by'][0])->toBe($taskC['id']);
});

// =============================================================================
// Ready Work with Dependencies Tests
// =============================================================================

it('excludes blocked tasks from ready()', function () {
    $this->taskService->initialize();
    $blocker = $this->taskService->create(['title' => 'Blocker task']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    // Blocked task depends on blocker (blocker blocks blocked)
    $this->taskService->addDependency($blocked['id'], $blocker['id']);

    $ready = $this->taskService->ready();

    // Only the blocker should be ready (blocked task has open dependency)
    expect($ready)->toHaveCount(1);
    expect($ready->first()['id'])->toBe($blocker['id']);
});

it('includes tasks when blocker is closed', function () {
    $this->taskService->initialize();
    $blocker = $this->taskService->create(['title' => 'Blocker task']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    // Blocked task depends on blocker
    $this->taskService->addDependency($blocked['id'], $blocker['id']);

    // Close the blocker
    $this->taskService->done($blocker['id']);

    $ready = $this->taskService->ready();

    // Only the blocked task should be ready now (blocker is closed)
    expect($ready)->toHaveCount(1);
    expect($ready->first()['id'])->toBe($blocked['id']);
});

it('returns task with no dependencies in ready()', function () {
    $this->taskService->initialize();
    $task = $this->taskService->create(['title' => 'Independent task']);

    $ready = $this->taskService->ready();

    expect($ready)->toHaveCount(1);
    expect($ready->first()['id'])->toBe($task['id']);
});

// =============================================================================
// blocked() Method Tests
// =============================================================================

it('returns only blocked tasks from blocked()', function () {
    $this->taskService->initialize();
    $blocker = $this->taskService->create(['title' => 'Blocker task']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);
    $unblocked = $this->taskService->create(['title' => 'Unblocked task']);

    // Blocked task depends on blocker
    $this->taskService->addDependency($blocked['id'], $blocker['id']);

    $blockedTasks = $this->taskService->blocked();

    // Only the blocked task should be returned
    expect($blockedTasks)->toHaveCount(1);
    expect($blockedTasks->first()['id'])->toBe($blocked['id']);
});

it('excludes tasks when blocker is closed from blocked()', function () {
    $this->taskService->initialize();
    $blocker = $this->taskService->create(['title' => 'Blocker task']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    // Blocked task depends on blocker
    $this->taskService->addDependency($blocked['id'], $blocker['id']);

    // Close the blocker
    $this->taskService->done($blocker['id']);

    $blockedTasks = $this->taskService->blocked();

    // No tasks should be blocked now (blocker is closed)
    expect($blockedTasks)->toHaveCount(0);
});

it('returns empty collection from blocked() when no blocked tasks', function () {
    $this->taskService->initialize();
    $this->taskService->create(['title' => 'Unblocked task']);

    $blockedTasks = $this->taskService->blocked();

    expect($blockedTasks)->toHaveCount(0);
});

it('excludes in_progress tasks from blocked()', function () {
    $this->taskService->initialize();
    $blocker = $this->taskService->create(['title' => 'Blocker task']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    // Blocked task depends on blocker
    $this->taskService->addDependency($blocked['id'], $blocker['id']);

    // Mark blocked task as in_progress
    $this->taskService->start($blocked['id']);

    $blockedTasks = $this->taskService->blocked();

    // in_progress tasks should not appear in blocked()
    expect($blockedTasks)->toHaveCount(0);
});

// =============================================================================
// Blocker Queries Tests
// =============================================================================

it('returns open blockers for a task', function () {
    $this->taskService->initialize();
    $blocker1 = $this->taskService->create(['title' => 'Blocker 1']);
    $blocker2 = $this->taskService->create(['title' => 'Blocker 2']);
    $blocked = $this->taskService->create(['title' => 'Blocked task']);

    // Add two blockers
    $this->taskService->addDependency($blocked['id'], $blocker1['id']);
    $this->taskService->addDependency($blocked['id'], $blocker2['id']);

    // Close one blocker
    $this->taskService->done($blocker1['id']);

    $blockers = $this->taskService->getBlockers($blocked['id']);

    // Only blocker2 should be returned (blocker1 is closed)
    expect($blockers)->toHaveCount(1);
    expect($blockers->first()['id'])->toBe($blocker2['id']);
});

it('returns empty collection when no blockers', function () {
    $this->taskService->initialize();
    $task = $this->taskService->create(['title' => 'Independent task']);

    $blockers = $this->taskService->getBlockers($task['id']);

    expect($blockers)->toBeEmpty();
});
