<?php

declare(strict_types=1);

namespace App\Repositories;

class TaskRepository extends BaseRepository
{
    protected function getTable(): string
    {
        return 'tasks';
    }

    /**
     * Find tasks by status.
     */
    public function findByStatus(string $status): array
    {
        return $this->fetchAll('SELECT * FROM tasks WHERE status = ? ORDER BY created_at', [$status]);
    }

    /**
     * Find tasks by epic ID.
     */
    public function findByEpicId(int $epicId): array
    {
        return $this->fetchAll('SELECT * FROM tasks WHERE epic_id = ? ORDER BY created_at', [$epicId]);
    }

    /**
     * Find tasks that are blocked by a specific task.
     */
    public function findBlockedByTask(string $blockerShortId): array
    {
        return $this->fetchAll(
            'SELECT * FROM tasks WHERE blocked_by LIKE ?',
            ['%"'.$blockerShortId.'"%']
        );
    }

    /**
     * Get all open tasks (status = 'open').
     */
    public function getOpenTasks(): array
    {
        return $this->findByStatus('open');
    }

    /**
     * Get all tasks in progress (status = 'in_progress').
     */
    public function getInProgressTasks(): array
    {
        return $this->findByStatus('in_progress');
    }

    /**
     * Get all closed tasks (status = 'closed').
     */
    public function getClosedTasks(): array
    {
        return $this->findByStatus('closed');
    }

    /**
     * Get all backlog tasks (status = 'someday').
     */
    public function getBacklogTasks(): array
    {
        return $this->findByStatus('someday');
    }

    /**
     * Get tasks with a specific label.
     */
    public function findByLabel(string $label): array
    {
        return $this->fetchAll(
            'SELECT * FROM tasks WHERE labels LIKE ? ORDER BY priority, created_at',
            ['%"'.$label.'"%']
        );
    }

    /**
     * Get tasks by priority.
     */
    public function findByPriority(int $priority): array
    {
        return $this->fetchAll(
            'SELECT * FROM tasks WHERE priority = ? ORDER BY created_at',
            [$priority]
        );
    }

    /**
     * Get tasks by complexity.
     */
    public function findByComplexity(string $complexity): array
    {
        return $this->fetchAll(
            'SELECT * FROM tasks WHERE complexity = ? ORDER BY priority, created_at',
            [$complexity]
        );
    }

    /**
     * Get all consumed tasks (consumed = 1).
     */
    public function getConsumedTasks(): array
    {
        return $this->fetchAll(
            'SELECT * FROM tasks WHERE consumed = 1 ORDER BY consumed_at DESC'
        );
    }

    /**
     * Get tasks by type.
     */
    public function findByType(string $type): array
    {
        return $this->fetchAll(
            'SELECT * FROM tasks WHERE type = ? ORDER BY priority, created_at',
            [$type]
        );
    }

    /**
     * Update blocked_by field for a task.
     */
    public function updateBlockedBy(string $shortId, array $blockedBy): void
    {
        $this->updateByShortId($shortId, [
            'blocked_by' => json_encode($blockedBy),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Update labels field for a task.
     */
    public function updateLabels(string $shortId, array $labels): void
    {
        $this->updateByShortId($shortId, [
            'labels' => json_encode($labels),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Check if a short ID already exists (for ID generation).
     */
    public function shortIdExists(string $shortId): bool
    {
        return $this->existsByShortId($shortId);
    }

    /**
     * Get all task short IDs (for ID generation collision detection).
     */
    public function getAllShortIds(): array
    {
        $rows = $this->fetchAll('SELECT short_id FROM tasks');

        return array_column($rows, 'short_id');
    }

    /**
     * Find task with support for partial ID matching.
     */
    public function findWithPartialMatch(string $id, string $prefix = 'f'): ?array
    {
        // Support old 'fuel-' prefix for backward compatibility
        $rows = $this->fetchAll(
            'SELECT * FROM tasks WHERE short_id LIKE ? OR short_id LIKE ? OR short_id LIKE ?',
            [$id.'%', $prefix.'-'.$id.'%', 'fuel-'.$id.'%']
        );

        if (count($rows) === 1) {
            return $rows[0];
        }

        if (count($rows) > 1) {
            $ids = array_column($rows, 'short_id');
            throw new \RuntimeException(
                sprintf("Ambiguous task ID '%s'. Matches: %s", $id, implode(', ', $ids))
            );
        }

        return null;
    }

    /**
     * Get tasks ordered by short ID.
     */
    public function allOrderedByShortId(): array
    {
        return $this->fetchAll('SELECT * FROM tasks ORDER BY short_id');
    }

    /**
     * Get the epic integer ID for a task.
     */
    public function getEpicIntegerId(string $taskShortId): ?int
    {
        $result = $this->fetchOne(
            'SELECT epic_id FROM tasks WHERE short_id = ?',
            [$taskShortId]
        );

        return $result !== null && $result['epic_id'] !== null ? (int) $result['epic_id'] : null;
    }
}
