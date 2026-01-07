<?php

declare(strict_types=1);

use Laravel\Boost\Fuel\TaskService;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->storagePath = $this->tempDir.'/tasks.jsonl';
    $this->service = new TaskService($this->storagePath);
});

afterEach(function () {
    // Clean up all files in temp directory (including .lock file)
    if (is_dir($this->tempDir)) {
        $files = glob($this->tempDir.'/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }
});

it('generates hash-based IDs with fuel prefix', function () {
    $id = $this->service->generateId(0);

    expect($id)->toStartWith('fuel-')
        ->and(strlen($id))->toBe(9); // fuel- (5) + 4 chars
});

it('generates 4-char hash for less than 500 tasks', function () {
    $id = $this->service->generateId(100);
    expect(strlen($id))->toBe(9); // fuel- + 4 chars
});

it('generates 5-char hash for 500-1500 tasks', function () {
    $id = $this->service->generateId(750);
    expect(strlen($id))->toBe(10); // fuel- + 5 chars
});

it('generates 6-char hash for 1500-10000 tasks', function () {
    $id = $this->service->generateId(5000);
    expect(strlen($id))->toBe(11); // fuel- + 6 chars
});

it('creates tasks with all required fields', function () {
    $this->service->initialize();
    $task = $this->service->create([
        'title' => 'Test task',
        'type' => 'feature',
        'priority' => 1,
    ]);

    expect($task)
        ->toHaveKey('id')
        ->toHaveKey('title', 'Test task')
        ->toHaveKey('type', 'feature')
        ->toHaveKey('status', 'open')
        ->toHaveKey('priority', 1)
        ->toHaveKey('created_at')
        ->toHaveKey('updated_at')
        ->toHaveKey('dependencies', [])
        ->toHaveKey('labels', []);
});

it('finds task by exact ID', function () {
    $this->service->initialize();
    $created = $this->service->create(['title' => 'Test task']);

    $found = $this->service->find($created['id']);

    expect($found)->not->toBeNull()
        ->and($found['id'])->toBe($created['id']);
});

it('finds task by partial ID', function () {
    $this->service->initialize();
    $created = $this->service->create(['title' => 'Test task']);

    // Extract the hash part (without fuel- prefix)
    $hashPart = substr($created['id'], 5);
    $partialId = substr($hashPart, 0, 2);

    $found = $this->service->find($partialId);

    expect($found)->not->toBeNull()
        ->and($found['id'])->toBe($created['id']);
});

it('throws exception for ambiguous partial ID', function () {
    $this->service->initialize();

    // Create two tasks - they'll have different IDs
    $task1 = $this->service->create(['title' => 'Test 1']);
    $task2 = $this->service->create(['title' => 'Test 2']);

    // Try to find by just 'fuel' prefix
    $this->service->find('fuel');
})->throws(RuntimeException::class, 'Ambiguous task ID');

it('returns null for non-existent task', function () {
    $this->service->initialize();
    $found = $this->service->find('nonexistent');

    expect($found)->toBeNull();
});

it('updates task fields', function () {
    $this->service->initialize();
    $task = $this->service->create(['title' => 'Original title']);

    $updated = $this->service->update($task['id'], [
        'title' => 'Updated title',
        'priority' => 0,
    ]);

    expect($updated['title'])->toBe('Updated title')
        ->and($updated['priority'])->toBe(0);
});

it('closes task with reason', function () {
    $this->service->initialize();
    $task = $this->service->create(['title' => 'Test task']);

    $closed = $this->service->close($task['id'], 'Completed successfully');

    expect($closed['status'])->toBe('closed')
        ->and($closed['closed_reason'])->toBe('Completed successfully');
});

it('soft-deletes task with tombstone', function () {
    $this->service->initialize();
    $task = $this->service->create(['title' => 'Test task']);

    $this->service->delete($task['id']);

    // Task should no longer appear in all()
    $all = $this->service->all();
    expect($all->contains('id', $task['id']))->toBeFalse();
});

it('ready() returns open tasks with no blockers', function () {
    $this->service->initialize();

    $task1 = $this->service->create(['title' => 'Task 1']);
    $task2 = $this->service->create(['title' => 'Task 2']);

    $ready = $this->service->ready();

    expect($ready->count())->toBe(2)
        ->and($ready->pluck('id')->toArray())->toContain($task1['id'], $task2['id']);
});

it('ready() excludes blocked tasks', function () {
    $this->service->initialize();

    $blocker = $this->service->create(['title' => 'Blocker task']);
    $blocked = $this->service->create([
        'title' => 'Blocked task',
        'dependencies' => [['depends_on' => $blocker['id'], 'type' => 'blocks']],
    ]);

    $ready = $this->service->ready();

    expect($ready->count())->toBe(1)
        ->and($ready->first()['id'])->toBe($blocker['id']);
});

it('ready() excludes closed tasks', function () {
    $this->service->initialize();

    $task = $this->service->create(['title' => 'Test task']);
    $this->service->close($task['id']);

    $ready = $this->service->ready();

    expect($ready->count())->toBe(0);
});

it('ready() includes previously blocked tasks when blocker is closed', function () {
    $this->service->initialize();

    $blocker = $this->service->create(['title' => 'Blocker task']);
    $blocked = $this->service->create([
        'title' => 'Blocked task',
        'dependencies' => [['depends_on' => $blocker['id'], 'type' => 'blocks']],
    ]);

    // Close the blocker
    $this->service->close($blocker['id']);

    $ready = $this->service->ready();

    expect($ready->count())->toBe(1)
        ->and($ready->first()['id'])->toBe($blocked['id']);
});

it('ready() sorts by priority then created_at', function () {
    $this->service->initialize();

    $lowPriority = $this->service->create(['title' => 'Low', 'priority' => 3]);
    sleep(1); // Ensure different timestamps
    $highPriority = $this->service->create(['title' => 'High', 'priority' => 1]);

    $ready = $this->service->ready();

    expect($ready->first()['id'])->toBe($highPriority['id'])
        ->and($ready->last()['id'])->toBe($lowPriority['id']);
});

it('detects circular dependencies', function () {
    $this->service->initialize();

    $taskA = $this->service->create(['title' => 'Task A']);
    $taskB = $this->service->create(['title' => 'Task B']);

    // A depends on B
    $this->service->addDependency($taskA['id'], $taskB['id']);

    // B depends on A would create cycle
    $this->service->addDependency($taskB['id'], $taskA['id']);
})->throws(RuntimeException::class, 'Circular dependency detected');

it('writes tasks sorted by ID for merge-friendly output', function () {
    $this->service->initialize();

    // Create tasks - they'll have random hash IDs
    $this->service->create(['title' => 'Task 1']);
    $this->service->create(['title' => 'Task 2']);
    $this->service->create(['title' => 'Task 3']);

    // Read the file directly
    $content = file_get_contents($this->storagePath);
    $lines = array_filter(explode("\n", $content));

    // Extract IDs from each line
    $ids = array_map(function ($line) {
        $task = json_decode($line, true);

        return $task['id'];
    }, $lines);

    // Verify sorted
    $sortedIds = $ids;
    sort($sortedIds);

    expect($ids)->toBe($sortedIds);
});

it('uses atomic writes with temp file', function () {
    $this->service->initialize();

    $task = $this->service->create(['title' => 'Test task']);

    // Verify file exists and no temp file left behind
    expect(file_exists($this->storagePath))->toBeTrue()
        ->and(file_exists($this->storagePath.'.tmp'))->toBeFalse();
});

it('adds and removes dependencies', function () {
    $this->service->initialize();

    $task1 = $this->service->create(['title' => 'Task 1']);
    $task2 = $this->service->create(['title' => 'Task 2']);

    $this->service->addDependency($task2['id'], $task1['id']);

    $deps = $this->service->getDependencies($task2['id']);
    expect($deps->count())->toBe(1);

    $this->service->removeDependency($task2['id'], $task1['id']);

    $deps = $this->service->getDependencies($task2['id']);
    expect($deps->count())->toBe(0);
});

it('searches tasks with filters', function () {
    $this->service->initialize();

    $this->service->create(['title' => 'Bug 1', 'type' => 'bug', 'priority' => 1]);
    $this->service->create(['title' => 'Feature 1', 'type' => 'feature', 'priority' => 2]);
    $this->service->create(['title' => 'Bug 2', 'type' => 'bug', 'priority' => 2]);

    $bugs = $this->service->search(['type' => 'bug']);
    expect($bugs->count())->toBe(2);

    $p1 = $this->service->search(['priority' => 1]);
    expect($p1->count())->toBe(1);
});

it('has default max tasks of 50', function () {
    expect($this->service->getMaxTasks())->toBe(50);
});

it('allows setting max tasks', function () {
    $this->service->setMaxTasks(10);
    expect($this->service->getMaxTasks())->toBe(10);
});

it('prune removes tombstones', function () {
    $this->service->initialize();

    $task = $this->service->create(['title' => 'Task to delete']);
    $this->service->delete($task['id']);

    // Verify tombstone exists in raw file
    $content = file_get_contents($this->storagePath);
    expect($content)->toContain('tombstone');

    $pruned = $this->service->prune();

    expect($pruned)->toBe(1);

    // Verify tombstone removed from raw file
    $content = file_get_contents($this->storagePath);
    expect($content)->not->toContain('tombstone');
});

it('prune removes closed tasks when over limit', function () {
    $this->service->initialize();
    $this->service->setMaxTasks(3);

    // Create 5 tasks
    $task1 = $this->service->create(['title' => 'Task 1']);
    $task2 = $this->service->create(['title' => 'Task 2']);
    $task3 = $this->service->create(['title' => 'Task 3']);
    $task4 = $this->service->create(['title' => 'Task 4']);
    $task5 = $this->service->create(['title' => 'Task 5']);

    // Close 3 tasks
    $this->service->update($task1['id'], ['status' => 'closed']);
    $this->service->update($task2['id'], ['status' => 'closed']);
    $this->service->update($task3['id'], ['status' => 'closed']);

    // Now we have 5 tasks (3 closed, 2 open), max is 3
    // Prune should remove 2 closed tasks to get down to 3
    $pruned = $this->service->prune();

    expect($pruned)->toBe(2);

    $all = $this->service->all();
    expect($all->count())->toBe(3);

    // Verify the 2 open tasks remain
    expect($all->contains('id', $task4['id']))->toBeTrue();
    expect($all->contains('id', $task5['id']))->toBeTrue();

    // Verify only 1 closed task remains (one of task1, task2, task3)
    $closedRemaining = $all->filter(fn (array $t): bool => $t['status'] === 'closed');
    expect($closedRemaining->count())->toBe(1);
});

it('prune does not remove open tasks', function () {
    $this->service->initialize();
    $this->service->setMaxTasks(2);

    // Create 4 tasks, all open
    $task1 = $this->service->create(['title' => 'Task 1']);
    $task2 = $this->service->create(['title' => 'Task 2']);
    $task3 = $this->service->create(['title' => 'Task 3']);
    $task4 = $this->service->create(['title' => 'Task 4']);

    // Prune - should not remove any since all are open
    $pruned = $this->service->prune();

    expect($pruned)->toBe(0);
    expect($this->service->all()->count())->toBe(4);
});

it('prune only removes enough closed tasks to reach limit', function () {
    $this->service->initialize();
    $this->service->setMaxTasks(3);

    // Create 5 tasks, close 4 of them
    $task1 = $this->service->create(['title' => 'Task 1']);
    $task2 = $this->service->create(['title' => 'Task 2']);
    $task3 = $this->service->create(['title' => 'Task 3']);
    $task4 = $this->service->create(['title' => 'Task 4']);
    $task5 = $this->service->create(['title' => 'Open task']); // Keep this open

    // Close 4 tasks
    $this->service->update($task1['id'], ['status' => 'closed']);
    $this->service->update($task2['id'], ['status' => 'closed']);
    $this->service->update($task3['id'], ['status' => 'closed']);
    $this->service->update($task4['id'], ['status' => 'closed']);

    // 5 tasks total, max 3 -> need to remove 2
    $pruned = $this->service->prune();

    expect($pruned)->toBe(2);

    $all = $this->service->all();
    expect($all->count())->toBe(3);

    // Verify the 1 open task remains
    expect($all->contains('id', $task5['id']))->toBeTrue();

    // Verify exactly 2 closed tasks remain
    $closedRemaining = $all->filter(fn (array $t): bool => $t['status'] === 'closed');
    expect($closedRemaining->count())->toBe(2);
});

it('close() does not auto-prune tasks (prune is manual)', function () {
    $this->service->initialize();
    $this->service->setMaxTasks(2);

    // Create 3 tasks
    $task1 = $this->service->create(['title' => 'Task 1']);
    $task2 = $this->service->create(['title' => 'Task 2']);
    $task3 = $this->service->create(['title' => 'Task 3']);

    // Close task1 first
    $this->service->update($task1['id'], ['status' => 'closed']);

    // Close task2 - this should NOT auto-prune (prune is now manual to avoid git merge conflicts)
    $this->service->close($task2['id']);

    $all = $this->service->all();
    expect($all->count())->toBe(3); // All 3 tasks should remain

    // All tasks should still exist
    expect($all->contains('id', $task1['id']))->toBeTrue();
    expect($all->contains('id', $task2['id']))->toBeTrue();
    expect($all->contains('id', $task3['id']))->toBeTrue();

    // Manual prune should now remove the excess
    $pruned = $this->service->prune();
    expect($pruned)->toBe(1);

    $afterPrune = $this->service->all();
    expect($afterPrune->count())->toBe(2);
});
