<?php

declare(strict_types=1);

use App\Services\DatabaseService;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir);
    $this->dbPath = $this->tempDir.'/test-agent.db';
    $this->service = new DatabaseService($this->dbPath);
});

afterEach(function () {
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

it('creates database file on initialization', function () {
    expect(file_exists($this->dbPath))->toBeFalse();

    $this->service->initialize();

    expect(file_exists($this->dbPath))->toBeTrue();
});

it('creates runs table with correct schema', function () {
    $this->service->initialize();

    $columns = $this->service->fetchAll('PRAGMA table_info(runs)');

    expect($columns)->toHaveCount(10);
    expect(array_column($columns, 'name'))->toBe([
        'id',
        'task_id',
        'agent',
        'status',
        'exit_code',
        'started_at',
        'completed_at',
        'duration_seconds',
        'session_id',
        'error_type',
    ]);
});

it('creates agent_health table with correct schema', function () {
    $this->service->initialize();

    $columns = $this->service->fetchAll('PRAGMA table_info(agent_health)');

    expect($columns)->toHaveCount(7);
    expect(array_column($columns, 'name'))->toBe([
        'agent',
        'last_success_at',
        'last_failure_at',
        'consecutive_failures',
        'backoff_until',
        'total_runs',
        'total_successes',
    ]);
});

it('creates indexes on runs table', function () {
    $this->service->initialize();

    $indexes = $this->service->fetchAll('PRAGMA index_list(runs)');
    $indexNames = array_column($indexes, 'name');

    // SQLite auto-creates an index for PRIMARY KEY, so we check for our custom indexes
    expect($indexNames)->toContain('idx_runs_task');
    expect($indexNames)->toContain('idx_runs_agent');
});

it('can insert and query data from runs table', function () {
    $this->service->initialize();

    $this->service->query(
        'INSERT INTO runs (id, task_id, agent, status, started_at) VALUES (?, ?, ?, ?, ?)',
        ['run-1', 'f-123456', 'claude', 'running', '2026-01-10T12:00:00Z']
    );

    $result = $this->service->fetchOne('SELECT * FROM runs WHERE id = ?', ['run-1']);

    expect($result)->not->toBeNull();
    expect($result['id'])->toBe('run-1');
    expect($result['task_id'])->toBe('f-123456');
    expect($result['agent'])->toBe('claude');
    expect($result['status'])->toBe('running');
});

it('can insert and query data from agent_health table', function () {
    $this->service->initialize();

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

it('supports transactions', function () {
    $this->service->initialize();

    $this->service->beginTransaction();
    $this->service->query(
        'INSERT INTO runs (id, task_id, agent) VALUES (?, ?, ?)',
        ['run-1', 'f-123456', 'claude']
    );
    $this->service->rollback();

    $result = $this->service->fetchOne('SELECT * FROM runs WHERE id = ?', ['run-1']);
    expect($result)->toBeNull();

    $this->service->beginTransaction();
    $this->service->query(
        'INSERT INTO runs (id, task_id, agent) VALUES (?, ?, ?)',
        ['run-2', 'f-123456', 'claude']
    );
    $this->service->commit();

    $result = $this->service->fetchOne('SELECT * FROM runs WHERE id = ?', ['run-2']);
    expect($result)->not->toBeNull();
});

it('can update database path', function () {
    $newDbPath = $this->tempDir.'/new-agent.db';
    $this->service->setDatabasePath($newDbPath);
    $this->service->initialize();

    expect(file_exists($newDbPath))->toBeTrue();
    expect($this->service->getPath())->toBe($newDbPath);

    if (file_exists($newDbPath)) {
        unlink($newDbPath);
    }
});

it('checks if database exists', function () {
    expect($this->service->exists())->toBeFalse();

    $this->service->initialize();

    expect($this->service->exists())->toBeTrue();
});

it('creates epics table with correct schema', function () {
    $this->service->initialize();

    $columns = $this->service->fetchAll('PRAGMA table_info(epics)');

    expect($columns)->toHaveCount(8);
    expect(array_column($columns, 'name'))->toBe([
        'id',
        'short_id',
        'title',
        'description',
        'status',
        'reviewed_at',
        'created_at',
        'updated_at',
    ]);
});

it('creates index on epics status', function () {
    $this->service->initialize();

    $indexes = $this->service->fetchAll('PRAGMA index_list(epics)');
    $indexNames = array_column($indexes, 'name');

    expect($indexNames)->toContain('idx_epics_status');
});

it('auto-migrates on first getConnection call', function () {
    // Just calling getConnection should trigger auto-migration
    $connection = $this->service->getConnection();

    // Verify schema_version table exists and has correct version
    $version = $connection->query('SELECT version FROM schema_version LIMIT 1')->fetch();
    expect($version['version'])->toBe(3);

    // Verify tables were created
    $tables = $this->service->fetchAll(
        "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
    );
    $tableNames = array_column($tables, 'name');

    expect($tableNames)->toContain('runs');
    expect($tableNames)->toContain('agent_health');
    expect($tableNames)->toContain('reviews');
    expect($tableNames)->toContain('epics');
    expect($tableNames)->toContain('schema_version');
});

it('does not re-run migrations on subsequent connections', function () {
    // First connection triggers migration
    $this->service->getConnection();

    // Insert test data
    $this->service->query(
        'INSERT INTO runs (id, task_id, agent) VALUES (?, ?, ?)',
        ['run-test', 'f-123456', 'claude']
    );

    // Create new service pointing to same DB
    $newService = new DatabaseService($this->dbPath);
    $newService->getConnection();

    // Data should still exist (migrations didn't wipe it)
    $result = $newService->fetchOne('SELECT * FROM runs WHERE id = ?', ['run-test']);
    expect($result)->not->toBeNull();
    expect($result['id'])->toBe('run-test');
});

it('runs only pending migrations when upgrading', function () {
    $connection = $this->service->getConnection();

    // Manually set version to 1 (simulating older DB)
    $connection->exec('DELETE FROM schema_version');
    $connection->exec('INSERT INTO schema_version (version) VALUES (1)');

    // Drop epics table (v2/v3 migration)
    $connection->exec('DROP TABLE IF EXISTS epics');

    // Create new service to trigger migration check
    $newService = new DatabaseService($this->dbPath);
    $newService->getConnection();

    // Version should be updated to 3
    $version = $connection->query('SELECT version FROM schema_version LIMIT 1')->fetch();
    expect($version['version'])->toBe(3);

    // Epics table should be recreated
    $tables = $newService->fetchAll(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='epics'"
    );
    expect($tables)->toHaveCount(1);
});

it('handles fresh database with no schema_version table', function () {
    // Create a raw SQLite DB with no tables
    $rawPdo = new PDO('sqlite:'.$this->dbPath);
    $rawPdo->exec('CREATE TABLE dummy (id TEXT)');
    $rawPdo = null;

    // New service should migrate from version 0
    $newService = new DatabaseService($this->dbPath);
    $newService->getConnection();

    // Should have all tables now
    $version = $newService->fetchOne('SELECT version FROM schema_version LIMIT 1');
    expect($version['version'])->toBe(3);
});
