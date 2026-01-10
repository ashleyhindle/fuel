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

    expect($columns)->toHaveCount(13);
    expect(array_column($columns, 'name'))->toBe([
        'id',
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
    expect($indexNames)->toContain('idx_runs_task_id');
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
    expect($version['version'])->toBe(10);

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

    // Version should be updated to latest
    $version = $connection->query('SELECT version FROM schema_version LIMIT 1')->fetch();
    expect($version['version'])->toBe(10);

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
    expect($version['version'])->toBe(10);
});

it('imports runs from JSONL files', function () {
    // Initialize database and create a task
    $this->service->getConnection();
    $this->service->query(
        'INSERT INTO tasks (short_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        ['f-abc123', 'Test Task', 'open', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z']
    );

    // Create runs directory and JSONL file
    $runsDir = $this->tempDir.'/runs';
    mkdir($runsDir);
    $jsonlPath = $runsDir.'/f-abc123.jsonl';

    // Write test runs to JSONL
    $run1 = [
        'run_id' => 'run-111111',
        'agent' => 'claude',
        'model' => 'claude-sonnet-4-5',
        'started_at' => '2026-01-01T10:00:00Z',
        'ended_at' => '2026-01-01T10:05:00Z',
        'exit_code' => 0,
        'output' => 'Task completed successfully',
        'session_id' => 'sess-123',
        'cost_usd' => 0.05,
    ];
    $run2 = [
        'run_id' => 'run-222222',
        'agent' => 'claude',
        'model' => 'claude-sonnet-4-5',
        'started_at' => '2026-01-01T11:00:00Z',
        'ended_at' => '2026-01-01T11:03:00Z',
        'exit_code' => 1,
        'output' => 'Task failed',
        'session_id' => 'sess-456',
        'cost_usd' => 0.03,
    ];

    file_put_contents($jsonlPath, json_encode($run1)."\n".json_encode($run2)."\n");

    // Create lock file
    touch($jsonlPath.'.lock');

    // Import runs
    $imported = $this->service->importRunsFromJsonl();

    // Verify import count
    expect($imported)->toBe(2);

    // Verify runs were imported correctly
    $runs = $this->service->fetchAll('SELECT * FROM runs ORDER BY started_at ASC');
    expect($runs)->toHaveCount(2);

    expect($runs[0]['id'])->toBe('run-111111');
    expect($runs[0]['agent'])->toBe('claude');
    expect($runs[0]['model'])->toBe('claude-sonnet-4-5');
    expect($runs[0]['status'])->toBe('completed');
    expect($runs[0]['exit_code'])->toBe(0);
    expect($runs[0]['output'])->toBe('Task completed successfully');
    expect($runs[0]['duration_seconds'])->toBe(300); // 5 minutes

    expect($runs[1]['id'])->toBe('run-222222');
    expect($runs[1]['exit_code'])->toBe(1);
    expect($runs[1]['duration_seconds'])->toBe(180); // 3 minutes

    // Verify JSONL and lock files were deleted
    expect(file_exists($jsonlPath))->toBeFalse();
    expect(file_exists($jsonlPath.'.lock'))->toBeFalse();

    // Verify runs directory was deleted
    expect(is_dir($runsDir))->toBeFalse();
});

it('imports runs from multiple JSONL files', function () {
    // Initialize database and create tasks
    $this->service->getConnection();
    $this->service->query(
        'INSERT INTO tasks (short_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        ['f-task01', 'Task 1', 'open', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z']
    );
    $this->service->query(
        'INSERT INTO tasks (short_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        ['f-task02', 'Task 2', 'open', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z']
    );

    // Create runs directory and JSONL files
    $runsDir = $this->tempDir.'/runs';
    mkdir($runsDir);

    // Task 1 run
    $run1 = [
        'run_id' => 'run-111111',
        'agent' => 'claude',
        'started_at' => '2026-01-01T10:00:00Z',
        'ended_at' => '2026-01-01T10:05:00Z',
        'exit_code' => 0,
    ];
    file_put_contents($runsDir.'/f-task01.jsonl', json_encode($run1)."\n");

    // Task 2 run
    $run2 = [
        'run_id' => 'run-222222',
        'agent' => 'cursor',
        'started_at' => '2026-01-01T11:00:00Z',
        'ended_at' => '2026-01-01T11:03:00Z',
        'exit_code' => 0,
    ];
    file_put_contents($runsDir.'/f-task02.jsonl', json_encode($run2)."\n");

    // Import runs
    $imported = $this->service->importRunsFromJsonl();

    // Verify import count
    expect($imported)->toBe(2);

    // Verify runs were imported for correct tasks
    $task1Runs = $this->service->fetchAll('SELECT * FROM runs WHERE task_id = (SELECT id FROM tasks WHERE short_id = ?)', ['f-task01']);
    expect($task1Runs)->toHaveCount(1);
    expect($task1Runs[0]['id'])->toBe('run-111111');
    expect($task1Runs[0]['agent'])->toBe('claude');

    $task2Runs = $this->service->fetchAll('SELECT * FROM runs WHERE task_id = (SELECT id FROM tasks WHERE short_id = ?)', ['f-task02']);
    expect($task2Runs)->toHaveCount(1);
    expect($task2Runs[0]['id'])->toBe('run-222222');
    expect($task2Runs[0]['agent'])->toBe('cursor');

    // Verify all files were deleted
    expect(is_dir($runsDir))->toBeFalse();
});

