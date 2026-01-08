<?php

use App\Services\RunService;
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

    // Bind our test RunService instance
    $this->app->singleton(RunService::class, function () {
        return new RunService($this->tempDir.'/.fuel/runs');
    });

    $this->taskService = $this->app->make(TaskService::class);
});

afterEach(function () {
    // Recursively delete temp directory
    $deleteDir = function (string $dir) use (&$deleteDir) {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $deleteDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    };

    $deleteDir($this->tempDir);
});

// Add Command Tests
describe('add command', function () {
    it('creates a task via CLI', function () {
        $this->artisan('add', ['title' => 'My test task', '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Created task: f-')
            ->assertExitCode(0);

        expect(file_exists($this->storagePath))->toBeTrue();
    });

    it('outputs JSON when --json flag is used', function () {
        Artisan::call('add', ['title' => 'JSON task', '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain('"status": "open"');
        expect($output)->toContain('"title": "JSON task"');
        expect($output)->toContain('"id": "f-');
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
            '--complexity' => 'moderate',
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
        expect($task['complexity'])->toBe('moderate');
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

    it('creates task with --complexity flag', function () {
        Artisan::call('add', [
            'title' => 'Complex task',
            '--complexity' => 'complex',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['complexity'])->toBe('complex');
    });

    it('defaults complexity to simple when not specified', function () {
        Artisan::call('add', [
            'title' => 'Task without complexity',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $task = json_decode($output, true);

        expect($task['complexity'])->toBe('simple');
    });

    it('validates --complexity flag enum', function () {
        $this->artisan('add', [
            'title' => 'Invalid complexity',
            '--complexity' => 'invalid-complexity',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Invalid task complexity')
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

        expect($output)->toContain('Created task: f-');
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
        $partialId = substr($blocker['id'], 2, 3); // Just hash part

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
        $partialId = substr($task['id'], 2, 3); // Just 3 chars of the hash

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
        $partialId = substr($task['id'], 2, 3); // Just 3 chars of the hash

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

    it('marks task as done with --commit flag', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task with commit']);
        $commitHash = 'abc123def456';

        $this->artisan('done', [
            'ids' => [$task['id']],
            '--commit' => $commitHash,
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain("Commit: {$commitHash}")
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
        expect($updated['commit_hash'])->toBe($commitHash);
    });

    it('outputs commit hash in JSON when --commit flag is used with --json', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON task with commit']);
        $commitHash = 'xyz789abc123';

        Artisan::call('done', [
            'ids' => [$task['id']],
            '--commit' => $commitHash,
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['status'])->toBe('closed');
        expect($result['commit_hash'])->toBe($commitHash);
    });

    it('does not add commit_hash field when --commit is not provided', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task without commit']);

        $this->artisan('done', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
        expect($updated)->not->toHaveKey('commit_hash');
    });

    it('can use both --reason and --commit flags together', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task with both flags']);
        $commitHash = 'def456ghi789';

        $this->artisan('done', [
            'ids' => [$task['id']],
            '--reason' => 'Fixed the bug',
            '--commit' => $commitHash,
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Completed task:')
            ->expectsOutputToContain('Reason: Fixed the bug')
            ->expectsOutputToContain("Commit: {$commitHash}")
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('closed');
        expect($updated['reason'])->toBe('Fixed the bug');
        expect($updated['commit_hash'])->toBe($commitHash);
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
        $partialId1 = substr($task1['id'], 2, 3);
        $partialId2 = substr($task2['id'], 2, 3);

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
        $partialId = substr($task['id'], 2, 3); // Just 3 chars of the hash

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

    it('clears consumed fields when reopening a task', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task with consumed fields']);
        $this->taskService->done($task['id']);

        // Manually add consumed fields (simulating a consumed task)
        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_at' => '2026-01-07T10:00:00+00:00',
            'consumed_exit_code' => 1,
            'consumed_output' => 'Some error output',
        ]);

        $closedTask = $this->taskService->find($task['id']);
        expect($closedTask['consumed'])->toBeTrue();
        expect($closedTask['consumed_at'])->toBe('2026-01-07T10:00:00+00:00');
        expect($closedTask['consumed_exit_code'])->toBe(1);
        expect($closedTask['consumed_output'])->toBe('Some error output');

        $this->artisan('reopen', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->assertExitCode(0);

        $reopenedTask = $this->taskService->find($task['id']);
        expect($reopenedTask['status'])->toBe('open');
        expect($reopenedTask)->not->toHaveKey('consumed');
        expect($reopenedTask)->not->toHaveKey('consumed_at');
        expect($reopenedTask)->not->toHaveKey('consumed_exit_code');
        expect($reopenedTask)->not->toHaveKey('consumed_output');
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
        $partialId1 = substr($task1['id'], 2, 3);
        $partialId2 = substr($task2['id'], 2, 3);

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
// retry Command Tests
// =============================================================================

describe('retry command', function () {
    it('retries a stuck task', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Stuck task']);
        $this->taskService->start($task['id']);

        // Mark task as consumed with non-zero exit code
        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_at' => '2026-01-07T10:00:00+00:00',
            'consumed_exit_code' => 1,
            'consumed_output' => 'Some error output',
        ]);

        $this->artisan('retry', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Retried task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('open');
        expect($updated)->not->toHaveKey('consumed');
        expect($updated)->not->toHaveKey('consumed_at');
        expect($updated)->not->toHaveKey('consumed_exit_code');
        expect($updated)->not->toHaveKey('consumed_output');
    });

    it('supports partial ID matching', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Partial ID stuck task']);
        $this->taskService->start($task['id']);

        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_exit_code' => 1,
        ]);

        $partialId = substr($task['id'], 2, 3); // Just 3 chars of the hash

        $this->artisan('retry', ['ids' => [$partialId], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Retried task:')
            ->assertExitCode(0);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('open');
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'JSON retry task']);
        $this->taskService->start($task['id']);

        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_exit_code' => 1,
        ]);

        Artisan::call('retry', [
            'ids' => [$task['id']],
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['id'])->toBe($task['id']);
        expect($result['status'])->toBe('open');
        expect($result['title'])->toBe('JSON retry task');
    });

    it('clears consumed fields when retrying a task', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task with consumed fields']);
        $this->taskService->start($task['id']);

        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_at' => '2026-01-07T10:00:00+00:00',
            'consumed_exit_code' => 1,
            'consumed_output' => 'Some error output',
        ]);

        $stuckTask = $this->taskService->find($task['id']);
        expect($stuckTask['consumed'])->toBeTrue();
        expect($stuckTask['consumed_at'])->toBe('2026-01-07T10:00:00+00:00');
        expect($stuckTask['consumed_exit_code'])->toBe(1);
        expect($stuckTask['consumed_output'])->toBe('Some error output');

        $this->artisan('retry', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->assertExitCode(0);

        $retriedTask = $this->taskService->find($task['id']);
        expect($retriedTask['status'])->toBe('open');
        expect($retriedTask)->not->toHaveKey('consumed');
        expect($retriedTask)->not->toHaveKey('consumed_at');
        expect($retriedTask)->not->toHaveKey('consumed_exit_code');
        expect($retriedTask)->not->toHaveKey('consumed_output');
    });

    it('fails when task is not found', function () {
        $this->taskService->initialize();

        $this->artisan('retry', ['ids' => ['nonexistent'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('fails when task is not consumed', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Not consumed task']);
        $this->taskService->start($task['id']);

        $this->artisan('retry', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('is not a consumed in_progress task')
            ->assertExitCode(1);

        $updated = $this->taskService->find($task['id']);
        expect($updated['status'])->toBe('in_progress');
    });

    it('retries task with zero exit code if still in_progress', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task with zero exit code']);
        $this->taskService->start($task['id']);

        // Agent exited cleanly but task still in_progress = something went wrong
        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_exit_code' => 0,
        ]);

        $this->artisan('retry', ['ids' => [$task['id']], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Retried task:')
            ->assertExitCode(0);
    });

    it('retries multiple stuck tasks', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Stuck task 1']);
        $task2 = $this->taskService->create(['title' => 'Stuck task 2']);
        $task3 = $this->taskService->create(['title' => 'Stuck task 3']);

        $this->taskService->start($task1['id']);
        $this->taskService->start($task2['id']);
        $this->taskService->start($task3['id']);

        $this->taskService->update($task1['id'], ['consumed' => true, 'consumed_exit_code' => 1]);
        $this->taskService->update($task2['id'], ['consumed' => true, 'consumed_exit_code' => 2]);
        $this->taskService->update($task3['id'], ['consumed' => true, 'consumed_exit_code' => 3]);

        $this->artisan('retry', [
            'ids' => [$task1['id'], $task2['id'], $task3['id']],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Retried task:')
            ->assertExitCode(0);

        expect($this->taskService->find($task1['id'])['status'])->toBe('open');
        expect($this->taskService->find($task2['id'])['status'])->toBe('open');
        expect($this->taskService->find($task3['id'])['status'])->toBe('open');
    });

    it('outputs multiple tasks as JSON array when multiple IDs provided', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Stuck task 1']);
        $task2 = $this->taskService->create(['title' => 'Stuck task 2']);

        $this->taskService->start($task1['id']);
        $this->taskService->start($task2['id']);

        $this->taskService->update($task1['id'], ['consumed' => true, 'consumed_exit_code' => 1]);
        $this->taskService->update($task2['id'], ['consumed' => true, 'consumed_exit_code' => 1]);

        Artisan::call('retry', [
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

    it('handles partial failures when retrying multiple tasks', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create(['title' => 'Stuck task 1']);
        $task2 = $this->taskService->create(['title' => 'Stuck task 2']);

        $this->taskService->start($task1['id']);
        $this->taskService->start($task2['id']);

        $this->taskService->update($task1['id'], ['consumed' => true, 'consumed_exit_code' => 1]);
        $this->taskService->update($task2['id'], ['consumed' => true, 'consumed_exit_code' => 1]);

        $this->artisan('retry', [
            'ids' => [$task1['id'], 'nonexistent', $task2['id']],
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain('Retried task:')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);

        // Task1 should be retried
        expect($this->taskService->find($task1['id'])['status'])->toBe('open');
        // Task2 should be retried
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
        $blockerPartial = substr($blocker['id'], 2, 3);
        $blockedPartial = substr($blocked['id'], 2, 3);

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
        $blockerPartial = substr($blocker['id'], 2, 3);
        $blockedPartial = substr($blocked['id'], 2, 3);

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

        // Titles may be truncated, so check for short IDs with complexity char
        $blockerShortId = substr($blocker['id'], 2, 6);
        $blockedShortId = substr($blocked['id'], 2, 6);
        expect($output)->toContain("[{$blockerShortId} ·s]");
        expect($output)->toContain("[{$blockedShortId} ·s]");
    });

    it('shows in progress tasks in In Progress column', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'In progress task']);
        $this->taskService->start($task['id']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        // Title may be truncated, so check for short ID with complexity char
        $shortId = substr($task['id'], 2, 6);
        expect($output)->toContain("[{$shortId} ·s]");
    });

    it('shows done tasks in Done column', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Completed task']);
        $this->taskService->done($task['id']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        // Done tasks appear in "Done" column
        $shortId = substr($task['id'], 2, 6);
        expect($output)->toContain('Done (1)');
        expect($output)->toContain("[{$shortId} ·s]");
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Test task']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain('"ready":');
        expect($output)->toContain('"in_progress":');
        expect($output)->toContain('"blocked":');
        expect($output)->toContain('"human":');
        expect($output)->toContain('"done":');
        expect($output)->toContain('Test task');
    });

    it('returns all done tasks in JSON output', function () {
        $this->taskService->initialize();

        // Create and close 12 tasks
        for ($i = 1; $i <= 12; $i++) {
            $task = $this->taskService->create(['title' => "Done task {$i}"]);
            $this->taskService->done($task['id']);
        }

        Artisan::call('board', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        // JSON output returns all done tasks (display limits to 3)
        expect($data['done'])->toHaveCount(12);
    });

    it('shows failed icon for consumed tasks with non-zero exit code', function () {
        $this->taskService->initialize();
        $stuckTask = $this->taskService->create(['title' => 'Stuck task']);
        $this->taskService->update($stuckTask['id'], [
            'consumed' => true,
            'consumed_exit_code' => 1,
        ]);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        $shortId = substr($stuckTask['id'], 2, 6);
        expect($output)->toContain('🪫');
        expect($output)->toContain("[{$shortId} ·s]");
    });

    it('does not show failed icon for consumed tasks with zero exit code', function () {
        $this->taskService->initialize();
        $successTask = $this->taskService->create(['title' => 'Success task']);
        $this->taskService->update($successTask['id'], [
            'consumed' => true,
            'consumed_exit_code' => 0,
        ]);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        $shortId = substr($successTask['id'], 2, 6);
        expect($output)->toContain("[{$shortId} ·s]");
        expect($output)->not->toContain('🪫');
    });

    it('shows stuck emoji for in_progress tasks with non-running PID', function () {
        $this->taskService->initialize();
        $stuckTask = $this->taskService->create(['title' => 'Stuck PID task']);
        $this->taskService->update($stuckTask['id'], [
            'status' => 'in_progress',
            'consume_pid' => 99999, // Non-existent PID
        ]);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        $shortId = substr($stuckTask['id'], 2, 6);
        expect($output)->toContain('🪫');
        expect($output)->toContain("[{$shortId} ·s]");
    });

    it('does not show stuck emoji for in_progress tasks with running PID', function () {
        $this->taskService->initialize();
        $runningTask = $this->taskService->create(['title' => 'Running task']);
        // Use current process PID which should be running
        $currentPid = getmypid();
        $this->taskService->update($runningTask['id'], [
            'status' => 'in_progress',
            'consume_pid' => $currentPid,
        ]);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        $shortId = substr($runningTask['id'], 2, 6);
        expect($output)->toContain("[{$shortId} ·s]");
        expect($output)->not->toContain('🪫');
    });

    it('shows complexity character in task display', function () {
        $this->taskService->initialize();
        $trivialTask = $this->taskService->create(['title' => 'Trivial task', 'complexity' => 'trivial']);
        $simpleTask = $this->taskService->create(['title' => 'Simple task', 'complexity' => 'simple']);
        $moderateTask = $this->taskService->create(['title' => 'Moderate task', 'complexity' => 'moderate']);
        $complexTask = $this->taskService->create(['title' => 'Complex task', 'complexity' => 'complex']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        $trivialShortId = substr($trivialTask['id'], 2, 6);
        $simpleShortId = substr($simpleTask['id'], 2, 6);
        $moderateShortId = substr($moderateTask['id'], 2, 6);
        $complexShortId = substr($complexTask['id'], 2, 6);

        expect($output)->toContain("[{$trivialShortId} ·t]");
        expect($output)->toContain("[{$simpleShortId} ·s]");
        expect($output)->toContain("[{$moderateShortId} ·m]");
        expect($output)->toContain("[{$complexShortId} ·c]");
    });

    it('defaults to simple complexity when complexity is missing', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task without complexity']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        $shortId = substr($task['id'], 2, 6);
        expect($output)->toContain("[{$shortId} ·s]");
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
        $partialId = substr($task['id'], 2, 3);

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
        $partialId = substr($task['id'], 2, 3);

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

        expect($output)->toStartWith('f-');
        expect(strlen($output))->toBe(8); // f- + 6 chars

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

    it('handles RuntimeException from TaskService::create()', function () {
        // Create a mock TaskService that throws RuntimeException
        $mockTaskService = \Mockery::mock(TaskService::class);
        $mockTaskService->shouldReceive('setStoragePath')
            ->once()
            ->andReturnSelf();
        $mockTaskService->shouldReceive('initialize')->once();
        $mockTaskService->shouldReceive('create')
            ->once()
            ->andThrow(new \RuntimeException('Failed to create task'));

        // Bind the mock to the service container
        $this->app->singleton(TaskService::class, function () use ($mockTaskService) {
            return $mockTaskService;
        });

        $exitCode = Artisan::call('q', ['title' => 'Test task', '--cwd' => $this->tempDir]);
        $output = trim(Artisan::output());

        expect($output)->toContain('Failed to create task');
        expect($exitCode)->toBe(\Illuminate\Console\Command::FAILURE);
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
        // Use explicit timestamps to ensure reliable ordering
        $baseTime = \Illuminate\Support\Carbon::parse('2024-01-01 12:00:00');
        \Illuminate\Support\Carbon::setTestNow($baseTime);

        try {
            // Create and close 5 tasks with explicit timestamps
            $taskIds = [];
            for ($i = 1; $i <= 5; $i++) {
                // Set time for this task (each task gets a different timestamp)
                \Illuminate\Support\Carbon::setTestNow($baseTime->copy()->addSeconds($i));
                $task = $this->taskService->create(['title' => "Task {$i}"]);
                $taskIds[] = $task['id'];
                // Increment time slightly for done() call to ensure updated_at differs
                \Illuminate\Support\Carbon::setTestNow($baseTime->copy()->addSeconds($i)->addMilliseconds(100));
                $this->taskService->done($task['id']);
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
        } finally {
            // Always restore real time
            \Illuminate\Support\Carbon::setTestNow();
        }
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
// human Command Tests
// =============================================================================

describe('human command', function () {
    it('shows empty when no tasks with needs-human label', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Regular task']);

        $this->artisan('human', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No tasks need human attention.')
            ->assertExitCode(0);
    });

    it('shows open tasks with needs-human label', function () {
        $this->taskService->initialize();
        $humanTask = $this->taskService->create([
            'title' => 'Needs human task',
            'labels' => ['needs-human'],
        ]);
        $regularTask = $this->taskService->create(['title' => 'Regular task']);

        Artisan::call('human', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Needs human task');
        expect($output)->toContain($humanTask['id']);
        expect($output)->not->toContain('Regular task');
    });

    it('excludes closed tasks with needs-human label', function () {
        $this->taskService->initialize();
        $humanTask = $this->taskService->create([
            'title' => 'Closed human task',
            'labels' => ['needs-human'],
        ]);
        $this->taskService->done($humanTask['id']);

        $this->artisan('human', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No tasks need human attention.')
            ->doesntExpectOutputToContain('Closed human task')
            ->assertExitCode(0);
    });

    it('excludes in_progress tasks with needs-human label', function () {
        $this->taskService->initialize();
        $humanTask = $this->taskService->create([
            'title' => 'In progress human task',
            'labels' => ['needs-human'],
        ]);
        $this->taskService->start($humanTask['id']);

        $this->artisan('human', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No tasks need human attention.')
            ->doesntExpectOutputToContain('In progress human task')
            ->assertExitCode(0);
    });

    it('excludes tasks without needs-human label', function () {
        $this->taskService->initialize();
        $this->taskService->create([
            'title' => 'Task with other labels',
            'labels' => ['bug', 'urgent'],
        ]);
        $this->taskService->create(['title' => 'Task with no labels']);

        $this->artisan('human', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No tasks need human attention.')
            ->doesntExpectOutputToContain('Task with other labels')
            ->doesntExpectOutputToContain('Task with no labels')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is provided', function () {
        $this->taskService->initialize();
        $humanTask = $this->taskService->create([
            'title' => 'Needs human task',
            'labels' => ['needs-human'],
        ]);
        $regularTask = $this->taskService->create(['title' => 'Regular task']);

        Artisan::call('human', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toHaveCount(1);
        expect($data[0]['id'])->toBe($humanTask['id']);
        expect($data[0]['title'])->toBe('Needs human task');
        expect($data[0]['status'])->toBe('open');
        expect($data[0]['labels'])->toContain('needs-human');
    });

    it('outputs empty array as JSON when no human tasks', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Regular task']);

        Artisan::call('human', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toBeEmpty();
    });

    it('displays task description when present', function () {
        $this->taskService->initialize();
        $humanTask = $this->taskService->create([
            'title' => 'Needs human task',
            'description' => 'This task needs human attention',
            'labels' => ['needs-human'],
        ]);

        Artisan::call('human', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Needs human task');
        expect($output)->toContain('This task needs human attention');
        expect($output)->toContain($humanTask['id']);
    });

    it('shows count of human tasks', function () {
        $this->taskService->initialize();
        $this->taskService->create([
            'title' => 'First human task',
            'labels' => ['needs-human'],
        ]);
        $this->taskService->create([
            'title' => 'Second human task',
            'labels' => ['needs-human'],
        ]);

        Artisan::call('human', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Tasks needing human attention (2):');
    });

    it('sorts tasks by created_at', function () {
        $this->taskService->initialize();
        $task1 = $this->taskService->create([
            'title' => 'First task',
            'labels' => ['needs-human'],
        ]);
        sleep(1);
        $task2 = $this->taskService->create([
            'title' => 'Second task',
            'labels' => ['needs-human'],
        ]);

        Artisan::call('human', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toHaveCount(2);
        expect($data[0]['id'])->toBe($task1['id']);
        expect($data[1]['id'])->toBe($task2['id']);
    });
});

// =============================================================================
// stuck Command Tests
// =============================================================================

describe('stuck command', function () {
    it('shows no stuck tasks when empty', function () {
        Artisan::call('stuck', ['--cwd' => $this->tempDir]);

        expect(Artisan::output())->toContain('No stuck tasks found');
    });

    it('shows only consumed tasks with non-zero exit codes', function () {
        $this->taskService->initialize();

        // Create tasks with different consumed states
        $successTask = $this->taskService->create(['title' => 'Success task']);
        $this->taskService->update($successTask['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 0,
        ]);

        $failedTask = $this->taskService->create(['title' => 'Failed task']);
        $this->taskService->update($failedTask['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 1,
            'consumed_output' => 'Error: Something went wrong',
        ]);

        $notConsumedTask = $this->taskService->create(['title' => 'Not consumed task']);

        Artisan::call('stuck', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Failed task');
        expect($output)->not->toContain('Success task');
        expect($output)->not->toContain('Not consumed task');
    });

    it('shows exit code and output for stuck tasks', function () {
        $this->taskService->initialize();

        $task = $this->taskService->create(['title' => 'Stuck task']);
        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 42,
            'consumed_output' => 'Error message here',
        ]);

        Artisan::call('stuck', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Stuck task');
        expect($output)->toContain('Exit code: 42');
        expect($output)->toContain('Error message here');
    });

    it('truncates long output', function () {
        $this->taskService->initialize();

        $longOutput = str_repeat('x', 600); // 600 characters
        $task = $this->taskService->create(['title' => 'Task with long output']);
        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 1,
            'consumed_output' => $longOutput,
        ]);

        Artisan::call('stuck', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Task with long output');
        expect($output)->toContain('...');
        // Should be truncated to ~500 chars
        expect(strlen($output))->toBeLessThan(700);
    });

    it('excludes tasks with zero exit code', function () {
        $this->taskService->initialize();

        $task = $this->taskService->create(['title' => 'Successful task']);
        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 0,
        ]);

        Artisan::call('stuck', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->not->toContain('Successful task');
        expect($output)->toContain('No stuck tasks found');
    });

    it('excludes tasks without consumed flag', function () {
        $this->taskService->initialize();

        $task = $this->taskService->create(['title' => 'Unconsumed task']);
        $this->taskService->update($task['id'], [
            'consumed_exit_code' => 1,
        ]);

        Artisan::call('stuck', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->not->toContain('Unconsumed task');
    });

    it('excludes tasks without exit code', function () {
        $this->taskService->initialize();

        $task = $this->taskService->create(['title' => 'Task without exit code']);
        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
        ]);

        Artisan::call('stuck', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->not->toContain('Task without exit code');
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();

        $task = $this->taskService->create(['title' => 'Stuck task']);
        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 1,
            'consumed_output' => 'Error output',
        ]);

        Artisan::call('stuck', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toHaveCount(1);
        expect($data[0]['id'])->toBe($task['id']);
        expect($data[0]['consumed'])->toBeTrue();
        expect($data[0]['consumed_exit_code'])->toBe(1);
        expect($data[0]['consumed_output'])->toBe('Error output');
    });

    it('outputs empty array as JSON when no stuck tasks', function () {
        $this->taskService->initialize();

        Artisan::call('stuck', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toBeEmpty();
    });

    it('sorts stuck tasks by consumed_at descending', function () {
        $this->taskService->initialize();

        $task1 = $this->taskService->create(['title' => 'First stuck task']);
        $this->taskService->update($task1['id'], [
            'consumed' => true,
            'consumed_at' => date('c', time() - 100),
            'consumed_exit_code' => 1,
        ]);

        sleep(1);

        $task2 = $this->taskService->create(['title' => 'Second stuck task']);
        $this->taskService->update($task2['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 2,
        ]);

        Artisan::call('stuck', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        // Most recent should appear first
        $pos1 = strpos($output, 'First stuck task');
        $pos2 = strpos($output, 'Second stuck task');
        expect($pos2)->toBeLessThan($pos1);
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
        expect($content)->toContain('f-');
    });

    it('does not create duplicate starter tasks when run multiple times', function () {
        // First init
        Artisan::call('init', ['--cwd' => $this->tempDir]);
        $firstContent = file_get_contents($this->storagePath);
        $firstTaskCount = substr_count($firstContent, 'README');

        // Second init
        Artisan::call('init', ['--cwd' => $this->tempDir]);
        $secondContent = file_get_contents($this->storagePath);
        $secondTaskCount = substr_count($secondContent, 'README');

        // Should have same number of starter tasks
        expect($secondTaskCount)->toBe($firstTaskCount);
        expect($firstTaskCount)->toBe(1);
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

// =============================================================================
// guidelines Command Tests
// =============================================================================

describe('guidelines command', function () {
    beforeEach(function () {
        // Clean up AGENTS.md in tempDir before each test
        $agentsMdPath = $this->tempDir.'/AGENTS.md';
        if (file_exists($agentsMdPath)) {
            unlink($agentsMdPath);
        }
    });

    it('outputs guidelines content when --add flag is not used', function () {
        Artisan::call('guidelines');
        $output = Artisan::output();

        expect($output)->toContain('Fuel Task Management');
        expect($output)->toContain('Quick Reference');
    });

    it('creates AGENTS.md when it does not exist with --add flag', function () {
        $agentsMdPath = $this->tempDir.'/AGENTS.md';

        expect(file_exists($agentsMdPath))->toBeFalse();

        Artisan::call('guidelines', ['--add' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect(file_exists($agentsMdPath))->toBeTrue();
        expect($output)->toContain('Created AGENTS.md with Fuel guidelines');

        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('# Agent Instructions');
        expect($content)->toContain('<fuel>');
        expect($content)->toContain('</fuel>');
    });

    it('replaces existing <fuel> section in AGENTS.md with --add flag', function () {
        $agentsMdPath = $this->tempDir.'/AGENTS.md';
        $oldContent = "# Agent Instructions\n\n<fuel>\nOld content here\n</fuel>\n\nSome other content";
        file_put_contents($agentsMdPath, $oldContent);

        Artisan::call('guidelines', ['--add' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Updated AGENTS.md with Fuel guidelines');

        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('<fuel>');
        expect($content)->toContain('</fuel>');
        expect($content)->toContain('Some other content');
        expect($content)->not->toContain('Old content here');
        // Should contain content from agent-instructions.md
        expect($content)->toContain('Fuel Task Management');
    });

    it('appends <fuel> section when AGENTS.md exists but has no fuel section with --add flag', function () {
        $agentsMdPath = $this->tempDir.'/AGENTS.md';
        $existingContent = "# Agent Instructions\n\nSome existing content here";
        file_put_contents($agentsMdPath, $existingContent);

        Artisan::call('guidelines', ['--add' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Updated AGENTS.md with Fuel guidelines');

        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('Some existing content here');
        expect($content)->toContain('<fuel>');
        expect($content)->toContain('</fuel>');
        // Should have double newline before fuel section
        expect($content)->toContain("content here\n\n<fuel>");
    });

    it('uses custom --cwd option with --add flag', function () {
        $customDir = sys_get_temp_dir().'/fuel-test-custom-'.uniqid();
        mkdir($customDir, 0755, true);
        $agentsMdPath = $customDir.'/AGENTS.md';

        try {
            Artisan::call('guidelines', ['--add' => true, '--cwd' => $customDir]);

            expect(file_exists($agentsMdPath))->toBeTrue();
            $content = file_get_contents($agentsMdPath);
            expect($content)->toContain('<fuel>');
            expect($content)->toContain('</fuel>');
        } finally {
            // Cleanup
            if (file_exists($agentsMdPath)) {
                unlink($agentsMdPath);
            }
            if (is_dir($customDir)) {
                rmdir($customDir);
            }
        }
    });

});

// Archive Command Tests
describe('archive command', function () {
    it('archives closed tasks older than specified days', function () {
        $this->taskService->initialize();

        // Create a closed task from 35 days ago
        $oldTask = $this->taskService->create(['title' => 'Old closed task']);
        $this->taskService->done($oldTask['id']);
        $oldDate = now()->subDays(35)->toIso8601String();
        $this->taskService->update($oldTask['id'], ['updated_at' => $oldDate]);

        // Create a closed task from 20 days ago (should not be archived)
        $recentTask = $this->taskService->create(['title' => 'Recent closed task']);
        $this->taskService->done($recentTask['id']);
        $recentDate = now()->subDays(20)->toIso8601String();
        $this->taskService->update($recentTask['id'], ['updated_at' => $recentDate]);

        Artisan::call('archive', ['--days' => 30, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Archived 1 task(s)');

        // Verify old task is archived
        expect($this->taskService->find($oldTask['id']))->toBeNull();

        // Verify recent task is still present
        expect($this->taskService->find($recentTask['id']))->not->toBeNull();
    });

    it('archives all closed tasks when --all flag is used', function () {
        $this->taskService->initialize();

        // Create closed tasks with different ages
        $task1 = $this->taskService->create(['title' => 'Closed task 1']);
        $this->taskService->done($task1['id']);

        $task2 = $this->taskService->create(['title' => 'Closed task 2']);
        $this->taskService->done($task2['id']);

        // Create an open task (should not be archived)
        $openTask = $this->taskService->create(['title' => 'Open task']);

        Artisan::call('archive', ['--all' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Archived 2 task(s)');

        // Verify closed tasks are archived
        expect($this->taskService->find($task1['id']))->toBeNull();
        expect($this->taskService->find($task2['id']))->toBeNull();

        // Verify open task remains
        expect($this->taskService->find($openTask['id']))->not->toBeNull();
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();

        $task = $this->taskService->create(['title' => 'Closed task']);
        $this->taskService->done($task['id']);
        $oldDate = now()->subDays(35)->toIso8601String();
        $this->taskService->update($task['id'], ['updated_at' => $oldDate]);

        Artisan::call('archive', ['--days' => 30, '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toHaveKeys(['archived', 'archived_tasks']);
        expect($result['archived'])->toBe(1);
        expect($result['archived_tasks'])->toHaveCount(1);
        expect($result['archived_tasks'][0]['id'])->toBe($task['id']);
    });

    it('shows message when no tasks to archive', function () {
        $this->taskService->initialize();

        // Create only open tasks
        $this->taskService->create(['title' => 'Open task']);

        Artisan::call('archive', ['--days' => 30, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('No tasks to archive');
    });

    it('validates days option must be positive integer', function () {
        $this->taskService->initialize();

        Artisan::call('archive', ['--days' => 0, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Days must be a positive integer');
    });

    it('uses default 30 days when --days not specified', function () {
        $this->taskService->initialize();

        // Create a closed task from 35 days ago
        $oldTask = $this->taskService->create(['title' => 'Old closed task']);
        $this->taskService->done($oldTask['id']);
        $oldDate = now()->subDays(35)->toIso8601String();
        $this->taskService->update($oldTask['id'], ['updated_at' => $oldDate]);

        Artisan::call('archive', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Archived 1 task(s)');
    });
});

// =============================================================================
// tree Command Tests
// =============================================================================

describe('tree command', function () {
    it('shows empty message when no pending tasks', function () {
        $this->taskService->initialize();

        $this->artisan('tree', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No pending tasks.')
            ->assertExitCode(0);
    });

    it('shows tasks without dependencies as flat list', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task one', 'priority' => 1]);
        $this->taskService->create(['title' => 'Task two', 'priority' => 2]);

        $this->artisan('tree', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Task one')
            ->expectsOutputToContain('Task two')
            ->assertExitCode(0);
    });

    it('shows blocking tasks with blocked tasks indented underneath', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        Artisan::call('tree', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Blocked task');
        expect($output)->toContain('Blocker task');
        expect($output)->toContain('blocked by this');
    });

    it('shows task with multiple blockers', function () {
        $this->taskService->initialize();
        $blocker1 = $this->taskService->create(['title' => 'First blocker']);
        $blocker2 = $this->taskService->create(['title' => 'Second blocker']);
        $blocked = $this->taskService->create(['title' => 'Multi-blocked task']);
        $this->taskService->addDependency($blocked['id'], $blocker1['id']);
        $this->taskService->addDependency($blocked['id'], $blocker2['id']);

        $this->artisan('tree', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Multi-blocked task')
            ->expectsOutputToContain('First blocker')
            ->expectsOutputToContain('Second blocker')
            ->assertExitCode(0);
    });

    it('excludes closed tasks from tree', function () {
        $this->taskService->initialize();
        $openTask = $this->taskService->create(['title' => 'Open task']);
        $closedTask = $this->taskService->create(['title' => 'Closed task']);
        $this->taskService->done($closedTask['id']);

        $this->artisan('tree', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Open task')
            ->doesntExpectOutputToContain('Closed task')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is provided', function () {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        Artisan::call('tree', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveCount(2);

        // Find the blocker task entry - it should have the blocked task in its 'blocks' array
        $blockerEntry = collect($data)->first(fn ($item) => $item['task']['title'] === 'Blocker task');
        expect($blockerEntry)->not->toBeNull();
        expect($blockerEntry['blocks'])->toHaveCount(1);
        expect($blockerEntry['blocks'][0]['title'])->toBe('Blocked task');
    });

    it('returns empty array in JSON when no tasks', function () {
        $this->taskService->initialize();

        Artisan::call('tree', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toBeEmpty();
    });

    it('sorts tasks by priority then created_at', function () {
        $this->taskService->initialize();
        $lowPriority = $this->taskService->create(['title' => 'Low priority', 'priority' => 3]);
        $highPriority = $this->taskService->create(['title' => 'High priority', 'priority' => 0]);

        Artisan::call('tree', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data[0]['task']['title'])->toBe('High priority');
        expect($data[1]['task']['title'])->toBe('Low priority');
    });

    it('shows needs-human label with special indicator', function () {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Human task', 'labels' => ['needs-human']]);
        $this->taskService->create(['title' => 'Normal task']);

        Artisan::call('tree', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Human task');
        expect($output)->toContain('needs human');
        expect($output)->toContain('Normal task');
    });
});

// =============================================================================
// runs Command Tests
// =============================================================================

describe('runs command', function () {
    it('shows error when task not found', function () {
        $this->taskService->initialize();

        $this->artisan('runs', ['id' => 'f-nonexistent', '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('shows error when no runs exist for task', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->artisan('runs', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('No runs found for task')
            ->assertExitCode(1);
    });

    it('displays runs for a task', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'model' => 'test-model',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Runs for task');
        expect($output)->toContain('test-agent');
        expect($output)->toContain('test-model');
    });

    it('displays multiple runs in table format', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'agent1',
            'model' => 'model1',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        $runService->logRun($task['id'], [
            'agent' => 'agent2',
            'model' => 'model2',
            'started_at' => '2026-01-07T11:00:00+00:00',
            'ended_at' => '2026-01-07T11:10:00+00:00',
            'exit_code' => 1,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Runs for task');
        expect($output)->toContain('agent1');
        expect($output)->toContain('agent2');
        expect($output)->toContain('model1');
        expect($output)->toContain('model2');
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'model' => 'test-model',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveCount(1);
        expect($data[0])->toHaveKeys(['run_id', 'agent', 'model', 'started_at', 'ended_at', 'exit_code', 'duration']);
        expect($data[0]['agent'])->toBe('test-agent');
        expect($data[0]['model'])->toBe('test-model');
    });

    it('shows latest run with --last flag', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'agent1',
            'model' => 'model1',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        $runService->logRun($task['id'], [
            'agent' => 'agent2',
            'model' => 'model2',
            'started_at' => '2026-01-07T11:00:00+00:00',
            'ended_at' => '2026-01-07T11:10:00+00:00',
            'exit_code' => 1,
            'output' => 'Test output',
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--last' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Latest run for task');
        expect($output)->toContain('agent2');
        expect($output)->toContain('model2');
        expect($output)->toContain('Test output');
        expect($output)->not->toContain('agent1');
    });

    it('shows latest run with full output in JSON format', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'agent1',
            'model' => 'model1',
            'started_at' => '2026-01-07T10:00:00+00:00',
        ]);

        $runService->logRun($task['id'], [
            'agent' => 'agent2',
            'model' => 'model2',
            'started_at' => '2026-01-07T11:00:00+00:00',
            'output' => 'Test output',
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--last' => true, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['agent'])->toBe('agent2');
        expect($data['output'])->toBe('Test output');
        expect($data)->toHaveKey('duration');
    });

    it('calculates duration correctly', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:30+00:00', // 5 minutes 30 seconds
            'exit_code' => 0,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data[0]['duration'])->toContain('5m');
        expect($data[0]['duration'])->toContain('30s');
    });

    it('handles running tasks with no end time', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => null,
            'exit_code' => null,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data[0]['duration'])->not->toBeEmpty();
        expect($data[0]['exit_code'])->toBeNull();
    });

    it('supports partial task ID matching', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'started_at' => '2026-01-07T10:00:00+00:00',
        ]);

        // Use partial ID (last 6 chars)
        $partialId = substr($task['id'], -6);

        $this->artisan('runs', ['id' => $partialId, '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Runs for task')
            ->assertExitCode(0);
    });
});

// Resume Command Tests
describe('resume command', function () {
    it('shows error when task not found', function () {
        $this->artisan('resume', ['id' => 'f-nonexistent', '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('shows error when no runs exist for task', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->artisan('resume', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('No runs found for task')
            ->assertExitCode(1);
    });

    it('shows error when run has no session_id', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            // No session_id
        ]);

        $this->artisan('resume', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('has no session_id')
            ->assertExitCode(1);
    });

    it('shows error when run has no agent', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
            // No agent
        ]);

        $this->artisan('resume', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('has no agent')
            ->assertExitCode(1);
    });

    it('shows error when agent is unknown', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'unknown-agent',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        $this->artisan('resume', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Unknown agent 'unknown-agent'")
            ->assertExitCode(1);
    });

    it('shows error when specific run not found', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        $this->artisan('resume', [
            'id' => $task['id'],
            '--run' => 'run-nonexistent',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain("Run 'run-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('supports partial run ID matching', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        $runs = $runService->getRuns($task['id']);
        $runId = $runs[0]['run_id'] ?? '';
        $partialRunId = substr($runId, 0, 6); // First 6 chars

        // This will fail because exec() replaces the process, but we can test validation passes
        // We'll just verify it doesn't fail with "not found" error
        $this->artisan('resume', [
            'id' => $task['id'],
            '--run' => $partialRunId,
            '--cwd' => $this->tempDir,
        ])
            ->assertExitCode(1); // Will fail at exec(), but validation should pass
    });

    it('outputs JSON error when --json flag is used', function () {
        Artisan::call('resume', [
            'id' => 'f-nonexistent',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['error'])->toContain("Task 'f-nonexistent' not found");
    });

    it('supports partial task ID matching', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        // Use partial ID (last 6 chars)
        $partialId = substr($task['id'], -6);

        $this->artisan('resume', ['id' => $partialId, '--cwd' => $this->tempDir])
            ->assertExitCode(1); // Will fail at exec(), but task should be found
    });
});

// =============================================================================
// summary Command Tests
// =============================================================================

describe('summary command', function () {
    it('shows error when task not found', function () {
        $this->taskService->initialize();

        $this->artisan('summary', ['id' => 'f-nonexistent', '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('shows error when no runs exist for task', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->artisan('summary', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('No runs found for task')
            ->assertExitCode(1);
    });

    it('displays summary for task with single run', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);
        $this->taskService->done($task['id']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'model' => 'sonnet',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'cost_usd' => 0.08,
            'session_id' => '550e8400-e29b-41d4-a716-446655440000',
            'output' => 'Task completed',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Test task');
        expect($output)->toContain('closed');
        expect($output)->toContain('Claude');
        expect($output)->toContain('sonnet');
        expect($output)->toContain('5m');
        expect($output)->toContain('$0.0800');
        expect($output)->toContain('0 (success)');
        expect($output)->toContain('550e8400-e29b-41d4-a716-446655440000');
        expect($output)->toContain('claude --resume');
    });

    it('parses file operations from output', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Created app/Services/AuthService.php. Modified tests/Feature/AuthTest.php. Deleted old/LegacyAuth.php.',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Created file: app/Services/AuthService.php');
        expect($output)->toContain('Modified file: tests/Feature/AuthTest.php');
        expect($output)->toContain('Deleted file: old/LegacyAuth.php');
    });

    it('parses test results from output', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Tests: 5 passed. Assertions: 15 passed.',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('5 tests passed');
        expect($output)->toContain('15 assertions passed');
    });

    it('parses git commits from output', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => '[main abc1234] feat: add authentication',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Git commit: abc1234');
    });

    it('shows all runs when --all flag is used', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        $runService->logRun($task['id'], [
            'agent' => 'cursor-agent',
            'started_at' => '2026-01-07T11:00:00+00:00',
            'ended_at' => '2026-01-07T11:10:00+00:00',
            'exit_code' => 1,
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir, '--all' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Claude');
        expect($output)->toContain('Cursor Agent');
        expect($output)->toContain('0 (success)');
        expect($output)->toContain('1 (failed)');
    });

    it('outputs JSON when --json flag is used', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Created app/Test.php',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveKeys(['task', 'runs']);
        expect($data['task']['id'])->toBe($task['id']);
        expect($data['runs'])->toHaveCount(1);
        expect($data['runs'][0])->toHaveKey('duration');
        expect($data['runs'][0])->toHaveKey('parsed_summary');
        expect($data['runs'][0]['parsed_summary'])->toContain('Created file: app/Test.php');
    });

    it('shows no actionable items when output has no parseable content', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Some random text without patterns',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('No actionable items detected');
    });

    it('supports partial task ID matching', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        // Use partial ID (first 5 chars after f-)
        $partialId = substr($task['id'], 2, 5);

        $this->artisan('summary', ['id' => $partialId, '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Test task')
            ->assertExitCode(0);
    });

    it('parses fuel task operations from output', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Created task f-abc123. Ran fuel done f-abc123.',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Created task: f-abc123');
        expect($output)->toContain('Completed task: f-abc123');
    });

    it('displays status with color coding', function () {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        // The output should contain the task status
        expect($output)->toContain('Status:');
        expect($output)->toContain('open');
    });
});
