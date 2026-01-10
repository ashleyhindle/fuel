<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use RuntimeException;

class DatabaseService
{
    private ?PDO $connection = null;

    private string $dbPath;

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
        $this->connection = null; // Reset connection
    }

    /**
     * Get PDO connection, creating it if needed.
     */
    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            try {
                $this->connection = new PDO('sqlite:'.$this->dbPath);
                $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                throw new RuntimeException('Failed to connect to SQLite database: '.$e->getMessage(), 0, $e);
            }
        }

        return $this->connection;
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
        $startedAt = date('c');

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
        $completedAt = date('c');

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
     * Decode JSON fields in a review record.
     */
    private function decodeReviewJsonFields(array $review): array
    {
        $review['issues'] = $review['issues'] !== null ? json_decode($review['issues'], true) : [];
        $review['followup_task_ids'] = $review['followup_task_ids'] !== null ? json_decode($review['followup_task_ids'], true) : [];

        return $review;
    }
}
