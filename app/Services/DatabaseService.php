<?php

declare(strict_types=1);

namespace App\Services;

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
        if ($this->connection === null) {
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
                    if ($column['name'] === 'id' && strtoupper($column['type']) === 'TEXT') {
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
                        size TEXT DEFAULT \'m\',
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
                    if ($column['name'] === 'id' && strtoupper($column['type']) === 'TEXT') {
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
            fn (int $version) => $version > $currentVersion,
            ARRAY_FILTER_USE_KEY
        );

        if (empty($pendingMigrations)) {
            return;
        }

        ksort($pendingMigrations);

        $ranMigrationV4 = false;

        $this->connection->beginTransaction();
        try {
            $maxVersion = $currentVersion;
            foreach ($pendingMigrations as $version => $migration) {
                $migration($this->connection);
                $maxVersion = $version;
                if ($version === 4) {
                    $ranMigrationV4 = true;
                }
            }
            $this->setSchemaVersion($maxVersion);
            $this->connection->commit();
        } catch (PDOException $e) {
            $this->connection->rollBack();
            throw new RuntimeException('Migration failed: '.$e->getMessage(), 0, $e);
        }

        // Auto-import tasks from JSONL after migration v4 creates the tasks table
        if ($ranMigrationV4) {
            $this->importTasksFromJsonl();
        }
    }

    /**
     * Initialize the database schema.
     * Creates the tables and indexes if they don't exist.
     */
    public function initialize(): void
    {
        $pdo = $this->getConnection();

        // Create runs table
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

        // Create indexes
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_task ON runs(task_id)');
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
                followup_task_ids TEXT,
                started_at TEXT,
                completed_at TEXT
            )
        ');

        // Create indexes for reviews table
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_short_id ON reviews(short_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_task_id ON reviews(task_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_status ON reviews(status)');

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
                size TEXT DEFAULT \'m\',
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
     * Import tasks from JSONL file into SQLite table.
     * Only imports if tasks.jsonl exists and tasks table is empty.
     *
     * @return int Number of tasks imported
     */
    public function importTasksFromJsonl(): int
    {
        // Derive tasks.jsonl path from database path
        $tasksJsonlPath = dirname($this->dbPath).'/tasks.jsonl';

        // Check if file exists
        if (! file_exists($tasksJsonlPath)) {
            return 0;
        }

        // Check if tasks table is empty
        $count = $this->fetchOne('SELECT COUNT(*) as count FROM tasks');
        if ($count !== null && (int) $count['count'] > 0) {
            return 0;
        }

        // Build epic short_id -> integer id lookup
        $epicLookup = [];
        $epics = $this->fetchAll('SELECT id, short_id FROM epics');
        foreach ($epics as $epic) {
            $epicLookup[$epic['short_id']] = (int) $epic['id'];
        }

        // Read and import tasks
        $imported = 0;
        $handle = fopen($tasksJsonlPath, 'r');
        if ($handle === false) {
            return 0;
        }

        $this->beginTransaction();
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $task = json_decode($line, true);
                if (! is_array($task) || ! isset($task['id'], $task['title'])) {
                    continue;
                }

                // Map epic_id string to integer if epic exists
                $epicId = null;
                if (isset($task['epic_id']) && $task['epic_id'] !== null) {
                    $epicId = $epicLookup[$task['epic_id']] ?? null;
                }

                // Encode arrays as JSON
                $labels = isset($task['labels']) && is_array($task['labels'])
                    ? json_encode($task['labels'])
                    : null;
                $blockedBy = isset($task['blocked_by']) && is_array($task['blocked_by'])
                    ? json_encode($task['blocked_by'])
                    : null;

                $this->query(
                    'INSERT INTO tasks (short_id, title, description, status, type, priority, size, complexity, labels, blocked_by, epic_id, commit_hash, reason, consumed, consumed_at, consumed_exit_code, consumed_output, consume_pid, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $task['id'],                                    // short_id
                        $task['title'],
                        $task['description'] ?? null,
                        $task['status'] ?? 'open',
                        $task['type'] ?? 'task',
                        $task['priority'] ?? 2,
                        $task['size'] ?? 'm',
                        $task['complexity'] ?? 'moderate',
                        $labels,
                        $blockedBy,
                        $epicId,
                        $task['commit_hash'] ?? null,
                        $task['reason'] ?? null,
                        $task['consumed'] ?? 0,
                        $task['consumed_at'] ?? null,
                        $task['consumed_exit_code'] ?? null,
                        $task['consumed_output'] ?? null,
                        $task['consume_pid'] ?? null,
                        $task['created_at'] ?? null,
                        $task['updated_at'] ?? null,
                    ]
                );
                $imported++;
            }
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        } finally {
            fclose($handle);
        }

        return $imported;
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
        } catch (PDOException $e) {
            throw new RuntimeException('Database query failed: '.$e->getMessage(), 0, $e);
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
     * Record that a review has started.
     *
     * @param  string  $taskShortId  The task short_id (e.g., 'f-xxxxxx')
     * @param  string  $agent  The agent performing the review
     * @return string The review short_id (e.g., 'r-xxxxxx')
     */
    public function recordReviewStarted(string $taskShortId, string $agent): string
    {
        $shortId = 'r-'.bin2hex(random_bytes(3));
        $startedAt = Carbon::now('UTC')->toIso8601String();

        // Resolve task short_id to integer id
        $taskIntId = $this->resolveTaskId($taskShortId);

        $this->query(
            'INSERT INTO reviews (short_id, task_id, agent, status, started_at) VALUES (?, ?, ?, ?, ?)',
            [$shortId, $taskIntId, $agent, 'pending', $startedAt]
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
     * Record that a review has completed.
     *
     * @param  string  $reviewShortId  The review short_id (e.g., 'r-xxxxxx')
     * @param  bool  $passed  Whether the review passed
     * @param  array  $issues  Array of issue types found (e.g., ['uncommitted_changes', 'tests_failing'])
     * @param  array  $followupTaskIds  Array of follow-up task short_ids created (stored as JSON array)
     */
    public function recordReviewCompleted(string $reviewShortId, bool $passed, array $issues, array $followupTaskIds): void
    {
        $status = $passed ? 'passed' : 'failed';
        $completedAt = Carbon::now('UTC')->toIso8601String();

        $this->query(
            'UPDATE reviews SET status = ?, issues = ?, followup_task_ids = ?, completed_at = ? WHERE short_id = ?',
            [$status, json_encode($issues), json_encode($followupTaskIds), $completedAt, $reviewShortId]
        );
    }

    /**
     * Get all reviews for a specific task.
     *
     * @param  string  $taskShortId  The task short_id (e.g., 'f-xxxxxx')
     * @return array Array of review records with issues and followup_task_ids decoded from JSON, task_id returned as short_id
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

        return array_map(fn (array $review): array => $this->decodeReviewJsonFields($review, $taskShortId), $reviews);
    }

    /**
     * Get all pending reviews.
     *
     * @return array Array of pending review records with issues and followup_task_ids decoded from JSON, task_id returned as short_id
     */
    public function getPendingReviews(): array
    {
        $reviews = $this->fetchAll(
            'SELECT * FROM reviews WHERE status = ? ORDER BY started_at ASC',
            ['pending']
        );

        return array_map(fn (array $review): array => $this->decodeReviewJsonFields($review), $reviews);
    }

    /**
     * Get all reviews, optionally filtered by status.
     *
     * @param  string|null  $status  Filter by status ('pending', 'passed', 'failed') or null for all
     * @param  int|null  $limit  Limit the number of results (null for no limit)
     * @return array Array of review records with issues and followup_task_ids decoded from JSON, task_id returned as short_id
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

        return array_map(fn (array $review): array => $this->decodeReviewJsonFields($review), $reviews);
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
        ];

        // Decode JSON fields
        $result['issues'] = $review['issues'] !== null ? json_decode($review['issues'], true) : [];
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to decode issues JSON: '.json_last_error_msg());
        }

        $result['followup_task_ids'] = $review['followup_task_ids'] !== null ? json_decode($review['followup_task_ids'], true) : [];
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to decode followup_task_ids JSON: '.json_last_error_msg());
        }

        return $result;
    }
}
