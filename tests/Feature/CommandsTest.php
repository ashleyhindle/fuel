<?php

use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->storagePath = $this->tempDir.'/.fuel/tasks.jsonl';

    // Bind our test TaskService instance
    $this->app->singleton(TaskService::class, function () {
        return new TaskService($this->storagePath);
    });

    $this->taskService = $this->app->make(TaskService::class);
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

// Add Command Tests
describe('add command', function () {
    it('creates a task via CLI', function () {
        $this->artisan('add', ['title' => 'My test task', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Created task: fuel-')
            ->assertExitCode(0);

        expect(file_exists($this->storagePath))->toBeTrue();
    });

    it('outputs JSON when --json flag is used', function () {
        Artisan::call('add', ['title' => 'JSON task', '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain('"status": "open"');
        expect($output)->toContain('"title": "JSON task"');
        expect($output)->toContain('"id": "fuel-');
    });

    it('creates task in custom cwd', function () {
        $this->artisan('add', ['title' => 'Custom path task', '--cwd' => $this->tempDir])
            ->assertExitCode(0);

        expect(file_exists($this->storagePath))->toBeTrue();

        $content = file_get_contents($this->storagePath);
        expect($content)->toContain('Custom path task');
    });
});

// Ready Command Tests
describe('ready command', function () {
    it('shows no tasks when empty', function () {
        $this->taskService->initialize();

        $this->artisan('ready', ['--cwd' => $this->tempDir])
            ->expectsOutput('No open tasks.')
            ->assertExitCode(0);
    });

    it('shows open tasks', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task one']);
        $this->taskService->create(['title' => 'Task two']);

        $this->artisan('ready', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Task one')
            ->expectsOutputToContain('Task two')
            ->assertExitCode(0);
    });

    it('excludes closed tasks', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Open task']);
        $closed = $this->taskService->create(['title' => 'Closed task']);
        $this->taskService->done($closed['id']);

        $this->artisan('ready', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Open task')
            ->doesntExpectOutputToContain('Closed task')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'JSON task']);

        $this->artisan('ready', ['--cwd' => $this->tempDir, '--json' => true])
            ->expectsOutputToContain('"title": "JSON task"')
            ->assertExitCode(0);
    });

    it('outputs empty array as JSON when no tasks', function () {
        $this->taskService->initialize();

        $this->artisan('ready', ['--cwd' => $this->tempDir, '--json' => true])
            ->expectsOutput('[]')
            ->assertExitCode(0);
    });
});

// Done Command Tests
describe('done command', function () {
    it('marks a task as done', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'To complete']);

        $this->artisan('done', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Completed task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
    });

    it('supports partial ID matching', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Partial ID task']);
        $partialId = substr($task['id'], 5, 3); // Just 3 chars of the hash

        $this->artisan('done', ['id' => $partialId, '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Completed task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
    });

    it('shows error for non-existent task', function () {
        $this->taskService->initialize();

        $this->artisan('done', ['id' => 'nonexistent', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON done task']);

        $this->artisan('done', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true])
            ->expectsOutputToContain('"status": "closed"')
            ->assertExitCode(0);
    });

    it('outputs JSON error for non-existent task with --json flag', function () {
        $this->taskService->initialize();

        $this->artisan('done', ['id' => 'nonexistent', '--cwd' => $this->tempDir, '--json' => true])
            ->expectsOutputToContain('"error":')
            ->assertExitCode(1);
    });
});

// =============================================================================
// dep:add Command Tests
// =============================================================================

describe('dep:add command', function () {
    it('adds dependency via CLI', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        $this->artisan('dep:add', [
            'from' => $blocked['id'],
            'to' => $blocker['id'],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Added dependency')
            ->assertExitCode(0);

        // Verify dependency was created
        $updated = $this->taskService->find($blocked['id']);
        expect($updated['dependencies'])->toHaveCount(1);
        expect($updated['dependencies'][0]['depends_on'])->toBe($blocker['id']);
    });

    it('dep:add outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        Artisan::call('dep:add', [
            'from' => $blocked['id'],
            'to' => $blocker['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);

        $output = Artisan::output();
        expect($output)->toContain($blocked['id']);
        expect($output)->toContain('depends_on');
    });

    it('dep:add shows error for non-existent task', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);

        $this->artisan('dep:add', [
            'from' => 'nonexistent',
            'to' => $blocker['id'],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('dep:add shows error for cycle detection', function () {
        $this->taskService->initialize();
        $taskA = $this->taskService->create(['title' => 'Task A']);
        $taskB = $this->taskService->create(['title' => 'Task B']);

        // A depends on B
        $this->taskService->addDependency($taskA['id'], $taskB['id']);

        // Try to make B depend on A (cycle)
        $this->artisan('dep:add', [
            'from' => $taskB['id'],
            'to' => $taskA['id'],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Circular dependency')
            ->assertExitCode(1);
    });

    it('dep:add supports partial ID matching', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Use partial IDs (just the hash part)
        $blockerPartial = substr($blocker['id'], 5, 3);
        $blockedPartial = substr($blocked['id'], 5, 3);

        $this->artisan('dep:add', [
            'from' => $blockedPartial,
            'to' => $blockerPartial,
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Added dependency')
            ->assertExitCode(0);

        // Verify dependency was created using full ID
        $updated = $this->taskService->find($blocked['id']);
        expect($updated['dependencies'])->toHaveCount(1);
    });
});

// =============================================================================
// dep:remove Command Tests
// =============================================================================

describe('dep:remove command', function () {
    it('removes dependency via CLI', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // First add a dependency
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        // Then remove it via CLI
        $this->artisan('dep:remove', [
            'from' => $blocked['id'],
            'to' => $blocker['id'],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Removed dependency')
            ->assertExitCode(0);

        // Verify dependency was removed
        $updated = $this->taskService->find($blocked['id']);
        expect($updated['dependencies'] ?? [])->toBeEmpty();
    });

    it('dep:remove outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // First add a dependency
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        Artisan::call('dep:remove', [
            'from' => $blocked['id'],
            'to' => $blocker['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);

        $output = Artisan::output();
        expect($output)->toContain($blocked['id']);
        expect($output)->toContain('dependencies');
    });

    it('dep:remove shows error for non-existent task', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);

        $this->artisan('dep:remove', [
            'from' => 'nonexistent',
            'to' => $blocker['id'],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('dep:remove shows error when no dependency exists', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        // Try to remove a dependency that doesn't exist
        $this->artisan('dep:remove', [
            'from' => $task1['id'],
            'to' => $task2['id'],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('No dependency exists')
            ->assertExitCode(1);
    });

    it('dep:remove supports partial ID matching', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // First add a dependency
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        // Use partial IDs (just the hash part)
        $blockerPartial = substr($blocker['id'], 5, 3);
        $blockedPartial = substr($blocked['id'], 5, 3);

        $this->artisan('dep:remove', [
            'from' => $blockedPartial,
            'to' => $blockerPartial,
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Removed dependency')
            ->assertExitCode(0);

        // Verify dependency was removed using full ID
        $updated = $this->taskService->find($blocked['id']);
        expect($updated['dependencies'] ?? [])->toBeEmpty();
    });
});

// =============================================================================
// ready Command with Dependencies Tests
// =============================================================================

describe('ready command with dependencies', function () {
    it('ready excludes tasks with open blockers', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add dependency: blocked depends on blocker
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        $this->artisan('ready', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Blocker task')
            ->doesntExpectOutputToContain('Blocked task')
            ->assertExitCode(0);
    });

    it('ready includes tasks when blocker is closed', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add dependency: blocked depends on blocker
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        // Close the blocker
        $this->taskService->done($blocker['id']);

        $this->artisan('ready', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Blocked task')
            ->doesntExpectOutputToContain('Blocker task')
            ->assertExitCode(0);
    });
});
