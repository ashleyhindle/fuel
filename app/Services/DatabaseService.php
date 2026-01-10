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

        $this->connection->beginTransaction();
        try {
            $maxVersion = $currentVersion;
            foreach ($pendingMigrations as $version => $migration) {
                $migration($this->connection);
                $maxVersion = $version;
            }
            $this->setSchemaVersion($maxVersion);
            $this->connection->commit();
        } catch (PDOException $e) {
            $this->connection->rollBack();
            throw new RuntimeException('Migration failed: '.$e->getMessage(), 0, $e);
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

        // Create reviews table
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

        // Create indexes for reviews table
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_task ON reviews(task_id)');

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
     * @return string The review ID
     */
    public function recordReviewStarted(string $taskId, string $agent): string
    {
        $reviewId = 'r-'.bin2hex(random_bytes(3));
        $startedAt = Carbon::now('UTC')->toIso8601String();

        $this->query(
            'INSERT INTO reviews (id, task_id, agent, status, started_at) VALUES (?, ?, ?, ?, ?)',
            [$reviewId, $taskId, $agent, 'pending', $startedAt]
        );

        return $reviewId;
    }

    /**
     * Record that a review has completed.
     *
     * @param  string  $reviewId  The review ID
     * @param  bool  $passed  Whether the review passed
     * @param  array  $issues  Array of issue types found (e.g., ['uncommitted_changes', 'tests_failing'])
     * @param  array  $followupTaskIds  Array of follow-up task IDs created
     */
    public function recordReviewCompleted(string $reviewId, bool $passed, array $issues, array $followupTaskIds): void
    {
        $status = $passed ? 'passed' : 'failed';
        $completedAt = Carbon::now('UTC')->toIso8601String();

        $this->query(
            'UPDATE reviews SET status = ?, issues = ?, followup_task_ids = ?, completed_at = ? WHERE id = ?',
            [$status, json_encode($issues), json_encode($followupTaskIds), $completedAt, $reviewId]
        );
    }

    /**
     * Get all reviews for a specific task.
     *
     * @return array Array of review records with issues and followup_task_ids decoded from JSON
     */
    public function getReviewsForTask(string $taskId): array
    {
        $reviews = $this->fetchAll(
            'SELECT * FROM reviews WHERE task_id = ? ORDER BY started_at DESC',
            [$taskId]
        );

        return array_map([$this, 'decodeReviewJsonFields'], $reviews);
    }

    /**
     * Get all pending reviews.
     *
     * @return array Array of pending review records with issues and followup_task_ids decoded from JSON
     */
    public function getPendingReviews(): array
    {
        $reviews = $this->fetchAll(
            'SELECT * FROM reviews WHERE status = ? ORDER BY started_at ASC',
            ['pending']
        );

        return array_map([$this, 'decodeReviewJsonFields'], $reviews);
    }

    /**
     * Get all reviews, optionally filtered by status.
     *
     * @param  string|null  $status  Filter by status ('pending', 'passed', 'failed') or null for all
     * @param  int|null  $limit  Limit the number of results (null for no limit)
     * @return array Array of review records with issues and followup_task_ids decoded from JSON
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

        return array_map([$this, 'decodeReviewJsonFields'], $reviews);
    }

    /**
     * Decode JSON fields in a review record.
     */
    private function decodeReviewJsonFields(array $review): array
    {
        $review['issues'] = $review['issues'] !== null ? json_decode($review['issues'], true) : [];
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to decode issues JSON: '.json_last_error_msg());
        }

        $review['followup_task_ids'] = $review['followup_task_ids'] !== null ? json_decode($review['followup_task_ids'], true) : [];
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to decode followup_task_ids JSON: '.json_last_error_msg());
        }

        return $review;
    }
}
