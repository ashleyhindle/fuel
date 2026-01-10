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
}
