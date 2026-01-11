<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\DatabaseService;
use PDO;
use PDOStatement;

abstract class BaseRepository
{
    protected DatabaseService $db;

    public function __construct(DatabaseService $db)
    {
        $this->db = $db;
    }

    /**
     * Get the table name for this repository.
     */
    abstract protected function getTable(): string;

    /**
     * Get the primary key column name.
     */
    protected function getPrimaryKey(): string
    {
        return 'id';
    }

    /**
     * Get the short ID column name.
     */
    protected function getShortIdColumn(): string
    {
        return 'short_id';
    }

    /**
     * Execute a query and return the PDO statement.
     */
    protected function query(string $sql, array $params = []): PDOStatement
    {
        return $this->db->query($sql, $params);
    }

    /**
     * Execute a query and return all results.
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Execute a query and return a single row.
     */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Find a record by its integer ID.
     */
    public function find(int $id): ?array
    {
        return $this->fetchOne(
            sprintf('SELECT * FROM %s WHERE %s = ?', $this->getTable(), $this->getPrimaryKey()),
            [$id]
        );
    }

    /**
     * Find a record by its short ID.
     */
    public function findByShortId(string $shortId): ?array
    {
        return $this->fetchOne(
            sprintf('SELECT * FROM %s WHERE %s = ?', $this->getTable(), $this->getShortIdColumn()),
            [$shortId]
        );
    }

    /**
     * Find records by short ID with partial matching support.
     */
    public function findByPartialShortId(string $partialId, string $prefix): ?array
    {
        // Try exact match first
        $row = $this->findByShortId($partialId);
        if ($row !== null) {
            return $row;
        }

        // Try partial match with prefix
        $rows = $this->fetchAll(
            sprintf(
                'SELECT * FROM %s WHERE %s LIKE ? OR %s LIKE ?',
                $this->getTable(),
                $this->getShortIdColumn(),
                $this->getShortIdColumn()
            ),
            [$partialId.'%', $prefix.'-'.$partialId.'%']
        );

        if (count($rows) === 1) {
            return $rows[0];
        }

        if (count($rows) > 1) {
            throw new \RuntimeException(
                sprintf("Ambiguous ID '%s'. Matches: %s", $partialId, implode(', ', array_column($rows, $this->getShortIdColumn())))
            );
        }

        return null;
    }

    /**
     * Get all records from the table.
     */
    public function all(): array
    {
        return $this->fetchAll(sprintf('SELECT * FROM %s', $this->getTable()));
    }

    /**
     * Insert a new record into the table.
     *
     * @param  array<string, mixed>  $data
     * @return int The inserted record's integer ID
     */
    public function insert(array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->getTable(),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->query($sql, array_values($data));

        return (int) $this->db->getConnection()->lastInsertId();
    }

    /**
     * Update a record by its integer ID.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): void
    {
        if (empty($data)) {
            return;
        }

        $updates = [];
        foreach (array_keys($data) as $column) {
            $updates[] = sprintf('%s = ?', $column);
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = ?',
            $this->getTable(),
            implode(', ', $updates),
            $this->getPrimaryKey()
        );

        $params = array_values($data);
        $params[] = $id;

        $this->query($sql, $params);
    }

    /**
     * Update a record by its short ID.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateByShortId(string $shortId, array $data): void
    {
        if (empty($data)) {
            return;
        }

        $updates = [];
        foreach (array_keys($data) as $column) {
            $updates[] = sprintf('%s = ?', $column);
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = ?',
            $this->getTable(),
            implode(', ', $updates),
            $this->getShortIdColumn()
        );

        $params = array_values($data);
        $params[] = $shortId;

        $this->query($sql, $params);
    }

    /**
     * Delete a record by its integer ID.
     */
    public function delete(int $id): void
    {
        $this->query(
            sprintf('DELETE FROM %s WHERE %s = ?', $this->getTable(), $this->getPrimaryKey()),
            [$id]
        );
    }

    /**
     * Delete a record by its short ID.
     */
    public function deleteByShortId(string $shortId): void
    {
        $this->query(
            sprintf('DELETE FROM %s WHERE %s = ?', $this->getTable(), $this->getShortIdColumn()),
            [$shortId]
        );
    }

    /**
     * Check if a record exists by its integer ID.
     */
    public function exists(int $id): bool
    {
        $result = $this->fetchOne(
            sprintf('SELECT 1 FROM %s WHERE %s = ? LIMIT 1', $this->getTable(), $this->getPrimaryKey()),
            [$id]
        );

        return $result !== null;
    }

    /**
     * Check if a record exists by its short ID.
     */
    public function existsByShortId(string $shortId): bool
    {
        $result = $this->fetchOne(
            sprintf('SELECT 1 FROM %s WHERE %s = ? LIMIT 1', $this->getTable(), $this->getShortIdColumn()),
            [$shortId]
        );

        return $result !== null;
    }

    /**
     * Count all records in the table.
     */
    public function count(): int
    {
        $result = $this->fetchOne(sprintf('SELECT COUNT(*) as count FROM %s', $this->getTable()));

        return $result !== null ? (int) $result['count'] : 0;
    }

    /**
     * Count records matching a WHERE condition.
     */
    public function countWhere(string $column, mixed $value): int
    {
        $result = $this->fetchOne(
            sprintf('SELECT COUNT(*) as count FROM %s WHERE %s = ?', $this->getTable(), $column),
            [$value]
        );

        return $result !== null ? (int) $result['count'] : 0;
    }

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    /**
     * Commit a database transaction.
     */
    public function commit(): void
    {
        $this->db->commit();
    }

    /**
     * Rollback a database transaction.
     */
    public function rollback(): void
    {
        $this->db->rollback();
    }

    /**
     * Resolve a short ID to its integer ID.
     */
    public function resolveToIntegerId(string $shortId): ?int
    {
        $result = $this->fetchOne(
            sprintf('SELECT %s FROM %s WHERE %s = ?', $this->getPrimaryKey(), $this->getTable(), $this->getShortIdColumn()),
            [$shortId]
        );

        return $result !== null ? (int) $result[$this->getPrimaryKey()] : null;
    }

    /**
     * Resolve an integer ID to its short ID.
     */
    public function resolveToShortId(int $id): ?string
    {
        $result = $this->fetchOne(
            sprintf('SELECT %s FROM %s WHERE %s = ?', $this->getShortIdColumn(), $this->getTable(), $this->getPrimaryKey()),
            [$id]
        );

        return $result !== null ? $result[$this->getShortIdColumn()] : null;
    }
}
