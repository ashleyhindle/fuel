<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Review;
use Carbon\Carbon;
use PDO;
use PDOException;
use RuntimeException;

class DatabaseService
{
    private ?PDO $connection = null;

    private string $dbPath;

    private bool $migrated = false;

    public function __construct(?string $dbPath = null)
    {
        $this->dbPath = $dbPath ?? getcwd().'/.fuel/agent.db';
    }

    /**
     * Set the database path.
     */
    public function setDatabasePath(string $path): void
    {
        $this->dbPath = $path;
        $this->connection = null;
        $this->migrated = false;
    }

    /**
     * Get PDO connection, creating it if needed.
     * Automatically runs pending migrations on first connection.
     */
    public function getConnection(): PDO
    {
        if (! $this->connection instanceof \PDO) {
            // Check if .fuel directory exists before trying to connect
            $fuelDir = dirname($this->dbPath);
            if (! is_dir($fuelDir)) {
                fwrite(STDERR, "\n\033[41;37m ERROR \033[0m Fuel is not initialized in this directory.\n\n");
                fwrite(STDERR, "  Run \033[33mfuel init\033[0m to set up Fuel in this project.\n\n");
                exit(1);
            }

            try {
                $this->connection = new PDO('sqlite:'.$this->dbPath);
                $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                // Production SQLite settings for concurrent access
                // WAL mode: allows concurrent reads while writing
                $this->connection->exec('PRAGMA journal_mode = WAL');
                // Wait up to 10 seconds for locks instead of failing immediately
                $this->connection->exec('PRAGMA busy_timeout = 10000');
            } catch (PDOException $e) {
                throw new RuntimeException('Failed to connect to SQLite database: '.$e->getMessage(), 0, $e);
            }
        }

        if (! $this->migrated) {
            $this->runMigrations();
            $this->migrated = true;
        }

        return $this->connection;
    }

    /**
     * Get the current schema version from the database.
     */
    private function getCurrentSchemaVersion(): int
    {
        // Check if schema_version table exists
        $result = $this->connection->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='schema_version'"
        )->fetch();

        if (! $result) {
            return 0;
        }

        $row = $this->connection->query('SELECT version FROM schema_version LIMIT 1')->fetch();

