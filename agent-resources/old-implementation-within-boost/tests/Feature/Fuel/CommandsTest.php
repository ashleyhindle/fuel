<?php

declare(strict_types=1);

use Laravel\Boost\Fuel\TaskService;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->storagePath = $this->tempDir.'/tasks.jsonl';

    $this->app->singleton(TaskService::class, function () {
        return new TaskService($this->storagePath);
    });

    $this->service = $this->app->make(TaskService::class);
    $this->service->initialize();
});

afterEach(function () {
    // Clean up all files in temp directory
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

it('adds a task via fuel:add command', function () {
    $this->artisan('fuel:add', [
        'title' => 'Test task',
        '--type' => 'feature',
        '--priority' => 1,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Created task:')
        ->expectsOutputToContain('Test task');
});

it('adds a task with JSON output', function () {
    $this->artisan('fuel:add', [
        'title' => 'Test task',
        '--json' => true,
    ])
        ->assertSuccessful();

    $tasks = $this->service->all();
    expect($tasks->count())->toBe(1)
        ->and($tasks->first()['title'])->toBe('Test task');
});

it('adds a task with labels', function () {
    $this->artisan('fuel:add', [
        'title' => 'Test task',
        '--labels' => 'backend,api',
    ])
        ->assertSuccessful();

    $task = $this->service->all()->first();
    expect($task['labels'])->toBe(['backend', 'api']);
});

it('adds a task with dependencies', function () {
    $blocker = $this->service->create(['title' => 'Blocker']);

    $this->artisan('fuel:add', [
        'title' => 'Blocked task',
        '--blocked-by' => $blocker['id'],
    ])
        ->assertSuccessful();

    $blocked = $this->service->all()->firstWhere('title', 'Blocked task');
    expect($blocked['dependencies'])->toHaveCount(1)
        ->and($blocked['dependencies'][0]['depends_on'])->toBe($blocker['id']);
});

it('lists tasks via fuel:list command', function () {
    $this->service->create(['title' => 'Task 1', 'type' => 'bug']);
    $this->service->create(['title' => 'Task 2', 'type' => 'feature']);

    $this->artisan('fuel:list')
        ->assertSuccessful()
        ->expectsOutputToContain('Task 1')
        ->expectsOutputToContain('Task 2');
});

it('lists tasks filtered by status', function () {
    $this->service->create(['title' => 'Open task']);
    $closed = $this->service->create(['title' => 'Closed task']);
    $this->service->close($closed['id']);

    $this->artisan('fuel:list', ['--status' => 'open'])
        ->assertSuccessful()
        ->expectsOutputToContain('Open task');
});

it('lists tasks filtered by type', function () {
    $this->service->create(['title' => 'Bug task', 'type' => 'bug']);
    $this->service->create(['title' => 'Feature task', 'type' => 'feature']);

    $this->artisan('fuel:list', ['--type' => 'bug'])
        ->assertSuccessful()
        ->expectsOutputToContain('Bug task');
});

it('lists tasks with JSON output', function () {
    $this->service->create(['title' => 'Test task']);

    $this->artisan('fuel:list', ['--json' => true])
        ->assertSuccessful();
});

it('shows ready tasks via fuel:ready command', function () {
    $this->service->create(['title' => 'Ready task']);

    $this->artisan('fuel:ready')
        ->assertSuccessful()
        ->expectsOutputToContain('Ready work')
        ->expectsOutputToContain('Ready task');
});

it('shows only unblocked ready tasks', function () {
    $blocker = $this->service->create(['title' => 'Blocker']);
    $this->service->create([
        'title' => 'Blocked',
        'dependencies' => [['depends_on' => $blocker['id'], 'type' => 'blocks']],
    ]);

    $this->artisan('fuel:ready')
        ->assertSuccessful()
        ->expectsOutputToContain('Blocker'); // Only blocker is ready (not blocked by anything)
});

it('shows ready tasks with JSON output', function () {
    $this->service->create(['title' => 'Test task']);

    $this->artisan('fuel:ready', ['--json' => true])
        ->assertSuccessful();
});

it('closes a task via fuel:done command', function () {
    $task = $this->service->create(['title' => 'Test task']);

    $this->artisan('fuel:done', [
        'id' => $task['id'],
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Closed task:')
        ->expectsOutputToContain('Test task');

    $updated = $this->service->find($task['id']);
    expect($updated['status'])->toBe('closed');
});

it('closes a task with partial ID', function () {
    $task = $this->service->create(['title' => 'Test task']);

    // Use just the first part of the hash
    $partialId = substr($task['id'], 5, 2); // Skip 'fuel-' and take 2 chars

    $this->artisan('fuel:done', [
        'id' => $partialId,
    ])
        ->assertSuccessful();

    $updated = $this->service->find($task['id']);
    expect($updated['status'])->toBe('closed');
});

it('shows unblocked tasks when closing a blocker', function () {
    $blocker = $this->service->create(['title' => 'Blocker task']);
    $blocked = $this->service->create([
        'title' => 'Blocked task',
        'dependencies' => [['depends_on' => $blocker['id'], 'type' => 'blocks']],
    ]);

    $this->artisan('fuel:done', [
        'id' => $blocker['id'],
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Now unblocked')
        ->expectsOutputToContain('Blocked task');
});

it('closes a task with reason', function () {
    $task = $this->service->create(['title' => 'Test task']);

    $this->artisan('fuel:done', [
        'id' => $task['id'],
        '--reason' => 'Completed successfully',
    ])
        ->assertSuccessful();

    $updated = $this->service->find($task['id']);
    expect($updated['closed_reason'])->toBe('Completed successfully');
});

it('closes a task with JSON output including unblocked tasks', function () {
    $blocker = $this->service->create(['title' => 'Blocker']);
    $blocked = $this->service->create([
        'title' => 'Blocked',
        'dependencies' => [['depends_on' => $blocker['id'], 'type' => 'blocks']],
    ]);

    $this->artisan('fuel:done', [
        'id' => $blocker['id'],
        '--json' => true,
    ])
        ->assertSuccessful();
});

it('fails when task not found', function () {
    $this->artisan('fuel:done', [
        'id' => 'nonexistent',
    ])
        ->assertFailed();
});

it('displays kanban board with columns', function () {
    $this->service->create(['title' => 'Ready task']);

    $this->artisan('fuel:board')
        ->assertSuccessful();
});

it('shows blocked tasks in pending column', function () {
    $blocker = $this->service->create(['title' => 'Blocker']);
    $this->service->create([
        'title' => 'Blocked task',
        'dependencies' => [['depends_on' => $blocker['id'], 'type' => 'blocks']],
    ]);

    $this->artisan('fuel:board')
        ->assertSuccessful();
});