it('skips runs for non-existent tasks', function () {
    // Initialize database (no tasks created)
    $this->service->getConnection();

    // Create runs directory and JSONL file for non-existent task
    $runsDir = $this->tempDir.'/runs';
    mkdir($runsDir);
    $jsonlPath = $runsDir.'/f-nonexist.jsonl';

    $run = [
        'run_id' => 'run-111111',
        'agent' => 'claude',
        'started_at' => '2026-01-01T10:00:00Z',
        'ended_at' => '2026-01-01T10:05:00Z',
        'exit_code' => 0,
    ];
    file_put_contents($jsonlPath, json_encode($run)."\n");

    // Import runs
    $imported = $this->service->importRunsFromJsonl();

    // Verify no runs were imported
    expect($imported)->toBe(0);

    // Verify runs table is empty
    $runs = $this->service->fetchAll('SELECT * FROM runs');
    expect($runs)->toHaveCount(0);

    // Verify JSONL file was NOT deleted (task doesn't exist, so file was skipped)
    expect(file_exists($jsonlPath))->toBeTrue();

    // Clean up manually
    unlink($jsonlPath);
    rmdir($runsDir);
});

it('skips duplicate runs on import', function () {
    // Initialize database and create a task
    $this->service->getConnection();
    $this->service->query(
        'INSERT INTO tasks (short_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        ['f-abc123', 'Test Task', 'open', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z']
    );

    // Get task integer ID
    $task = $this->service->fetchOne('SELECT id FROM tasks WHERE short_id = ?', ['f-abc123']);
    $taskId = $task['id'];

    // Manually insert a run
    $this->service->query(
        'INSERT INTO runs (id, task_id, agent, status, started_at) VALUES (?, ?, ?, ?, ?)',
        ['run-111111', $taskId, 'claude', 'completed', '2026-01-01T10:00:00Z']
    );

    // Create runs directory and JSONL file with duplicate run_id
    $runsDir = $this->tempDir.'/runs';
    mkdir($runsDir);
    $jsonlPath = $runsDir.'/f-abc123.jsonl';

    $run = [
        'run_id' => 'run-111111', // Duplicate
        'agent' => 'claude',
        'started_at' => '2026-01-01T10:00:00Z',
        'ended_at' => '2026-01-01T10:05:00Z',
        'exit_code' => 0,
    ];
    file_put_contents($jsonlPath, json_encode($run)."\n");

    // Import runs
    $imported = $this->service->importRunsFromJsonl();

    // Verify no runs were imported (duplicate skipped)
    expect($imported)->toBe(0);

    // Verify only one run exists
    $runs = $this->service->fetchAll('SELECT * FROM runs');
    expect($runs)->toHaveCount(1);
});

it('returns zero when runs directory does not exist', function () {
    $this->service->getConnection();

    $imported = $this->service->importRunsFromJsonl();

    expect($imported)->toBe(0);
});

it('returns zero when runs directory is empty', function () {
    $this->service->getConnection();

    // Create empty runs directory
    $runsDir = $this->tempDir.'/runs';
    mkdir($runsDir);

    $imported = $this->service->importRunsFromJsonl();

    expect($imported)->toBe(0);

    // Clean up
    rmdir($runsDir);
});

it('auto-imports runs after migration v10', function () {
    // Create a task in tasks.jsonl to be imported by v4 migration
    $tasksJsonlPath = $this->tempDir.'/tasks.jsonl';
    $task = [
        'id' => 'f-abc123',
        'title' => 'Test Task',
        'status' => 'open',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ];
    file_put_contents($tasksJsonlPath, json_encode($task)."\n");

    // Create runs directory and JSONL file
    $runsDir = $this->tempDir.'/runs';
    mkdir($runsDir);
    $jsonlPath = $runsDir.'/f-abc123.jsonl';

    $run = [
        'run_id' => 'run-111111',
        'agent' => 'claude',
        'started_at' => '2026-01-01T10:00:00Z',
        'ended_at' => '2026-01-01T10:05:00Z',
        'exit_code' => 0,
    ];
    file_put_contents($jsonlPath, json_encode($run)."\n");

    // Trigger migrations (v4 imports tasks, v10 imports runs)
    $this->service->getConnection();

    // Verify run was auto-imported
    $runs = $this->service->fetchAll('SELECT * FROM runs');
    expect($runs)->toHaveCount(1);
    expect($runs[0]['id'])->toBe('run-111111');

    // Verify JSONL file was deleted
    expect(file_exists($jsonlPath))->toBeFalse();

    // Verify runs directory was deleted
    expect(is_dir($runsDir))->toBeFalse();
});
