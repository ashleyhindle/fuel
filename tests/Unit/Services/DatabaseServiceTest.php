<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir);
    $this->dbPath = $this->tempDir.'/test-agent.db';
    $this->service = new DatabaseService($this->dbPath);
    config(['database.connections.sqlite.database' => $this->dbPath]);
    Artisan::call('migrate', ['--force' => true]);
});

it('creates database file on initialization', function (): void {
    // Database file is now created in constructor via configureDatabase
    expect(file_exists($this->dbPath))->toBeTrue();

    // Initialize creates the tables

    // Verify tables exist
    $tables = $this->service->fetchAll(
        "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
    );
    expect(count($tables))->toBeGreaterThan(0);
});

it('creates indexes on runs table', function (): void {

    $indexes = $this->service->fetchAll('PRAGMA index_list(runs)');
    $indexNames = array_column($indexes, 'name');

    // SQLite auto-creates an index for PRIMARY KEY, so we check for our custom indexes
    expect($indexNames)->toContain('idx_runs_short_id');
    expect($indexNames)->toContain('idx_runs_task_id');
    expect($indexNames)->toContain('idx_runs_agent');
});

it('can insert and query data from runs table', function (): void {

    // Create a task first (required for foreign key constraint)
    $this->service->query(
        'INSERT INTO tasks (short_id, title) VALUES (?, ?)',
        ['f-123456', 'Test task']
    );
    $taskId = $this->service->fetchOne('SELECT id FROM tasks WHERE short_id = ?', ['f-123456'])['id'];

    $this->service->query(
        'INSERT INTO runs (short_id, task_id, agent, status, started_at) VALUES (?, ?, ?, ?, ?)',
        ['run-1', $taskId, 'claude', 'running', '2026-01-10T12:00:00Z']
    );

    $result = $this->service->fetchOne('SELECT * FROM runs WHERE short_id = ?', ['run-1']);

    expect($result)->not->toBeNull();
    expect($result['short_id'])->toBe('run-1');
    expect($result['task_id'])->toBe($taskId);
    expect($result['agent'])->toBe('claude');
    expect($result['status'])->toBe('running');
});

it('can insert and query data from agent_health table', function (): void {

    $this->service->query(
        'INSERT INTO agent_health (agent, total_runs, total_successes, consecutive_failures) VALUES (?, ?, ?, ?)',
        ['claude', 10, 8, 2]
    );

    $result = $this->service->fetchOne('SELECT * FROM agent_health WHERE agent = ?', ['claude']);

    expect($result)->not->toBeNull();
    expect($result['agent'])->toBe('claude');
    expect($result['total_runs'])->toBe(10);
    expect($result['total_successes'])->toBe(8);
    expect($result['consecutive_failures'])->toBe(2);
});

it('supports transactions', function (): void {

    // Create a task first (required for foreign key constraint)
    $this->service->query(
        'INSERT INTO tasks (short_id, title) VALUES (?, ?)',
        ['f-123456', 'Test task']
    );
    $taskId = $this->service->fetchOne('SELECT id FROM tasks WHERE short_id = ?', ['f-123456'])['id'];

    $this->service->beginTransaction();
    $this->service->query(
        'INSERT INTO runs (short_id, task_id, agent) VALUES (?, ?, ?)',
        ['run-1', $taskId, 'claude']
    );
    $this->service->rollback();

    $result = $this->service->fetchOne('SELECT * FROM runs WHERE short_id = ?', ['run-1']);
    expect($result)->toBeNull();

    $this->service->beginTransaction();
    $this->service->query(
        'INSERT INTO runs (short_id, task_id, agent) VALUES (?, ?, ?)',
        ['run-2', $taskId, 'claude']
    );
    $this->service->commit();

    $result = $this->service->fetchOne('SELECT * FROM runs WHERE short_id = ?', ['run-2']);
    expect($result)->not->toBeNull();
});

it('checks if database exists', function (): void {
    // Database file is now created in constructor via configureDatabase
    expect($this->service->exists())->toBeTrue();

    expect($this->service->exists())->toBeTrue();
});

it('creates index on epics status', function (): void {

    $indexes = $this->service->fetchAll('PRAGMA index_list(epics)');
    $indexNames = array_column($indexes, 'name');

    expect($indexNames)->toContain('idx_epics_status');
});
