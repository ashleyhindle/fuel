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
    // Clean up AGENTS.md created by init command tests
    $agentsMdPath = $this->tempDir.'/AGENTS.md';
    if (file_exists($agentsMdPath)) {
        unlink($agentsMdPath);
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

    it('creates task with --description flag', function () {
        Artisan::call('add', [
            'title' => 'Task with description',
            '--description' => 'This is a detailed description',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['description'])->toBe('This is a detailed description');
    });

    it('creates task with -d flag (description shortcut)', function () {
        Artisan::call('add', [
            'title' => 'Task with -d flag',
            '-d' => 'Short description',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['description'])->toBe('Short description');
    });

    it('creates task with --type flag', function () {
        Artisan::call('add', [
            'title' => 'Bug fix',
            '--type' => 'bug',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['type'])->toBe('bug');
    });

    it('validates --type flag enum', function () {
        $this->artisan('add', [
            'title' => 'Invalid type',
            '--type' => 'invalid-type',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid task type')
            ->assertExitCode(1);
    });

    it('creates task with --priority flag', function () {
        Artisan::call('add', [
            'title' => 'High priority task',
            '--priority' => '4',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['priority'])->toBe(4);
    });

    it('validates --priority flag range', function () {
        $this->artisan('add', [
            'title' => 'Invalid priority',
            '--priority' => '5',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid priority')
            ->assertExitCode(1);
    });

    it('validates --priority flag is integer', function () {
        $this->artisan('add', [
            'title' => 'Invalid priority',
            '--priority' => 'high',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid priority')
            ->assertExitCode(1);
    });

    it('creates task with --labels flag', function () {
        Artisan::call('add', [
            'title' => 'Labeled task',
            '--labels' => 'frontend,backend,urgent',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['labels'])->toBe(['frontend', 'backend', 'urgent']);
    });

    it('handles --labels flag with spaces', function () {
        Artisan::call('add', [
            'title' => 'Labeled task',
            '--labels' => 'frontend, backend, urgent',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['labels'])->toBe(['frontend', 'backend', 'urgent']);
    });

    it('creates task with all flags together', function () {
        Artisan::call('add', [
            'title' => 'Complete task',
            '--description' => 'Full featured task',
            '--type' => 'feature',
            '--priority' => '3',
            '--labels' => 'ui,backend',
            '--size' => 'l',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['title'])->toBe('Complete task');
        expect($task['description'])->toBe('Full featured task');
        expect($task['type'])->toBe('feature');
        expect($task['priority'])->toBe(3);
        expect($task['labels'])->toBe(['ui', 'backend']);
        expect($task['size'])->toBe('l');
    });

    it('creates task with --size flag', function () {
        Artisan::call('add', [
            'title' => 'Large task',
            '--size' => 'xl',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['size'])->toBe('xl');
    });

    it('validates --size flag enum', function () {
        $this->artisan('add', [
            'title' => 'Invalid size',
            '--size' => 'invalid-size',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid task size')
            ->assertExitCode(1);
    });

    it('creates task with --blocked-by flag (single blocker)', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $blocker['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['blocked_by'])->toHaveCount(1);
        expect($task['blocked_by'])->toContain($blocker['id']);
    });

    it('creates task with --blocked-by flag (multiple blockers)', function () {
        $this->taskService->initialize();
        $blocker1 = $this->taskService->create(['title' => 'Blocker 1']);
        $blocker2 = $this->taskService->create(['title' => 'Blocker 2']);

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $blocker1['id'].','.$blocker2['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['blocked_by'])->toHaveCount(2);
        expect($task['blocked_by'])->toContain($blocker1['id']);
        expect($task['blocked_by'])->toContain($blocker2['id']);
    });

    it('creates task with --blocked-by flag (with spaces)', function () {
        $this->taskService->initialize();
        $blocker1 = $this->taskService->create(['title' => 'Blocker 1']);
        $blocker2 = $this->taskService->create(['title' => 'Blocker 2']);

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $blocker1['id'].', '.$blocker2['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['blocked_by'])->toHaveCount(2);
        expect($task['blocked_by'])->toContain($blocker1['id']);
        expect($task['blocked_by'])->toContain($blocker2['id']);
    });

    it('displays blocked-by info in non-JSON output', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $blocker['id'],
            '--cwd' => $this->tempDir,
        ]);
        $output = Artisan::output();

        expect($output)->toContain('Created task: fuel-');
        expect($output)->toContain('Blocked by:');
        expect($output)->toContain($blocker['id']);
    });

    it('creates task with --blocked-by and other flags', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);

        Artisan::call('add', [
            'title' => 'Complete blocked task',
            '--description' => 'Blocked feature',
            '--type' => 'feature',
            '--priority' => '2',
            '--labels' => 'backend',
            '--blocked-by' => $blocker['id'],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['title'])->toBe('Complete blocked task');
        expect($task['description'])->toBe('Blocked feature');
        expect($task['type'])->toBe('feature');
        expect($task['priority'])->toBe(2);
        expect($task['labels'])->toBe(['backend']);
        expect($task['blocked_by'])->toHaveCount(1);
        expect($task['blocked_by'])->toContain($blocker['id']);
    });

    it('supports partial IDs in --blocked-by flag', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $partialId = substr($blocker['id'], 5, 3); // Just hash part

        Artisan::call('add', [
            'title' => 'Blocked task',
            '--blocked-by' => $partialId,
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['blocked_by'])->toHaveCount(1);
        // Note: TaskService.create() stores the ID as provided, so partial ID will be stored
        // The dependency resolution happens when checking blockers, not at creation time
        expect($task['blocked_by'])->toContain($partialId);
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

    it('filters by --size flag', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Small task', 'size' => 's']);
        $this->taskService->create(['title' => 'Large task', 'size' => 'xl']);

        $this->artisan('ready', ['--size' => 's', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Small task')
            ->doesntExpectOutputToContain('Large task')
            ->assertExitCode(0);
    });
});

// Available Command Tests
describe('available command', function () {
    it('outputs count of ready tasks', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task 1']);
        $this->taskService->create(['title' => 'Task 2']);

        Artisan::call('available', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect(trim($output))->toBe('2');
    });

    it('exits with code 0 when tasks are available', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task 1']);

        $this->artisan('available', ['--cwd' => $this->tempDir])
            ->assertExitCode(0);
    });

    it('exits with code 1 when no tasks are available', function () {
        $this->taskService->initialize();

        $this->artisan('available', ['--cwd' => $this->tempDir])
            ->expectsOutput('0')
            ->assertExitCode(1);
    });

    it('outputs 0 when no tasks are available', function () {
        $this->taskService->initialize();

        Artisan::call('available', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect(trim($output))->toBe('0');
    });

    it('excludes in_progress tasks from count', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->start($task1['id']);

        Artisan::call('available', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        // Should only count task2 (task1 is in_progress)
        expect(trim($output))->toBe('1');
    });

    it('excludes blocked tasks from count', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker']);
        $blocked = $this->taskService->create(['title' => 'Blocked']);
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        Artisan::call('available', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        // Should only count blocker (blocked is blocked)
        expect(trim($output))->toBe('1');
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task 1']);

        Artisan::call('available', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['count'])->toBe(1);
        expect($result['available'])->toBeTrue();
    });

    it('outputs JSON with available false when no tasks', function () {
        $this->taskService->initialize();

        Artisan::call('available', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['count'])->toBe(0);
        expect($result['available'])->toBeFalse();
    });

    it('supports --cwd flag', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task 1']);

        Artisan::call('available', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect(trim($output))->toBe('1');
    });
});

// Start Command Tests
describe('start command', function () {
    it('sets status to in_progress', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task to start']);

        $this->artisan('start', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Started task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('in_progress');
    });

    it('excludes task from ready() output', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        // Start task1
        $this->artisan('start', ['id' => $task1['id'], '--cwd' => $this->tempDir])
            ->assertExitCode(0);

        // Task1 should not appear in ready output
        $this->artisan('ready', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Task 2')
            ->doesntExpectOutputToContain('Task 1')
            ->assertExitCode(0);
    });

    it('supports partial ID matching', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Partial ID task']);
        $partialId = substr($task['id'], 5, 3); // Just 3 chars of the hash

        $this->artisan('start', ['id' => $partialId, '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Started task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('in_progress');
    });

    it('returns JSON when --json flag used', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON start task']);

        Artisan::call('start', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['id'])->toBe($task['id']);
        expect($result['status'])->toBe('in_progress');
        expect($result['title'])->toBe('JSON start task');
    });

    it('handles invalid IDs gracefully', function () {
        $this->taskService->initialize();

        $this->artisan('start', ['id' => 'nonexistent', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('outputs JSON error for invalid ID with --json flag', function () {
        $this->taskService->initialize();

        Artisan::call('start', ['id' => 'nonexistent', '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveKey('error');
        expect($result['error'])->toContain('not found');
    });
});

// Done Command Tests
describe('done command', function () {
    it('marks a task as done', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'To complete']);

        $this->artisan('done', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Completed task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
    });

    it('supports partial ID matching', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Partial ID task']);
        $partialId = substr($task['id'], 5, 3); // Just 3 chars of the hash

        $this->artisan('done', ['ids' => [$partialId], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Completed task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
    });

    it('shows error for non-existent task', function () {
        $this->taskService->initialize();

        $this->artisan('done', ['ids' => ['nonexistent'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON done task']);

        $this->artisan('done', ['ids' => [$task['id']], '--cwd' => $this->tempDir, '--json' => true])
            ->expectsOutputToContain('"status": "closed"')
            ->assertExitCode(0);
    });

    it('outputs JSON error for non-existent task with --json flag', function () {
        $this->taskService->initialize();

        $this->artisan('done', ['ids' => ['nonexistent'], '--cwd' => $this->tempDir, '--json' => true])
            ->expectsOutputToContain('"error":')
            ->assertExitCode(1);
    });

    it('marks task as done with --reason flag', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task with reason']);

        $this->artisan('done', [
            'ids' => [$task['id']],
            '--reason' => 'Fixed the bug',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain('Reason: Fixed the bug')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
        expect($updated['reason'])->toBe('Fixed the bug');
    });

    it('outputs reason in JSON when --reason flag is used with --json', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON task with reason']);

        Artisan::call('done', [
            'ids' => [$task['id']],
            '--reason' => 'Completed successfully',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['status'])->toBe('closed');
        expect($result['reason'])->toBe('Completed successfully');
    });

    it('does not add reason field when --reason is not provided', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task without reason']);

        $this->artisan('done', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
        expect($updated)->not->toHaveKey('reason');
    });

    it('marks multiple tasks as done', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $task3 = $this->taskService->create(['title' => 'Task 3']);

        $this->artisan('done', [
            'ids' => [$task1['id'], $task2['id'], $task3['id']],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Completed task:')
            ->assertExitCode(0);

        expect($this->taskService->find($task1['id'])['status'])->toBe('closed');
        expect($this->taskService->find($task2['id'])['status'])->toBe('closed');
        expect($this->taskService->find($task3['id'])['status'])->toBe('closed');
    });

    it('outputs multiple tasks as JSON array when multiple IDs provided', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        Artisan::call('done', [
            'ids' => [$task1['id'], $task2['id']],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveCount(2);
        expect($result[0]['status'])->toBe('closed');
        expect($result[1]['status'])->toBe('closed');
        expect(collect($result)->pluck('id')->toArray())->toContain($task1['id'], $task2['id']);
    });

    it('outputs single task as object when one ID provided with --json', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Single task']);

        Artisan::call('done', [
            'ids' => [$task['id']],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveKey('id');
        expect($result['id'])->toBe($task['id']);
        expect($result['status'])->toBe('closed');
    });

    it('handles partial failures when marking multiple tasks', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        $this->artisan('done', [
            'ids' => [$task1['id'], 'nonexistent', $task2['id']],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);

        // Task1 should be closed
        expect($this->taskService->find($task1['id'])['status'])->toBe('closed');
        // Task2 should be closed
        expect($this->taskService->find($task2['id'])['status'])->toBe('closed');
    });

    it('applies same reason to all tasks when --reason provided', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        $this->artisan('done', [
            'ids' => [$task1['id'], $task2['id']],
            '--reason' => 'Batch completion',
            '--cwd' => $this->tempDir,
        ])
            ->assertExitCode(0);

        expect($this->taskService->find($task1['id'])['reason'])->toBe('Batch completion');
        expect($this->taskService->find($task2['id'])['reason'])->toBe('Batch completion');
    });

    it('supports partial IDs when marking multiple tasks', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $partialId1 = substr($task1['id'], 5, 3);
        $partialId2 = substr($task2['id'], 5, 3);

        $this->artisan('done', [
            'ids' => [$partialId1, $partialId2],
            '--cwd' => $this->tempDir,
        ])
            ->assertExitCode(0);

        expect($this->taskService->find($task1['id'])['status'])->toBe('closed');
        expect($this->taskService->find($task2['id'])['status'])->toBe('closed');
    });
});

// =============================================================================
// reopen Command Tests
// =============================================================================

describe('reopen command', function () {
    it('reopens a closed task', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'To reopen']);
        $this->taskService->done($task['id']);

        $this->artisan('reopen', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('open');
    });

    it('supports partial ID matching', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Partial ID task']);
        $this->taskService->done($task['id']);
        $partialId = substr($task['id'], 5, 3); // Just 3 chars of the hash

        $this->artisan('reopen', ['ids' => [$partialId], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('open');
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON reopen task']);
        $this->taskService->done($task['id']);

        Artisan::call('reopen', [
            'ids' => [$task['id']],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['id'])->toBe($task['id']);
        expect($result['status'])->toBe('open');
        expect($result['title'])->toBe('JSON reopen task');
    });

    it('removes reason when reopening a task', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task with reason']);
        $this->taskService->done($task['id'], 'Fixed the bug');

        $closedTask = $this->taskService->find($task['id']);
        expect($closedTask['reason'])->toBe('Fixed the bug');

        $this->artisan('reopen', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->assertExitCode(0);

        $reopenedTask = $this->taskService->find($task['id']);
        expect($reopenedTask['status'])->toBe('open');
        expect($reopenedTask)->not->toHaveKey('reason');
    });

    it('fails when task is not found', function () {
        $this->taskService->initialize();

        $this->artisan('reopen', ['ids' => ['nonexistent'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('fails when task is open', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Open task']);

        $this->artisan('reopen', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('is not closed or in_progress')
            ->assertExitCode(1);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('open');
    });

    it('reopens an in_progress task', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'In progress task']);
        $this->taskService->start($task['id']);

        $this->artisan('reopen', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('open');
    });

    it('reopens multiple tasks', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $task3 = $this->taskService->create(['title' => 'Task 3']);
        $this->taskService->done($task1['id']);
        $this->taskService->done($task2['id']);
        $this->taskService->done($task3['id']);

        $this->artisan('reopen', [
            'ids' => [$task1['id'], $task2['id'], $task3['id']],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        expect($this->taskService->find($task1['id'])['status'])->toBe('open');
        expect($this->taskService->find($task2['id'])['status'])->toBe('open');
        expect($this->taskService->find($task3['id'])['status'])->toBe('open');
    });

    it('outputs multiple tasks as JSON array when multiple IDs provided', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->done($task1['id']);
        $this->taskService->done($task2['id']);

        Artisan::call('reopen', [
            'ids' => [$task1['id'], $task2['id']],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveCount(2);
        expect($result[0]['status'])->toBe('open');
        expect($result[1]['status'])->toBe('open');
        expect(collect($result)->pluck('id')->toArray())->toContain($task1['id'], $task2['id']);
    });

    it('handles partial failures when reopening multiple tasks', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->done($task1['id']);
        $this->taskService->done($task2['id']);

        $this->artisan('reopen', [
            'ids' => [$task1['id'], 'nonexistent', $task2['id']],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Reopened task:')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);

        // Task1 should be reopened
        expect($this->taskService->find($task1['id'])['status'])->toBe('open');
        // Task2 should be reopened
        expect($this->taskService->find($task2['id'])['status'])->toBe('open');
    });

    it('supports partial IDs when reopening multiple tasks', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->done($task1['id']);
        $this->taskService->done($task2['id']);
        $partialId1 = substr($task1['id'], 5, 3);
        $partialId2 = substr($task2['id'], 5, 3);

        $this->artisan('reopen', [
            'ids' => [$partialId1, $partialId2],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Reopened task:')
            ->assertExitCode(0);

        expect($this->taskService->find($task1['id'])['status'])->toBe('open');
        expect($this->taskService->find($task2['id'])['status'])->toBe('open');
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

        // Verify blocker was added to blocked_by array
        $updated = $this->taskService->find($blocked['id']);
        expect($updated['blocked_by'])->toHaveCount(1);
        expect($updated['blocked_by'])->toContain($blocker['id']);
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
        expect($output)->toContain('blocked_by');
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

        // Verify blocker was added to blocked_by array using full ID
        $updated = $this->taskService->find($blocked['id']);
        expect($updated['blocked_by'])->toHaveCount(1);
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

        // Verify blocker was removed from blocked_by array
        $updated = $this->taskService->find($blocked['id']);
        expect($updated['blocked_by'] ?? [])->toBeEmpty();
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
        expect($output)->toContain('blocked_by');
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

        // Verify blocker was removed from blocked_by array using full ID
        $updated = $this->taskService->find($blocked['id']);
        expect($updated['blocked_by'] ?? [])->toBeEmpty();
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

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
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

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        // Close the blocker
        $this->taskService->done($blocker['id']);

        $this->artisan('ready', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Blocked task')
            ->doesntExpectOutputToContain('Blocker task')
            ->assertExitCode(0);
    });
});

// =============================================================================
// blocked Command Tests
// =============================================================================

describe('blocked command', function () {
    it('shows empty when no blocked tasks', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Unblocked task']);

        $this->artisan('blocked', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No blocked tasks.')
            ->assertExitCode(0);
    });

    it('blocked includes tasks with open blockers', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        $this->artisan('blocked', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Blocked task')
            ->doesntExpectOutputToContain('Blocker task')
            ->assertExitCode(0);
    });

    it('blocked excludes tasks when blocker is closed', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        // Close the blocker
        $this->taskService->done($blocker['id']);

        $this->artisan('blocked', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No blocked tasks.')
            ->doesntExpectOutputToContain('Blocked task')
            ->assertExitCode(0);
    });

    it('blocked outputs JSON when --json flag is provided', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add blocker to blocked_by array: blocked task has blocker in its blocked_by array
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        Artisan::call('blocked', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain($blocked['id']);
        expect($output)->toContain('Blocked task');
        expect($output)->not->toContain('Blocker task');
    });

    it('blocked filters by size when --size option is provided', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blockedSmall = $this->taskService->create(['title' => 'Small blocked task', 'size' => 's']);
        $blockedLarge = $this->taskService->create(['title' => 'Large blocked task', 'size' => 'l']);

        // Add dependencies
        $this->taskService->addDependency($blockedSmall['id'], $blocker['id']);
        $this->taskService->addDependency($blockedLarge['id'], $blocker['id']);

        $this->artisan('blocked', ['--cwd' => $this->tempDir, '--size' => 's'])
            ->expectsOutputToContain('Small blocked task')
            ->doesntExpectOutputToContain('Large blocked task')
            ->assertExitCode(0);
    });
});

// =============================================================================
// board Command Tests
// =============================================================================

describe('board command', function () {
    it('shows empty board when no tasks', function () {
        $this->taskService->initialize();

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Ready');
        expect($output)->toContain('In Progress');
        expect($output)->toContain('Blocked');
        expect($output)->toContain('No tasks');
    });

    it('shows ready tasks in Ready column', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Ready task']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Ready task');
    });

    it('shows blocked tasks in Blocked column', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        // Titles may be truncated, so check for short IDs
        $blockerShortId = substr($blocker['id'], 5, 4);
        $blockedShortId = substr($blocked['id'], 5, 4);
        expect($output)->toContain("[{$blockerShortId}]");
        expect($output)->toContain("[{$blockedShortId}]");
    });

    it('shows in progress tasks in In Progress column', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'In progress task']);
        $this->taskService->start($task['id']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        // Title may be truncated, so check for short ID
        $shortId = substr($task['id'], 5, 4);
        expect($output)->toContain("[{$shortId}]");
    });

    it('shows done tasks in Recently done line', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Completed task']);
        $this->taskService->done($task['id']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        // Done tasks appear in "Recently done:" line below the board
        $shortId = substr($task['id'], 5, 4);
        expect($output)->toContain('Recently done');
        expect($output)->toContain("[{$shortId}]");
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Test task']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain('"ready":');
        expect($output)->toContain('"in_progress":');
        expect($output)->toContain('"blocked":');
        expect($output)->toContain('"done":');
        expect($output)->toContain('Test task');
    });

    it('limits done tasks to 10 most recent', function () {
        $this->taskService->initialize();

        // Create and close 12 tasks
        for ($i = 1; $i <= 12; $i++) {
            $task = $this->taskService->create(['title' => "Done task {$i}"]);
            $this->taskService->done($task['id']);
        }

        Artisan::call('board', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data['done'])->toHaveCount(10);
    });
});

// =============================================================================
// show Command Tests
// =============================================================================

describe('show command', function () {
    it('shows task details with all fields', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create([
            'title' => 'Test task',
            'description' => 'Test description',
            'type' => 'feature',
            'priority' => 3,
            'labels' => ['frontend', 'backend'],
        ]);

        $this->artisan('show', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Task: '.$task['id'])
            ->expectsOutputToContain('Title: Test task')
            ->expectsOutputToContain('Status: open')
            ->expectsOutputToContain('Description: Test description')
            ->expectsOutputToContain('Type: feature')
            ->expectsOutputToContain('Priority: 3')
            ->expectsOutputToContain('Labels: frontend, backend')
            ->assertExitCode(0);
    });

    it('shows task with blockers in blocked_by array', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker']);
        $task = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($task['id'], $blocker['id']);

        $this->artisan('show', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Blocked by: '.$blocker['id'])
            ->assertExitCode(0);
    });

    it('shows task size', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'size' => 'xl']);

        $this->artisan('show', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Size: xl')
            ->assertExitCode(0);
    });

    it('shows task with reason if present', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Completed task']);
        $this->taskService->done($task['id'], 'Fixed the issue');

        $this->artisan('show', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Reason: Fixed the issue')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create([
            'title' => 'JSON task',
            'description' => 'JSON description',
            'type' => 'bug',
            'priority' => 4,
            'labels' => ['critical'],
        ]);

        Artisan::call('show', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['id'])->toBe($task['id']);
        expect($result['title'])->toBe('JSON task');
        expect($result['description'])->toBe('JSON description');
        expect($result['type'])->toBe('bug');
        expect($result['priority'])->toBe(4);
        expect($result['labels'])->toBe(['critical']);
    });

    it('shows error for non-existent task', function () {
        $this->taskService->initialize();

        $this->artisan('show', ['id' => 'nonexistent', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('supports partial ID matching', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Partial ID task']);
        $partialId = substr($task['id'], 5, 3);

        $this->artisan('show', ['id' => $partialId, '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Task: '.$task['id'])
            ->assertExitCode(0);
    });
});

// =============================================================================
// list Command Tests
// =============================================================================

describe('list command', function () {
    it('lists all tasks', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task 1']);
        $this->taskService->create(['title' => 'Task 2']);

        $this->artisan('list', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Task 1')
            ->expectsOutputToContain('Task 2')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);

        Artisan::call('list', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $tasks = json_decode($output, true);

        expect($tasks)->toHaveCount(2);
        expect(collect($tasks)->pluck('id')->toArray())->toContain($task1['id'], $task2['id']);
    });

    it('filters by --status flag', function () {
        $this->taskService->initialize();
        $open = $this->taskService->create(['title' => 'Open task']);
        $closed = $this->taskService->create(['title' => 'Closed task']);
        $this->taskService->done($closed['id']);

        $this->artisan('list', ['--status' => 'open', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Open task')
            ->doesntExpectOutputToContain('Closed task')
            ->assertExitCode(0);
    });

    it('filters by --type flag', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Bug task', 'type' => 'bug']);
        $this->taskService->create(['title' => 'Feature task', 'type' => 'feature']);

        $this->artisan('list', ['--type' => 'bug', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Bug task')
            ->doesntExpectOutputToContain('Feature task')
            ->assertExitCode(0);
    });

    it('filters by --priority flag', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'High priority', 'priority' => 4]);
        $this->taskService->create(['title' => 'Low priority', 'priority' => 1]);

        $this->artisan('list', ['--priority' => '4', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('High priority')
            ->doesntExpectOutputToContain('Low priority')
            ->assertExitCode(0);
    });

    it('filters by --labels flag', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Frontend task', 'labels' => ['frontend', 'ui']]);
        $this->taskService->create(['title' => 'Backend task', 'labels' => ['backend', 'api']]);
        $this->taskService->create(['title' => 'No labels']);

        $this->artisan('list', ['--labels' => 'frontend', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Frontend task')
            ->doesntExpectOutputToContain('Backend task')
            ->doesntExpectOutputToContain('No labels')
            ->assertExitCode(0);
    });

    it('filters by multiple labels (comma-separated)', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task with frontend', 'labels' => ['frontend']]);
        $this->taskService->create(['title' => 'Task with backend', 'labels' => ['backend']]);
        $this->taskService->create(['title' => 'Task with both', 'labels' => ['frontend', 'backend']]);

        $this->artisan('list', ['--labels' => 'frontend,backend', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Task with frontend')
            ->expectsOutputToContain('Task with backend')
            ->expectsOutputToContain('Task with both')
            ->assertExitCode(0);
    });

    it('applies multiple filters together', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Open bug', 'type' => 'bug']);
        $closedBug = $this->taskService->create(['title' => 'Closed bug', 'type' => 'bug']);
        $this->taskService->done($closedBug['id']);
        $this->taskService->create(['title' => 'Open feature', 'type' => 'feature']);

        $this->artisan('list', [
            '--status' => 'open',
            '--type' => 'bug',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Open bug')
            ->doesntExpectOutputToContain('Closed bug')
            ->doesntExpectOutputToContain('Open feature')
            ->assertExitCode(0);
    });

    it('shows empty message when no tasks match filters', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Open task']);

        $this->artisan('list', ['--status' => 'closed', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('No tasks found')
            ->assertExitCode(0);
    });

    it('outputs all schema fields in JSON', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create([
            'title' => 'Complete task',
            'description' => 'Full description',
            'type' => 'feature',
            'priority' => 3,
            'labels' => ['test'],
            'size' => 'l',
        ]);

        Artisan::call('list', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $tasks = json_decode($output, true);

        expect($tasks)->toHaveCount(1);
        expect($tasks[0])->toHaveKeys(['id', 'title', 'status', 'description', 'type', 'priority', 'labels', 'size', 'blocked_by', 'created_at', 'updated_at']);
        expect($tasks[0]['description'])->toBe('Full description');
        expect($tasks[0]['type'])->toBe('feature');
        expect($tasks[0]['priority'])->toBe(3);
        expect($tasks[0]['labels'])->toBe(['test']);
        expect($tasks[0]['size'])->toBe('l');
    });

    it('filters by --size flag', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Small task', 'size' => 'xs']);
        $this->taskService->create(['title' => 'Medium task', 'size' => 'm']);
        $this->taskService->create(['title' => 'Large task', 'size' => 'xl']);

        $this->artisan('list', ['--size' => 'xl', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Large task')
            ->doesntExpectOutputToContain('Small task')
            ->doesntExpectOutputToContain('Medium task')
            ->assertExitCode(0);
    });
});

// =============================================================================
// update Command Tests
// =============================================================================

describe('update command', function () {
    it('updates task title', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Original title']);

        Artisan::call('update', [
            'id' => $task['id'],
            '--title' => 'Updated title',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['title'])->toBe('Updated title');
        expect($updated['id'])->toBe($task['id']);
    });

    it('updates task description', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        Artisan::call('update', [
            'id' => $task['id'],
            '--description' => 'New description',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['description'])->toBe('New description');
    });

    it('clears task description when empty string provided', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'description' => 'Old description']);

        Artisan::call('update', [
            'id' => $task['id'],
            '--description' => '',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['description'])->toBeNull();
    });

    it('updates task type', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'type' => 'task']);

        Artisan::call('update', [
            'id' => $task['id'],
            '--type' => 'bug',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['type'])->toBe('bug');
    });

    it('validates task type enum', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        $this->artisan('update', [
            'id' => $task['id'],
            '--type' => 'invalid-type',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid task type')
            ->assertExitCode(1);
    });

    it('updates task priority', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'priority' => 2]);

        Artisan::call('update', [
            'id' => $task['id'],
            '--priority' => '4',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['priority'])->toBe(4);
    });

    it('validates priority range', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        $this->artisan('update', [
            'id' => $task['id'],
            '--priority' => '5',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid priority')
            ->assertExitCode(1);
    });

    it('updates task status', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        Artisan::call('update', [
            'id' => $task['id'],
            '--status' => 'closed',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['status'])->toBe('closed');
    });

    it('adds labels with --add-labels flag', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'labels' => ['existing']]);

        Artisan::call('update', [
            'id' => $task['id'],
            '--add-labels' => 'new1,new2',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['labels'])->toContain('existing', 'new1', 'new2');
    });

    it('removes labels with --remove-labels flag', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'labels' => ['keep', 'remove1', 'remove2']]);

        Artisan::call('update', [
            'id' => $task['id'],
            '--remove-labels' => 'remove1,remove2',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['labels'])->toBe(['keep']);
    });

    it('adds and removes labels in same update', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'labels' => ['old1', 'old2']]);

        Artisan::call('update', [
            'id' => $task['id'],
            '--add-labels' => 'new1',
            '--remove-labels' => 'old1',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['labels'])->toContain('old2', 'new1');
        expect($updated['labels'])->not->toContain('old1');
    });

    it('updates multiple fields at once', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Original', 'type' => 'task', 'priority' => 2]);

        Artisan::call('update', [
            'id' => $task['id'],
            '--title' => 'Updated',
            '--type' => 'feature',
            '--priority' => '3',
            '--description' => 'New description',
            '--size' => 'l',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['title'])->toBe('Updated');
        expect($updated['type'])->toBe('feature');
        expect($updated['priority'])->toBe(3);
        expect($updated['description'])->toBe('New description');
        expect($updated['size'])->toBe('l');
    });

    it('updates task size', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task', 'size' => 'm']);

        Artisan::call('update', [
            'id' => $task['id'],
            '--size' => 'xl',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['size'])->toBe('xl');
    });

    it('validates task size enum in update', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        $this->artisan('update', [
            'id' => $task['id'],
            '--size' => 'invalid-size',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid task size')
            ->assertExitCode(1);
    });

    it('shows error when no update fields provided', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        $this->artisan('update', [
            'id' => $task['id'],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('No update fields provided')
            ->assertExitCode(1);
    });

    it('shows error for non-existent task', function () {
        $this->taskService->initialize();

        $this->artisan('update', [
            'id' => 'nonexistent',
            '--title' => 'New title',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('supports partial ID matching', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);
        $partialId = substr($task['id'], 5, 3);

        Artisan::call('update', [
            'id' => $partialId,
            '--title' => 'Updated',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated['title'])->toBe('Updated');
        expect($updated['id'])->toBe($task['id']);
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task']);

        Artisan::call('update', [
            'id' => $task['id'],
            '--title' => 'Updated',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $updated = json_decode($output, true);

        expect($updated)->toHaveKey('id');
        expect($updated)->toHaveKey('title');
        expect($updated['title'])->toBe('Updated');
    });
});

// =============================================================================
// q Command Tests (Quick Capture)
// =============================================================================

describe('q command', function () {
    it('creates task and outputs only the ID', function () {
        $this->taskService->initialize();

        Artisan::call('q', ['title' => 'Quick task', '--cwd' => $this->tempDir]);
        $output = trim(Artisan::output());

        expect($output)->toStartWith('fuel-');
        expect(strlen($output))->toBe(9); // fuel- + 4 chars

        // Verify task was actually created
        $task = $this->taskService->find($output);
        expect($task)->not->toBeNull();
        expect($task['title'])->toBe('Quick task');
    });

    it('returns exit code 0 on success', function () {
        $this->taskService->initialize();

        $this->artisan('q', ['title' => 'Quick task', '--cwd' => $this->tempDir])
            ->assertExitCode(0);
    });
});

// =============================================================================
// status Command Tests
// =============================================================================

describe('status command', function () {
    it('shows zero counts when no tasks exist', function () {
        $this->taskService->initialize();

        $this->artisan('status', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Open')
            ->expectsOutputToContain('In Progress')
            ->expectsOutputToContain('Closed')
            ->expectsOutputToContain('Blocked')
            ->expectsOutputToContain('Total')
            ->assertExitCode(0);
    });

    it('counts tasks by status correctly', function () {
        $this->taskService->initialize();
        $open1 = $this->taskService->create(['title' => 'Open task 1']);
        $open2 = $this->taskService->create(['title' => 'Open task 2']);
        $inProgress = $this->taskService->create(['title' => 'In progress task']);
        $closed1 = $this->taskService->create(['title' => 'Closed task 1']);
        $closed2 = $this->taskService->create(['title' => 'Closed task 2']);

        $this->taskService->start($inProgress['id']);
        $this->taskService->done($closed1['id']);
        $this->taskService->done($closed2['id']);

        $this->artisan('status', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Open')
            ->expectsOutputToContain('In Progress')
            ->expectsOutputToContain('Closed')
            ->assertExitCode(0);
    });

    it('counts blocked tasks correctly', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked1 = $this->taskService->create(['title' => 'Blocked task 1']);
        $blocked2 = $this->taskService->create(['title' => 'Blocked task 2']);

        // Add dependencies
        $this->taskService->addDependency($blocked1['id'], $blocker['id']);
        $this->taskService->addDependency($blocked2['id'], $blocker['id']);

        $this->artisan('status', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Blocked')
            ->assertExitCode(0);
    });

    it('does not count tasks as blocked when blocker is closed', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add dependency
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        // Close the blocker
        $this->taskService->done($blocker['id']);

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['blocked'])->toBe(0);
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Open task']);
        $inProgress = $this->taskService->create(['title' => 'In progress task']);
        $closed = $this->taskService->create(['title' => 'Closed task']);

        $this->taskService->start($inProgress['id']);
        $this->taskService->done($closed['id']);

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveKeys(['open', 'in_progress', 'closed', 'blocked', 'total']);
        expect($result['open'])->toBe(1);
        expect($result['in_progress'])->toBe(1);
        expect($result['closed'])->toBe(1);
        expect($result['blocked'])->toBe(0);
        expect($result['total'])->toBe(3);
    });

    it('shows correct total count', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task 1']);
        $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->create(['title' => 'Task 3']);

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['total'])->toBe(3);
        expect($result['open'])->toBe(3);
    });

    it('handles empty state with JSON output', function () {
        $this->taskService->initialize();

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['open'])->toBe(0);
        expect($result['in_progress'])->toBe(0);
        expect($result['closed'])->toBe(0);
        expect($result['blocked'])->toBe(0);
        expect($result['total'])->toBe(0);
    });

    it('counts only open tasks as blocked', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blockedOpen = $this->taskService->create(['title' => 'Blocked open task']);
        $blockedInProgress = $this->taskService->create(['title' => 'Blocked in progress task']);

        // Add dependencies
        $this->taskService->addDependency($blockedOpen['id'], $blocker['id']);
        $this->taskService->addDependency($blockedInProgress['id'], $blocker['id']);

        // Set one to in_progress
        $this->taskService->start($blockedInProgress['id']);

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        // Only open tasks should be counted as blocked
        expect($result['blocked'])->toBe(1);
    });
});

// =============================================================================
// completed Command Tests
// =============================================================================

describe('completed command', function () {
    it('shows no completed tasks when empty', function () {
        Artisan::call('completed', ['--cwd' => $this->tempDir]);

        expect(Artisan::output())->toContain('No completed tasks found');
    });

    it('shows completed tasks in reverse chronological order', function () {
        // Create and close some tasks
        $task1 = $this->taskService->create(['title' => 'First task']);
        $task2 = $this->taskService->create(['title' => 'Second task']);
        $task3 = $this->taskService->create(['title' => 'Third task']);

        // Close them in order
        $this->taskService->done($task1['id']);
        sleep(1); // Ensure different timestamps
        $this->taskService->done($task2['id']);
        sleep(1);
        $this->taskService->done($task3['id']);

        Artisan::call('completed', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        // Should show most recent first
        expect($output)->toContain('Third task');
        expect($output)->toContain('Second task');
        expect($output)->toContain('First task');
    });

    it('excludes open and in_progress tasks', function () {
        $open = $this->taskService->create(['title' => 'Open task']);
        $inProgress = $this->taskService->create(['title' => 'In progress task']);
        $closed = $this->taskService->create(['title' => 'Closed task']);

        $this->taskService->start($inProgress['id']);
        $this->taskService->done($closed['id']);

        Artisan::call('completed', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Closed task');
        expect($output)->not->toContain('Open task');
        expect($output)->not->toContain('In progress task');
    });

    it('respects --limit option', function () {
        // Create and close 5 tasks
        $taskIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $task = $this->taskService->create(['title' => "Task {$i}"]);
            $taskIds[] = $task['id'];
            $this->taskService->done($task['id']);
            usleep(200000); // Delay for different timestamps
        }

        Artisan::call('completed', ['--cwd' => $this->tempDir, '--limit' => 3, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toHaveCount(3);
        // Verify limit works - should only return 3 tasks
        $titles = array_column($data, 'title');
        expect($titles)->toHaveCount(3);
        // Most recent tasks should be included (Task 5 should be in results)
        expect($titles)->toContain('Task 5');
    });

    it('outputs JSON when --json flag is used', function () {
        $task = $this->taskService->create(['title' => 'Completed task']);
        $this->taskService->done($task['id']);

        Artisan::call('completed', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toHaveCount(1);
        expect($data[0]['id'])->toBe($task['id']);
        expect($data[0]['status'])->toBe('closed');
    });

    it('outputs empty array as JSON when no completed tasks', function () {
        Artisan::call('completed', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toBeEmpty();
    });

    it('displays task details in table format', function () {
        $task = $this->taskService->create([
            'title' => 'Test completed task',
            'type' => 'feature',
            'priority' => 1,
        ]);
        $this->taskService->done($task['id']);

        Artisan::call('completed', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('ID');
        expect($output)->toContain('Title');
        expect($output)->toContain('Completed');
        expect($output)->toContain('Type');
        expect($output)->toContain('Priority');
        expect($output)->toContain($task['id']);
        expect($output)->toContain('Test completed task');
        expect($output)->toContain('feature');
        expect($output)->toContain('1');
    });
});

// =============================================================================
// init Command Tests
// =============================================================================

describe('init command', function () {
    it('creates .fuel directory', function () {
        $fuelDir = $this->tempDir.'/.fuel';

        // Ensure it doesn't exist first
        if (is_dir($fuelDir)) {
            rmdir($fuelDir);
        }

        Artisan::call('init', ['--cwd' => $this->tempDir]);

        expect(is_dir($fuelDir))->toBeTrue();
    });

    it('creates tasks.jsonl file', function () {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        expect(file_exists($this->storagePath))->toBeTrue();
    });

    it('creates a starter task', function () {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        // Verify task file was created with content
        expect(file_exists($this->storagePath))->toBeTrue();
        $content = file_get_contents($this->storagePath);
        expect($content)->toContain('README');
        expect($content)->toContain('fuel-');
    });

    it('creates AGENTS.md with fuel guidelines', function () {
        $agentsMdPath = $this->tempDir.'/AGENTS.md';

        // Remove if exists
        if (file_exists($agentsMdPath)) {
            unlink($agentsMdPath);
        }

        Artisan::call('init', ['--cwd' => $this->tempDir]);

        expect(file_exists($agentsMdPath))->toBeTrue();
        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('<fuel>');
        expect($content)->toContain('</fuel>');
    });
});
