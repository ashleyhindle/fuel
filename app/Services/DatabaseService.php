<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Review;
use Illuminate\Support\Facades\DB;
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
        $this->configureDatabase();
    }

    /**
     * Set the database path and configure Laravel's DB connection.
     */
    public function setDatabasePath(string $path): void
    {
        $this->dbPath = $path;
        $this->connection = null;
        $this->configureDatabase();
    }

    /**
     * Configure Laravel's database connection to use this path.
     * Creates the database file if it doesn't exist.
     */
    private function configureDatabase(): void
    {
        // Create database file if it doesn't exist (Laravel's SQLite connector requires this)
        if (! file_exists($this->dbPath)) {
            $dir = dirname($this->dbPath);
            if (is_dir($dir)) {
                touch($this->dbPath);
            }
        }

        config(['database.connections.sqlite.database' => $this->dbPath]);
        DB::purge('sqlite'); // Clear cached connection so new path is used
    }

    /**
     * Get PDO connection, creating it if needed.
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

        return $this->connection;
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
     * Execute a write query (INSERT, UPDATE, DELETE).
     * For SELECT queries, use fetchAll() or fetchOne() instead.
     */
    public function query(string $sql, array $params = []): bool
    {
        // Ensure connection is established
        $this->getConnection();

        try {
            return DB::statement($sql, $params);
        } catch (\Exception $e) {
            throw new RuntimeException('Database query failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute a query and return all results.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        // Ensure connection is established
        $this->getConnection();

        try {
            $results = DB::select($sql, $params);

            // DB::select returns array of stdClass, convert to associative arrays
            return array_map(fn ($row) => (array) $row, $results);
        } catch (\Exception $e) {
            throw new RuntimeException('Database query failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute a query and return a single row.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $results = $this->fetchAll($sql, $params);

        return $results[0] ?? null;
    }

    /**
     * Begin a transaction.
     */
    public function beginTransaction(): void
    {
        DB::beginTransaction();
    }

    /**
     * Commit a transaction.
     */
    public function commit(): void
    {
        DB::commit();
    }

    /**
     * Rollback a transaction.
     */
    public function rollback(): void
    {
        DB::rollBack();
    }

    /**
     * Get the last inserted ID.
     */
    public function lastInsertId(): int
    {
        return (int) DB::getPdo()->lastInsertId();
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
     * Get a single review by its short_id.
     *
     * @param  string  $reviewShortId  The review short_id (e.g., 'r-xxxxxx')
     * @return Review|null The review model or null if not found
     */
    public function getReview(string $reviewShortId): ?Review
    {
        return Review::findByPartialId($reviewShortId);
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

        return Review::where('task_id', $taskIntId)
            ->orderBy('started_at', 'desc')
            ->get()
            ->all();
    }

    /**
     * Get all pending reviews.
     *
     * @return array<Review> Array of Review models
     */
    public function getPendingReviews(): array
    {
        return Review::where('status', 'pending')
            ->orderBy('started_at', 'asc')
            ->get()
            ->all();
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
        $query = Review::query();

        if ($status !== null) {
            $query->where('status', $status);
        }

        $query->orderBy('started_at', 'desc');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->all();
    }
}
