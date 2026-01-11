<?php

declare(strict_types=1);

use App\Services\DatabaseService;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir);
    $this->dbPath = $this->tempDir.'/test-agent.db';
    $this->service = new DatabaseService($this->dbPath);
});

it('creates database file on initialization', function (): void {
    expect(file_exists($this->dbPath))->toBeFalse();

    $this->service->initialize();

    expect(file_exists($this->dbPath))->toBeTrue();
});

it('creates runs table with correct schema', function (): void {
    $this->service->initialize();

    $columns = $this->service->fetchAll('PRAGMA table_info(runs)');

    expect($columns)->toHaveCount(14);
    expect(array_column($columns, 'name'))->toBe([
        'id',
        'short_id',
        'task_id',
        'agent',
        'status',
        'exit_code',
        'started_at',
        'ended_at',
        'duration_seconds',
        'session_id',
        'error_type',
        'model',
        'output',
        'cost_usd',
    ]);
});

it('creates agent_health table with correct schema', function (): void {
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

it('creates indexes on runs table', function (): void {
    $this->service->initialize();

    $indexes = $this->service->fetchAll('PRAGMA index_list(runs)');
    $indexNames = array_column($indexes, 'name');

    // SQLite auto-creates an index for PRIMARY KEY, so we check for our custom indexes
    expect($indexNames)->toContain('idx_runs_short_id');
    expect($indexNames)->toContain('idx_runs_task_id');
    expect($indexNames)->toContain('idx_runs_agent');
});

it('can insert and query data from runs table', function (): void {
    $this->service->initialize();

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

it('supports transactions', function (): void {
    $this->service->initialize();

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

it('can update database path', function (): void {
    $newDbPath = $this->tempDir.'/new-agent.db';
    $this->service->setDatabasePath($newDbPath);
    $this->service->initialize();

    expect(file_exists($newDbPath))->toBeTrue();
    expect($this->service->getPath())->toBe($newDbPath);

    if (file_exists($newDbPath)) {
        unlink($newDbPath);
    }
});

it('checks if database exists', function (): void {
    expect($this->service->exists())->toBeFalse();

    $this->service->initialize();

    expect($this->service->exists())->toBeTrue();
});

it('creates epics table with correct schema', function (): void {
    $this->service->initialize();

    $columns = $this->service->fetchAll('PRAGMA table_info(epics)');

    expect($columns)->toHaveCount(11);
    expect(array_column($columns, 'name'))->toBe([
        'id',
        'short_id',
        'title',
        'description',
        'status',
        'reviewed_at',
        'created_at',
        'updated_at',
        'approved_at',
        'approved_by',
        'changes_requested_at',
    ]);
});

it('creates index on epics status', function (): void {
    $this->service->initialize();

    $indexes = $this->service->fetchAll('PRAGMA index_list(epics)');
    $indexNames = array_column($indexes, 'name');

    expect($indexNames)->toContain('idx_epics_status');
});

it('auto-migrates on first getConnection call', function (): void {
    // Just calling getConnection should trigger auto-migration
    $connection = $this->service->getConnection();

    // Verify schema_version table exists and has correct version
    $version = $connection->query('SELECT version FROM schema_version LIMIT 1')->fetch();
    expect($version['version'])->toBe(13);

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

it('does not re-run migrations on subsequent connections', function (): void {
    // First connection triggers migration
    $this->service->getConnection();

    // Create a task first (required for foreign key constraint)
    $this->service->query(
        'INSERT INTO tasks (short_id, title) VALUES (?, ?)',
        ['f-123456', 'Test task']
    );
    $taskId = $this->service->fetchOne('SELECT id FROM tasks WHERE short_id = ?', ['f-123456'])['id'];

    // Insert test data into runs using new schema (short_id instead of id)
    $this->service->query(
        'INSERT INTO runs (short_id, task_id, agent) VALUES (?, ?, ?)',
        ['run-test', $taskId, 'claude']
    );

    // Create new service pointing to same DB
    $newService = new DatabaseService($this->dbPath);
    $newService->getConnection();

    // Data should still exist (migrations didn't wipe it)
    $result = $newService->fetchOne('SELECT * FROM runs WHERE short_id = ?', ['run-test']);
    expect($result)->not->toBeNull();
    expect($result['short_id'])->toBe('run-test');
});

it('runs only pending migrations when upgrading', function (): void {
    $connection = $this->service->getConnection();

    // Manually set version to 1 (simulating older DB)
    $connection->exec('DELETE FROM schema_version');
    $connection->exec('INSERT INTO schema_version (version) VALUES (1)');

    // Drop epics table (v2/v3 migration)
    $connection->exec('DROP TABLE IF EXISTS epics');

    // Create new service to trigger migration check
    $newService = new DatabaseService($this->dbPath);
    $newService->getConnection();

    // Version should be updated to latest
    $version = $connection->query('SELECT version FROM schema_version LIMIT 1')->fetch();
    expect($version['version'])->toBe(13);

    // Epics table should be recreated
    $tables = $newService->fetchAll(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='epics'"
    );
    expect($tables)->toHaveCount(1);
});

it('handles fresh database with no schema_version table', function (): void {
    // Create a raw SQLite DB with no tables
    $rawPdo = new PDO('sqlite:'.$this->dbPath);
    $rawPdo->exec('CREATE TABLE dummy (id TEXT)');
    $rawPdo = null;

    // New service should migrate from version 0
    $newService = new DatabaseService($this->dbPath);
    $newService->getConnection();

    // Should have all tables now
    $version = $newService->fetchOne('SELECT version FROM schema_version LIMIT 1');
    expect($version['version'])->toBe(13);
});

it('migrates backlog.jsonl items to tasks with status=someday', function (): void {
    // Setup: Create .fuel directory and backlog.jsonl file
    $fuelDir = $this->tempDir.'/.fuel';
    mkdir($fuelDir, 0755, true);

    $backlogPath = $fuelDir.'/backlog.jsonl';
    $lockPath = $backlogPath.'.lock';

    // Create backlog.jsonl with test items
    $backlogItems = [
        json_encode([
            'id' => 'b-7d1601',
            'title' => 'Test backlog item 1',
            'description' => 'First test description',
            'created_at' => '2026-01-10T10:00:00+00:00',
        ]),
        json_encode([
            'id' => 'b-812288',
            'title' => 'Test backlog item 2',
            'description' => null,
            'created_at' => '2026-01-10T11:00:00+00:00',
        ]),
    ];
    file_put_contents($backlogPath, implode("\n", $backlogItems)."\n");
    touch($lockPath);

    // Change working directory to temp dir so migration finds the backlog file
    $oldCwd = getcwd();
    chdir($this->tempDir);

    try {
        // Trigger migration by getting connection
        $this->service->getConnection();

        // Verify backlog files were deleted
        expect(file_exists($backlogPath))->toBeFalse();
        expect(file_exists($lockPath))->toBeFalse();

        // Verify tasks were created with status=someday
        $tasks = $this->service->fetchAll("SELECT * FROM tasks WHERE status = 'someday' ORDER BY created_at");
        expect($tasks)->toHaveCount(2);

        // Check first task
        expect($tasks[0]['title'])->toBe('Test backlog item 1');
        expect($tasks[0]['description'])->toBe('First test description');
        expect($tasks[0]['status'])->toBe('someday');
        expect($tasks[0]['type'])->toBe('task');
        expect($tasks[0]['priority'])->toBe(2);
        expect($tasks[0]['complexity'])->toBe('moderate');
        expect($tasks[0]['short_id'])->toStartWith('f-');
        expect($tasks[0]['created_at'])->toBe('2026-01-10T10:00:00+00:00');

        // Check second task
        expect($tasks[1]['title'])->toBe('Test backlog item 2');
        expect($tasks[1]['description'])->toBeNull();
        expect($tasks[1]['status'])->toBe('someday');
        expect($tasks[1]['short_id'])->toStartWith('f-');
    } finally {
        chdir($oldCwd);
    }
});

it('handles empty backlog.jsonl during migration', function (): void {
    // Setup: Create .fuel directory and empty backlog.jsonl file
    $fuelDir = $this->tempDir.'/.fuel';
    mkdir($fuelDir, 0755, true);

    $backlogPath = $fuelDir.'/backlog.jsonl';
    $lockPath = $backlogPath.'.lock';

    file_put_contents($backlogPath, '');
    touch($lockPath);

    // Change working directory to temp dir
    $oldCwd = getcwd();
    chdir($this->tempDir);

    try {
        // Trigger migration
        $this->service->getConnection();

        // Verify files were deleted
        expect(file_exists($backlogPath))->toBeFalse();
        expect(file_exists($lockPath))->toBeFalse();

        // Verify no tasks were created
        $tasks = $this->service->fetchAll("SELECT * FROM tasks WHERE status = 'someday'");
        expect($tasks)->toBeEmpty();
    } finally {
        chdir($oldCwd);
    }
});

it('is idempotent when backlog.jsonl does not exist', function (): void {
    // Setup: Create .fuel directory but no backlog.jsonl
    $fuelDir = $this->tempDir.'/.fuel';
    mkdir($fuelDir, 0755, true);

    // Change working directory to temp dir
    $oldCwd = getcwd();
    chdir($this->tempDir);

    try {
        // Trigger migration (should be no-op)
        $this->service->getConnection();

        // Verify migration completed successfully
        $version = $this->service->fetchOne('SELECT version FROM schema_version LIMIT 1');
        expect($version['version'])->toBe(13);

        // No errors should occur and no tasks should be created
        $tasks = $this->service->fetchAll("SELECT * FROM tasks WHERE status = 'someday'");
        expect($tasks)->toBeEmpty();
    } finally {
        chdir($oldCwd);
    }
});
