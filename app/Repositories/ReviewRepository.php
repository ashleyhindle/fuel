<?php

declare(strict_types=1);

namespace App\Repositories;

class ReviewRepository extends BaseRepository
{
    protected function getTable(): string
    {
        return 'reviews';
    }

    /**
     * Get all reviews for a specific task.
     */
    public function findByTaskId(int $taskId): array
    {
        return $this->fetchAll(
            'SELECT * FROM reviews WHERE task_id = ? ORDER BY started_at DESC',
            [$taskId]
        );
    }

    /**
     * Get all reviews by status.
     */
    public function findByStatus(string $status): array
    {
        return $this->fetchAll(
            'SELECT * FROM reviews WHERE status = ? ORDER BY started_at ASC',
            [$status]
        );
    }

    /**
     * Get all pending reviews.
     */
    public function getPendingReviews(): array
    {
        return $this->findByStatus('pending');
    }

    /**
     * Get all passed reviews.
     */
    public function getPassedReviews(): array
    {
        return $this->findByStatus('passed');
    }

    /**
     * Get all failed reviews.
     */
    public function getFailedReviews(): array
    {
        return $this->findByStatus('failed');
    }

    /**
     * Get reviews by agent.
     */
    public function findByAgent(string $agent): array
    {
        return $this->fetchAll(
            'SELECT * FROM reviews WHERE agent = ? ORDER BY started_at DESC',
            [$agent]
        );
    }

    /**
     * Get reviews for a specific run.
     */
    public function findByRunId(int $runId): array
    {
        return $this->fetchAll(
            'SELECT * FROM reviews WHERE run_id = ? ORDER BY started_at DESC',
            [$runId]
        );
    }

    /**
     * Get the latest review for a task.
     */
    public function getLatestForTask(int $taskId): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM reviews WHERE task_id = ? ORDER BY started_at DESC LIMIT 1',
            [$taskId]
        );
    }

    /**
     * Mark a review as completed.
     */
    public function markAsCompleted(string $shortId, bool $passed, array $issues = []): void
    {
        $status = $passed ? 'passed' : 'failed';
        $completedAt = now()->toIso8601String();

        $this->updateByShortId($shortId, [
            'status' => $status,
            'issues' => json_encode($issues),
            'completed_at' => $completedAt,
        ]);
    }

    /**
     * Create a new review record.
     */
    public function createReview(string $shortId, int $taskId, string $agent, ?int $runId = null): int
    {
        return $this->insert([
            'short_id' => $shortId,
            'task_id' => $taskId,
            'agent' => $agent,
            'status' => 'pending',
            'started_at' => now()->toIso8601String(),
            'run_id' => $runId,
        ]);
    }

    /**
     * Find review with support for partial ID matching.
     */
    public function findWithPartialMatch(string $id): ?array
    {
        $normalizedId = $id;
        if (!str_starts_with($normalizedId, 'r-')) {
            $normalizedId = 'r-'.$id;
        }

        // Try exact match first
        $review = $this->findByShortId($normalizedId);
        if ($review !== null) {
            return $review;
        }

        // Try partial match
        return $this->fetchOne(
            'SELECT * FROM reviews WHERE short_id LIKE ? ORDER BY started_at DESC LIMIT 1',
            [$normalizedId.'%']
        );
    }

    /**
     * Get all reviews with limit option.
     */
    public function getAllWithLimit(?int $limit = null): array
    {
        $sql = 'SELECT * FROM reviews ORDER BY started_at DESC';
        $params = [];

        if ($limit !== null) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }

        return $this->fetchAll($sql, $params);
    }

    /**
     * Get reviews by status with limit.
     */
    public function findByStatusWithLimit(string $status, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM reviews WHERE status = ? ORDER BY started_at DESC';
        $params = [$status];

        if ($limit !== null) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }

        return $this->fetchAll($sql, $params);
    }

    /**
     * Count reviews by status.
     */
    public function countByStatus(): array
    {
        $rows = $this->fetchAll(
            'SELECT status, COUNT(*) as count FROM reviews GROUP BY status'
        );

        $result = [
            'pending' => 0,
            'passed' => 0,
            'failed' => 0,
        ];

        foreach ($rows as $row) {
            $status = $row['status'];
            if (isset($result[$status])) {
                $result[$status] = (int) $row['count'];
            }
        }

        return $result;
    }

    /**
     * Resolve a task short ID to its integer ID.
     */
    public function resolveTaskId(string $taskShortId): ?int
    {
        $result = $this->fetchOne('SELECT id FROM tasks WHERE short_id = ?', [$taskShortId]);

        return $result !== null ? (int) $result['id'] : null;
    }

    /**
     * Resolve a task integer ID to its short ID.
     */
    public function resolveTaskShortId(?int $taskIntId): ?string
    {
        if ($taskIntId === null) {
            return null;
        }

        $result = $this->fetchOne('SELECT short_id FROM tasks WHERE id = ?', [$taskIntId]);

        return $result !== null ? $result['short_id'] : null;
    }

    /**
     * Get the latest run ID for a task.
     */
    public function getLatestRunIdForTask(int $taskId): ?int
    {
        $result = $this->fetchOne(
            'SELECT id FROM runs WHERE task_id = ? ORDER BY started_at DESC, id DESC LIMIT 1',
            [$taskId]
        );

        return $result !== null ? (int) $result['id'] : null;
    }
}