        return $row ? (int) $row['version'] : 0;
    }

    /**
     * Update the schema version in the database.
     */
    private function setSchemaVersion(int $version): void
    {
        // Ensure schema_version table exists
        $this->connection->exec('
            CREATE TABLE IF NOT EXISTS schema_version (
                version INTEGER NOT NULL
            )
        ');

        // Delete any existing row and insert the new version
        $this->connection->exec('DELETE FROM schema_version');

        $stmt = $this->connection->prepare('INSERT INTO schema_version (version) VALUES (?)');
        $stmt->execute([$version]);
    }

    /**
     * Get all available migrations as [version => callable].
     */
    private function getMigrations(): array
    {
        return [
            1 => function (PDO $pdo): void {
                // v1: runs, agent_health, reviews tables with indexes
                $pdo->exec('
                    CREATE TABLE IF NOT EXISTS runs (
                        id TEXT PRIMARY KEY,
                        task_id TEXT NOT NULL,
                        agent TEXT NOT NULL,
                        status TEXT DEFAULT \'running\',
                        exit_code INTEGER,
                        started_at TEXT,
                        completed_at TEXT,
                        duration_seconds INTEGER,
                        session_id TEXT,
                        error_type TEXT
                    )
                ');

                $pdo->exec('
                    CREATE TABLE IF NOT EXISTS agent_health (
                        agent TEXT PRIMARY KEY,
                        last_success_at TEXT,
                        last_failure_at TEXT,
                        consecutive_failures INTEGER DEFAULT 0,
                        backoff_until TEXT,
                        total_runs INTEGER DEFAULT 0,
                        total_successes INTEGER DEFAULT 0
                    )
                ');

                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_task ON runs(task_id)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_agent ON runs(agent)');

                $pdo->exec('
                    CREATE TABLE IF NOT EXISTS reviews (
                        id TEXT PRIMARY KEY,
                        task_id TEXT NOT NULL,
                        agent TEXT NOT NULL,
                        status TEXT DEFAULT \'pending\',
                        issues TEXT,
                        followup_task_ids TEXT,
                        started_at TEXT,
                        completed_at TEXT,
                        FOREIGN KEY (task_id) REFERENCES tasks(id)
                    )
                ');

                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_task ON reviews(task_id)');
            },
            2 => function (PDO $pdo): void {
                // v2: epics table (original TEXT id schema - superseded by v3)
                $pdo->exec('
                    CREATE TABLE IF NOT EXISTS epics (
                        id TEXT PRIMARY KEY,
                        title TEXT NOT NULL,
                        description TEXT,
                        status TEXT DEFAULT \'planning\',
                        created_at TEXT,
                        reviewed_at TEXT,
                        approved_at TEXT,
                        approved_by TEXT
                    )
                ');

                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_epics_status ON epics(status)');
            },
            3 => function (PDO $pdo): void {
                // v3: Migrate epics to integer PK with short_id column
                // Check if epics table exists and has old schema (id as TEXT primary key)
                $tableInfo = $pdo->query('PRAGMA table_info(epics)')->fetchAll();
                $hasOldSchema = false;
                foreach ($tableInfo as $column) {
                    if ($column['name'] === 'id' && strtoupper((string) $column['type']) === 'TEXT') {
                        $hasOldSchema = true;
                        break;
                    }
                }

                if ($hasOldSchema) {
                    // Migrate existing data
                    $pdo->exec('
                        CREATE TABLE epics_new (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            short_id TEXT UNIQUE NOT NULL,
                            title TEXT NOT NULL,
                            description TEXT,
                            status TEXT DEFAULT \'planning\',
                            reviewed_at TEXT,
                            created_at TEXT,
                            updated_at TEXT
                        )
                    ');

                    // Copy data from old table (old id becomes short_id)
                    $pdo->exec('
                        INSERT INTO epics_new (short_id, title, description, status, reviewed_at, created_at, updated_at)
                        SELECT id, title, description, status, reviewed_at, created_at, created_at
                        FROM epics
                    ');

                    $pdo->exec('DROP TABLE epics');
                    $pdo->exec('ALTER TABLE epics_new RENAME TO epics');
                } else {
                    // Fresh install or already migrated - create with new schema
                    $pdo->exec('
                        CREATE TABLE IF NOT EXISTS epics (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            short_id TEXT UNIQUE NOT NULL,
                            title TEXT NOT NULL,
                            description TEXT,
                            status TEXT DEFAULT \'planning\',
                            reviewed_at TEXT,
                            created_at TEXT,
                            updated_at TEXT
                        )
                    ');
                }

                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_epics_short_id ON epics(short_id)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_epics_status ON epics(status)');
            },
            4 => function (PDO $pdo): void {
                // v4: tasks table (migrated from JSONL storage)
                $pdo->exec('
                    CREATE TABLE IF NOT EXISTS tasks (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        short_id TEXT UNIQUE NOT NULL,
                        title TEXT NOT NULL,
                        description TEXT,
                        status TEXT NOT NULL DEFAULT \'open\',
                        type TEXT DEFAULT \'task\',
                        priority INTEGER DEFAULT 2,
                        complexity TEXT DEFAULT \'moderate\',
                        labels TEXT,
                        blocked_by TEXT,
                        epic_id INTEGER REFERENCES epics(id) ON DELETE SET NULL,
                        commit_hash TEXT,
                        reason TEXT,
                        consumed INTEGER DEFAULT 0,
                        consumed_at TEXT,
                        consumed_exit_code INTEGER,
                        consumed_output TEXT,
                        consume_pid INTEGER,
                        created_at TEXT,
                        updated_at TEXT
                    )
                ');

                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_epic_id ON tasks(epic_id)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_short_id ON tasks(short_id)');
            },
            5 => function (PDO $pdo): void {
                // v5: Migrate reviews to integer PK with short_id, and integer FK to tasks
                // Check if reviews table exists and has old schema (id as TEXT primary key)
                $tableInfo = $pdo->query('PRAGMA table_info(reviews)')->fetchAll();
                $hasOldSchema = false;
                foreach ($tableInfo as $column) {
                    if ($column['name'] === 'id' && strtoupper((string) $column['type']) === 'TEXT') {
                        $hasOldSchema = true;
                        break;
                    }
                }

                if ($hasOldSchema) {
                    // Build task short_id -> integer id lookup
                    $taskLookup = [];
                    $taskRows = $pdo->query('SELECT id, short_id FROM tasks')->fetchAll();
                    foreach ($taskRows as $row) {
                        $taskLookup[$row['short_id']] = (int) $row['id'];
                    }

                    // Create new reviews table with proper schema
                    $pdo->exec('
                        CREATE TABLE reviews_new (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            short_id TEXT UNIQUE NOT NULL,
                            task_id INTEGER REFERENCES tasks(id) ON DELETE CASCADE,
                            agent TEXT,
                            status TEXT DEFAULT \'pending\',
                            issues TEXT,
                            followup_task_ids TEXT,
                            started_at TEXT,
                            completed_at TEXT
                        )
                    ');

                    // Copy data from old table (old id becomes short_id, resolve task_id to integer)
                    $oldReviews = $pdo->query('SELECT * FROM reviews')->fetchAll();
                    $stmt = $pdo->prepare('
                        INSERT INTO reviews_new (short_id, task_id, agent, status, issues, followup_task_ids, started_at, completed_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ');

                    foreach ($oldReviews as $review) {
                        $taskIntId = $taskLookup[$review['task_id']] ?? null;
                        $stmt->execute([
                            $review['id'],                   // old id becomes short_id
                            $taskIntId,                      // resolved integer task_id (null if task deleted)
                            $review['agent'],
                            $review['status'],
                            $review['issues'],
                            $review['followup_task_ids'],
                            $review['started_at'],
                            $review['completed_at'],
                        ]);
                    }

                    $pdo->exec('DROP TABLE reviews');
                    $pdo->exec('ALTER TABLE reviews_new RENAME TO reviews');
                } else {
                    // Fresh install or already migrated - create with new schema
                    $pdo->exec('
                        CREATE TABLE IF NOT EXISTS reviews (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            short_id TEXT UNIQUE NOT NULL,
                            task_id INTEGER REFERENCES tasks(id) ON DELETE CASCADE,
                            agent TEXT,
                            status TEXT DEFAULT \'pending\',
                            issues TEXT,
                            followup_task_ids TEXT,
                            started_at TEXT,
                            completed_at TEXT
                        )
                    ');
                }

                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_short_id ON reviews(short_id)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_task_id ON reviews(task_id)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_status ON reviews(status)');
            },
            6 => function (PDO $pdo): void {
                // v6: Add commit_hash column to tasks table if it doesn't exist
                $tableInfo = $pdo->query('PRAGMA table_info(tasks)')->fetchAll();
                $hasCommitHash = false;
                foreach ($tableInfo as $column) {
                    if ($column['name'] === 'commit_hash') {
                        $hasCommitHash = true;
                        break;
                    }
                }

                if (! $hasCommitHash) {
                    $pdo->exec('ALTER TABLE tasks ADD COLUMN commit_hash TEXT');
                }
            },
            7 => function (PDO $pdo): void {
                // v7: Add last_review_issues column to tasks table for retry feedback
                $tableInfo = $pdo->query('PRAGMA table_info(tasks)')->fetchAll();
                $hasColumn = false;
                foreach ($tableInfo as $column) {
                    if ($column['name'] === 'last_review_issues') {
                        $hasColumn = true;
                        break;
                    }
                }

                if (! $hasColumn) {
                    $pdo->exec('ALTER TABLE tasks ADD COLUMN last_review_issues TEXT');
                }
            },
            8 => function (PDO $pdo): void {
                // v8: Add approved_at, approved_by, and changes_requested_at columns to epics table for approval workflow
                $tableInfo = $pdo->query('PRAGMA table_info(epics)')->fetchAll();
                $hasApprovedAt = false;
                $hasApprovedBy = false;
                $hasChangesRequestedAt = false;
                foreach ($tableInfo as $column) {
                    if ($column['name'] === 'approved_at') {
                        $hasApprovedAt = true;
                    }

                    if ($column['name'] === 'approved_by') {
                        $hasApprovedBy = true;
                    }

                    if ($column['name'] === 'changes_requested_at') {
                        $hasChangesRequestedAt = true;
                    }
                }

                if (! $hasApprovedAt) {
                    $pdo->exec('ALTER TABLE epics ADD COLUMN approved_at TEXT');
                }

                if (! $hasApprovedBy) {
                    $pdo->exec('ALTER TABLE epics ADD COLUMN approved_by TEXT');
                }

                if (! $hasChangesRequestedAt) {
                    $pdo->exec('ALTER TABLE epics ADD COLUMN changes_requested_at TEXT');
                }
            },
            9 => function (PDO $pdo): void {
                // v9: Add run_id column to reviews table to link reviews to specific runs
                $tableInfo = $pdo->query('PRAGMA table_info(reviews)')->fetchAll();
                $hasRunId = false;
                foreach ($tableInfo as $column) {
                    if ($column['name'] === 'run_id') {
                        $hasRunId = true;
                        break;
                    }
                }

                if (! $hasRunId) {
                    $pdo->exec('ALTER TABLE reviews ADD COLUMN run_id INTEGER');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_run_id ON reviews(run_id)');
                }
            },
            10 => function (PDO $pdo): void {
                // v10: Migrate runs table to use integer FK for task_id, add model/output/cost_usd, rename completed_at to ended_at
                // Check if runs table exists and has old schema (task_id as TEXT)
                $tableInfo = $pdo->query('PRAGMA table_info(runs)')->fetchAll();
                $hasOldSchema = false;
                foreach ($tableInfo as $column) {
                    if ($column['name'] === 'task_id' && strtoupper((string) $column['type']) === 'TEXT') {
                        $hasOldSchema = true;
                        break;
                    }
                }

                if ($hasOldSchema) {
                    // Build task short_id -> integer id lookup
                    $taskLookup = [];
                    $taskRows = $pdo->query('SELECT id, short_id FROM tasks')->fetchAll();
                    foreach ($taskRows as $row) {
                        $taskLookup[$row['short_id']] = (int) $row['id'];
                    }

                    // Create new runs table with updated schema
                    $pdo->exec('
                        CREATE TABLE runs_new (
                            id TEXT PRIMARY KEY,
                            task_id INTEGER REFERENCES tasks(id) ON DELETE CASCADE,
                            agent TEXT NOT NULL,
                            status TEXT DEFAULT \'running\',
                            exit_code INTEGER,
                            started_at TEXT,
                            ended_at TEXT,
                            duration_seconds INTEGER,
                            session_id TEXT,
                            error_type TEXT,
                            model TEXT,
                            output TEXT,
                            cost_usd REAL
                        )
                    ');

                    // Copy data from old table (resolve task_id to integer, rename completed_at to ended_at)
                    $oldRuns = $pdo->query('SELECT * FROM runs')->fetchAll();
                    $stmt = $pdo->prepare('
                        INSERT INTO runs_new (id, task_id, agent, status, exit_code, started_at, ended_at, duration_seconds, session_id, error_type, model, output, cost_usd)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');

                    foreach ($oldRuns as $run) {
                        $taskIntId = $taskLookup[$run['task_id']] ?? null;
                        $stmt->execute([
                            $run['id'],
                            $taskIntId,                      // resolved integer task_id (null if task deleted)
                            $run['agent'],
                            $run['status'],
                            $run['exit_code'],
                            $run['started_at'],
                            $run['completed_at'],            // completed_at becomes ended_at
                            $run['duration_seconds'],
                            $run['session_id'],
                            $run['error_type'],
                            null,                            // model (new column)
                            null,                            // output (new column)
                            null,                            // cost_usd (new column)
                        ]);
                    }

                    $pdo->exec('DROP TABLE runs');
                    $pdo->exec('ALTER TABLE runs_new RENAME TO runs');
                } else {
                    // Fresh install or already migrated - create with new schema
                    $pdo->exec('
                        CREATE TABLE IF NOT EXISTS runs (
                            id TEXT PRIMARY KEY,
                            task_id INTEGER REFERENCES tasks(id) ON DELETE CASCADE,
                            agent TEXT NOT NULL,
                            status TEXT DEFAULT \'running\',
                            exit_code INTEGER,
                            started_at TEXT,
                            ended_at TEXT,
                            duration_seconds INTEGER,
                            session_id TEXT,
                            error_type TEXT,
                            model TEXT,
                            output TEXT,
                            cost_usd REAL
                        )
                    ');
                }

                // Recreate indexes for runs table
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_task_id ON runs(task_id)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_agent ON runs(agent)');
            },
            11 => function (PDO $pdo): void {
                // v11: Remove followup_task_ids column from reviews table
                // Reviews now use structured JSON output from agents instead of follow-up tasks
                $tableInfo = $pdo->query('PRAGMA table_info(reviews)')->fetchAll();
                $hasFollowupColumn = false;
                foreach ($tableInfo as $column) {
                    if ($column['name'] === 'followup_task_ids') {
                        $hasFollowupColumn = true;
                        break;
                    }
                }

                if ($hasFollowupColumn) {
                    // Recreate table without followup_task_ids column
                    $pdo->exec('
                        CREATE TABLE reviews_new (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            short_id TEXT UNIQUE NOT NULL,
                            task_id INTEGER REFERENCES tasks(id) ON DELETE CASCADE,
                            agent TEXT,
                            status TEXT DEFAULT \'pending\',
                            issues TEXT,
                            started_at TEXT,
                            completed_at TEXT,
                            run_id INTEGER
                        )
                    ');

                    // Copy data without followup_task_ids
                    $pdo->exec('
                        INSERT INTO reviews_new (id, short_id, task_id, agent, status, issues, started_at, completed_at, run_id)
                        SELECT id, short_id, task_id, agent, status, issues, started_at, completed_at, run_id
                        FROM reviews
                    ');

                    $pdo->exec('DROP TABLE reviews');
                    $pdo->exec('ALTER TABLE reviews_new RENAME TO reviews');

                    // Recreate indexes
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_task ON reviews(task_id)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_status ON reviews(status)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_run_id ON reviews(run_id)');
                }
            },
            12 => function (PDO $pdo): void {
                // v12: Migrate runs to integer PK with short_id column
                // Check if runs table exists and has old schema (id as TEXT primary key)
                $tableInfo = $pdo->query('PRAGMA table_info(runs)')->fetchAll();
                $hasOldSchema = false;
                foreach ($tableInfo as $column) {
                    if ($column['name'] === 'id' && strtoupper((string) $column['type']) === 'TEXT') {
                        $hasOldSchema = true;
                        break;
                    }
                }

                if ($hasOldSchema) {
                    // Create new runs table with proper schema
                    $pdo->exec('
                        CREATE TABLE runs_new (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            short_id TEXT UNIQUE NOT NULL,
                            task_id INTEGER REFERENCES tasks(id) ON DELETE CASCADE,
                            agent TEXT NOT NULL,
                            status TEXT DEFAULT \'running\',
                            exit_code INTEGER,
                            started_at TEXT,
                            ended_at TEXT,
                            duration_seconds INTEGER,
                            session_id TEXT,
                            error_type TEXT,
                            model TEXT,
                            output TEXT,
                            cost_usd REAL
                        )
                    ');

                    // Copy data from old table (old id becomes short_id)
                    $pdo->exec('
                        INSERT INTO runs_new (short_id, task_id, agent, status, exit_code, started_at, ended_at, duration_seconds, session_id, error_type, model, output, cost_usd)
                        SELECT id, task_id, agent, status, exit_code, started_at, ended_at, duration_seconds, session_id, error_type, model, output, cost_usd
                        FROM runs
                    ');

                    // Build run short_id -> integer id lookup for updating reviews
                    $runLookup = [];
                    $runRows = $pdo->query('SELECT id, short_id FROM runs_new')->fetchAll();
                    foreach ($runRows as $row) {
                        $runLookup[$row['short_id']] = (int) $row['id'];
                    }

                    // Update reviews.run_id to point to new integer IDs
                    // Before migration: run_id contains TEXT "run-xxxxxx" values (SQLite stores them as-is)
                    // After migration: run_id should contain INTEGER references to runs.id
                    $reviewsWithRunId = $pdo->query('SELECT id, run_id FROM reviews WHERE run_id IS NOT NULL')->fetchAll();
                    $stmt = $pdo->prepare('UPDATE reviews SET run_id = ? WHERE id = ?');
                    foreach ($reviewsWithRunId as $review) {
                        $oldRunId = $review['run_id'];
                        // Skip if run_id is already purely numeric (already migrated or empty)
                        if (is_numeric($oldRunId) && ! str_contains((string) $oldRunId, '-')) {
                            continue;
                        }

                        // Look up new integer id by the old string run_id (e.g., "run-xxxxxx")
                        $newRunId = $runLookup[$oldRunId] ?? null;
                        $stmt->execute([$newRunId, $review['id']]);
                    }

                    $pdo->exec('DROP TABLE runs');
                    $pdo->exec('ALTER TABLE runs_new RENAME TO runs');

                    // Recreate indexes
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_short_id ON runs(short_id)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_task_id ON runs(task_id)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_agent ON runs(agent)');
                } else {
                    // Fresh install or already migrated - create with new schema
                    $pdo->exec('
                        CREATE TABLE IF NOT EXISTS runs (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            short_id TEXT UNIQUE NOT NULL,
                            task_id INTEGER REFERENCES tasks(id) ON DELETE CASCADE,
                            agent TEXT NOT NULL,
                            status TEXT DEFAULT \'running\',
                            exit_code INTEGER,
                            started_at TEXT,
                            ended_at TEXT,
                            duration_seconds INTEGER,
                            session_id TEXT,
                            error_type TEXT,
                            model TEXT,
                            output TEXT,
                            cost_usd REAL
                        )
                    ');

                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_short_id ON runs(short_id)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_task_id ON runs(task_id)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_agent ON runs(agent)');
                }
            },
            13 => function (PDO $pdo): void {
                // v13: Migrate backlog.jsonl items to tasks with status=someday
                $backlogPath = getcwd().'/.fuel/backlog.jsonl';
                $lockPath = $backlogPath.'.lock';

                // Skip if backlog.jsonl doesn't exist (already migrated or never used)
                if (! file_exists($backlogPath)) {
                    return;
                }

                // Read and parse backlog items
                $content = file_get_contents($backlogPath);
                if ($content === false || trim($content) === '') {
                    // Empty backlog - just delete the file
                    @unlink($backlogPath);
                    @unlink($lockPath);

                    return;
                }

                $lines = explode("\n", trim($content));
                $imported = 0;

                foreach ($lines as $line) {
                    if (trim($line) === '') {
                        continue;
                    }

                    $item = json_decode($line, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        // Log error but continue with other items
                        error_log('Failed to parse backlog item: '.$line);

                        continue;
                    }

                    // Generate new f-xxxxxx ID (using same 6-char hash approach)
                    $hash = hash('sha256', uniqid('f-', true).microtime(true));
                    $shortId = 'f-'.substr($hash, 0, 6);

                    // Insert task with status=someday
                    $stmt = $pdo->prepare('
                        INSERT INTO tasks (short_id, title, description, status, type, priority, complexity, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');

                    $stmt->execute([
                        $shortId,
                        $item['title'] ?? 'Untitled',
                        $item['description'] ?? null,
                        'someday',                      // status=someday for backlog items
                        'task',                         // default type
                        2,                              // default priority
                        'moderate',                     // default complexity
                        $item['created_at'] ?? date('c'),
                        date('c'),
                    ]);

                    $imported++;
                }

                // Log migration summary
                error_log(sprintf('Migrated %d backlog items to tasks with status=someday', $imported));

                // Delete backlog files after successful import
                @unlink($backlogPath);
                @unlink($lockPath);
            },
            14 => function (PDO $pdo): void {
                // v14: Remove legacy 'size' column from tasks (replaced by 'complexity')
                // Check if column exists before attempting to drop
                $tableInfo = $pdo->query('PRAGMA table_info(tasks)')->fetchAll();
                $hasSize = false;
                foreach ($tableInfo as $column) {
                    if ($column['name'] === 'size') {
                        $hasSize = true;
                        break;
                    }
                }

                if ($hasSize) {
                    // SQLite 3.35+ supports ALTER TABLE DROP COLUMN
                    $pdo->exec('ALTER TABLE tasks DROP COLUMN size');
                }
            },
        ];
    }

    /**
     * Run any pending migrations.
     */
    private function runMigrations(): void
    {
        $currentVersion = $this->getCurrentSchemaVersion();
        $migrations = $this->getMigrations();

        $pendingMigrations = array_filter(
            $migrations,
            fn (int $version): bool => $version > $currentVersion,
            ARRAY_FILTER_USE_KEY
        );

        if ($pendingMigrations === []) {
            return;
        }

        ksort($pendingMigrations);

        $this->connection->beginTransaction();
        try {
            $maxVersion = $currentVersion;
            foreach ($pendingMigrations as $version => $migration) {
                $migration($this->connection);
                $maxVersion = $version;
            }

            $this->setSchemaVersion($maxVersion);
            $this->connection->commit();
        } catch (PDOException $pdoException) {
            $this->connection->rollBack();
            throw new RuntimeException('Migration failed: '.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * Initialize the database schema.
     * Creates the tables and indexes if they don't exist.
     */
    public function initialize(): void
    {
        $pdo = $this->getConnection();

        // Create runs table (new schema with integer PK and short_id)
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                short_id TEXT UNIQUE NOT NULL,
                task_id INTEGER REFERENCES tasks(id) ON DELETE CASCADE,
                agent TEXT NOT NULL,
                status TEXT DEFAULT \'running\',
                exit_code INTEGER,
                started_at TEXT,
                ended_at TEXT,
                duration_seconds INTEGER,
                session_id TEXT,
                error_type TEXT,
                model TEXT,
                output TEXT,
                cost_usd REAL
            )
        ');

        // Create agent_health table
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS agent_health (
                agent TEXT PRIMARY KEY,
                last_success_at TEXT,
                last_failure_at TEXT,
                consecutive_failures INTEGER DEFAULT 0,
                backoff_until TEXT,
                total_runs INTEGER DEFAULT 0,
                total_successes INTEGER DEFAULT 0
            )
        ');

        // Create indexes for runs table
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_short_id ON runs(short_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_task_id ON runs(task_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_agent ON runs(agent)');

        // Create reviews table (new schema with integer PK and short_id)
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS reviews (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                short_id TEXT UNIQUE NOT NULL,
                task_id INTEGER REFERENCES tasks(id) ON DELETE CASCADE,
                agent TEXT,
                status TEXT DEFAULT \'pending\',
                issues TEXT,
                started_at TEXT,
                completed_at TEXT,
                run_id INTEGER
            )
        ');

        // Create indexes for reviews table
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_short_id ON reviews(short_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_task_id ON reviews(task_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_status ON reviews(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_run_id ON reviews(run_id)');

        // Create epics table (new schema with integer PK and short_id)
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS epics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                short_id TEXT UNIQUE NOT NULL,
                title TEXT NOT NULL,
                description TEXT,
                status TEXT DEFAULT \'planning\',
                reviewed_at TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        // Create indexes for epics table
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_epics_short_id ON epics(short_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_epics_status ON epics(status)');

        // Create tasks table (new schema with integer PK and short_id)
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                short_id TEXT UNIQUE NOT NULL,
                title TEXT NOT NULL,
                description TEXT,
                status TEXT NOT NULL DEFAULT \'open\',
                type TEXT DEFAULT \'task\',
                priority INTEGER DEFAULT 2,
                complexity TEXT DEFAULT \'moderate\',
                labels TEXT,
                blocked_by TEXT,
                epic_id INTEGER REFERENCES epics(id) ON DELETE SET NULL,
                commit_hash TEXT,
                reason TEXT,
                consumed INTEGER DEFAULT 0,
                consumed_at TEXT,
                consumed_exit_code INTEGER,
                consumed_output TEXT,
                consume_pid INTEGER,
                last_review_issues TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');

        // Create indexes for tasks table
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_epic_id ON tasks(epic_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_short_id ON tasks(short_id)');
    }

    /**
     * Check if the database file exists.
     */
    public function exists(): bool
    {
        return file_exists($this->dbPath);
    }

    /**
     * Get the database file path.
     */
    public function getPath(): string
    {
        return $this->dbPath;
    }

    /**
     * Execute a query and return the PDO statement.
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);

            return $stmt;
        } catch (PDOException $pdoException) {
            throw new RuntimeException('Database query failed: '.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * Execute a query and return all results.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Execute a query and return a single row.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();

        return $result !== false ? $result : null;
    }

    /**
     * Begin a transaction.
     */
    public function beginTransaction(): void
    {
        $this->getConnection()->beginTransaction();
    }

    /**
     * Commit a transaction.
     */
    public function commit(): void
    {
        $this->getConnection()->commit();
    }

    /**
     * Rollback a transaction.
     */
    public function rollback(): void
    {
        $this->getConnection()->rollBack();
    }

    /**
     * Get the latest run ID for a task from the runs table.
     *
     * @param  string  $taskShortId  The task short_id (e.g., 'f-xxxxxx')
     * @return int|null The run integer ID, or null if no runs found
     */
    public function getLatestRunId(string $taskShortId): ?int
    {
        // Resolve task short_id to integer id
        $taskIntId = $this->resolveTaskId($taskShortId);
        if ($taskIntId === null) {
            return null;
        }

        // Get the latest run for this task, ordered by started_at descending
        $run = $this->fetchOne(
            'SELECT id FROM runs WHERE task_id = ? ORDER BY started_at DESC, id DESC LIMIT 1',
            [$taskIntId]
        );

        return $run !== null ? (int) $run['id'] : null;
    }

    /**
     * Record that a review has started.
     *
     * @param  string  $taskShortId  The task short_id (e.g., 'f-xxxxxx')
     * @param  string  $agent  The agent performing the review
     * @param  int|null  $runId  The run integer ID being reviewed
     * @return string The review short_id (e.g., 'r-xxxxxx')
     */
    public function recordReviewStarted(string $taskShortId, string $agent, ?int $runId = null): string
    {
        $shortId = 'r-'.bin2hex(random_bytes(3));
        $startedAt = Carbon::now('UTC')->toIso8601String();

        // Resolve task short_id to integer id
        $taskIntId = $this->resolveTaskId($taskShortId);

        $this->query(
            'INSERT INTO reviews (short_id, task_id, agent, status, started_at, run_id) VALUES (?, ?, ?, ?, ?, ?)',
            [$shortId, $taskIntId, $agent, 'pending', $startedAt, $runId]
        );

        return $shortId;
    }

    /**
     * Resolve a task short_id to its integer id.
     *
     * @param  string  $taskShortId  The task short_id (e.g., 'f-xxxxxx')
     * @return int|null The integer id, or null if task not found
     */
    private function resolveTaskId(string $taskShortId): ?int
    {
        $task = $this->fetchOne('SELECT id FROM tasks WHERE short_id = ?', [$taskShortId]);

        return $task !== null ? (int) $task['id'] : null;
    }

    /**
     * Resolve a task integer id to its short_id.
     *
     * @param  int|null  $taskIntId  The integer id
     * @return string|null The short_id, or null if task not found
     */
    private function resolveTaskShortId(?int $taskIntId): ?string
    {
        if ($taskIntId === null) {
            return null;
        }

        $task = $this->fetchOne('SELECT short_id FROM tasks WHERE id = ?', [$taskIntId]);

        return $task !== null ? $task['short_id'] : null;
    }

    /**
     * Resolve a run short_id to its integer id.
     *
     * @param  string  $runShortId  The run short_id (e.g., 'run-xxxxxx')
     * @return int|null The integer id, or null if run not found
     */
    private function resolveRunId(string $runShortId): ?int
    {
        $run = $this->fetchOne('SELECT id FROM runs WHERE short_id = ?', [$runShortId]);

        return $run !== null ? (int) $run['id'] : null;
    }

    /**
     * Get the integer ID for a run by its short_id.
     * Public method for external use.
     *
     * @param  string  $runShortId  The run short_id (e.g., 'run-xxxxxx')
     * @return int|null The integer id, or null if run not found
     */
    public function getRunIntegerId(string $runShortId): ?int
    {
        return $this->resolveRunId($runShortId);
    }

    /**
     * Get a single review by its short_id.
     *
     * @param  string  $reviewShortId  The review short_id (e.g., 'r-xxxxxx')
     * @return Review|null The review model or null if not found
     */
    public function getReview(string $reviewShortId): ?Review
    {
        // Support partial matching like task IDs
        $normalizedId = $reviewShortId;
        if (! str_starts_with($normalizedId, 'r-')) {
            $normalizedId = 'r-'.$normalizedId;
        }

        // Try exact match first
        $review = $this->fetchOne('SELECT * FROM reviews WHERE short_id = ?', [$normalizedId]);

        // If not found, try partial match
        if ($review === null) {
            $review = $this->fetchOne(
                'SELECT * FROM reviews WHERE short_id LIKE ? ORDER BY started_at DESC LIMIT 1',
                [$normalizedId.'%']
            );
        }

        if ($review === null) {
            return null;
        }

        return Review::fromArray($this->decodeReviewJsonFields($review));
    }

    /**
     * Record that a review has completed.
     *
     * @param  string  $reviewShortId  The review short_id (e.g., 'r-xxxxxx')
     * @param  bool  $passed  Whether the review passed
     * @param  array  $issues  Array of issue descriptions found
     */
    public function recordReviewCompleted(string $reviewShortId, bool $passed, array $issues): void
    {
        $status = $passed ? 'passed' : 'failed';
        $completedAt = Carbon::now('UTC')->toIso8601String();

        $this->query(
            'UPDATE reviews SET status = ?, issues = ?, completed_at = ? WHERE short_id = ?',
            [$status, json_encode($issues), $completedAt, $reviewShortId]
        );
    }

    /**
     * Get all reviews for a specific task.
     *
     * @param  string  $taskShortId  The task short_id (e.g., 'f-xxxxxx')
     * @return array<Review> Array of Review models
     */
    public function getReviewsForTask(string $taskShortId): array
    {
        // Resolve task short_id to integer id
        $taskIntId = $this->resolveTaskId($taskShortId);
        if ($taskIntId === null) {
            return [];
        }

        $reviews = $this->fetchAll(
            'SELECT * FROM reviews WHERE task_id = ? ORDER BY started_at DESC',
            [$taskIntId]
        );

        return array_map(
            fn (array $review): Review => Review::fromArray($this->decodeReviewJsonFields($review, $taskShortId)),
            $reviews
        );
    }

    /**
     * Get all pending reviews.
     *
     * @return array<Review> Array of Review models
     */
    public function getPendingReviews(): array
    {
        $reviews = $this->fetchAll(
            'SELECT * FROM reviews WHERE status = ? ORDER BY started_at ASC',
            ['pending']
        );

        return array_map(
            fn (array $review): Review => Review::fromArray($this->decodeReviewJsonFields($review)),
            $reviews
        );
    }

    /**
     * Get all reviews, optionally filtered by status.
     *
     * @param  string|null  $status  Filter by status ('pending', 'passed', 'failed') or null for all
     * @param  int|null  $limit  Limit the number of results (null for no limit)
     * @return array<Review> Array of Review models
     */
    public function getAllReviews(?string $status = null, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM reviews';
        $params = [];

        if ($status !== null) {
            $sql .= ' WHERE status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY started_at DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }

        $reviews = $this->fetchAll($sql, $params);

        return array_map(
            fn (array $review): Review => Review::fromArray($this->decodeReviewJsonFields($review)),
            $reviews
        );
    }

    /**
     * Decode JSON fields in a review record and map to public interface format.
     *
     * @param  array  $review  The raw review record from database
     * @param  string|null  $taskShortIdOverride  Optional override for task_id (used when already known)
     * @return array The review with: id as short_id, task_id as short_id, decoded JSON fields
     */
    private function decodeReviewJsonFields(array $review, ?string $taskShortIdOverride = null): array
    {
        // Map id to short_id for public interface
        $result = [
            'id' => $review['short_id'],
            'task_id' => $taskShortIdOverride ?? $this->resolveTaskShortId(
                $review['task_id'] !== null ? (int) $review['task_id'] : null
            ),
            'agent' => $review['agent'],
            'status' => $review['status'],
            'started_at' => $review['started_at'],
            'completed_at' => $review['completed_at'],
            'run_id' => $review['run_id'] !== null ? (int) $review['run_id'] : null,
        ];

        // Decode JSON fields
        $result['issues'] = [];
        if ($review['issues'] !== null && $review['issues'] !== '') {
            $decoded = json_decode((string) $review['issues'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $result['issues'] = $decoded;
            }
        }

        return $result;
    }
}
