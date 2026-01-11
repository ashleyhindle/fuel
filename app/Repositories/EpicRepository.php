<?php

declare(strict_types=1);

namespace App\Repositories;

class EpicRepository extends BaseRepository
{
    protected function getTable(): string
    {
        return 'epics';
    }

    /**
     * Get all epics ordered by creation date.
     */
    public function allOrderedByCreatedAt(): array
    {
        return $this->fetchAll('SELECT * FROM epics ORDER BY created_at DESC');
    }

    /**
     * Get epics that haven't been reviewed yet.
     */
    public function getUnreviewedEpics(): array
    {
        return $this->fetchAll(
            'SELECT * FROM epics WHERE reviewed_at IS NULL ORDER BY created_at DESC'
        );
    }

    /**
     * Get epics that have been approved.
     */
    public function getApprovedEpics(): array
    {
        return $this->fetchAll(
            'SELECT * FROM epics WHERE approved_at IS NOT NULL ORDER BY approved_at DESC'
        );
    }

    /**
     * Get epics with changes requested.
     */
    public function getEpicsWithChangesRequested(): array
    {
        return $this->fetchAll(
            'SELECT * FROM epics WHERE changes_requested_at IS NOT NULL AND approved_at IS NULL ORDER BY changes_requested_at DESC'
        );
    }

    /**
     * Mark an epic as reviewed.
     */
    public function markAsReviewed(string $shortId): void
    {
        $now = now()->toIso8601String();
        $this->updateByShortId($shortId, [
            'reviewed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Mark an epic as approved.
     */
    public function markAsApproved(string $shortId, string $approvedBy = 'human'): void
    {
        $now = now()->toIso8601String();
        $this->updateByShortId($shortId, [
            'approved_at' => $now,
            'approved_by' => $approvedBy,
            'changes_requested_at' => null,
            'updated_at' => $now,
        ]);
    }

    /**
     * Mark an epic as having changes requested.
     */
    public function markAsChangesRequested(string $shortId): void
    {
        $now = now()->toIso8601String();
        $this->updateByShortId($shortId, [
            'changes_requested_at' => $now,
            'approved_at' => null,
            'approved_by' => null,
            'updated_at' => $now,
        ]);
    }

    /**
     * Find epics pending review (has tasks, all tasks closed, not yet reviewed).
     * This requires joining with tasks table.
     */
    public function findEpicsPendingReview(): array
    {
        $sql = '
            SELECT e.*
            FROM epics e
            WHERE e.reviewed_at IS NULL
              AND EXISTS (SELECT 1 FROM tasks t WHERE t.epic_id = e.id)
              AND NOT EXISTS (
                  SELECT 1 FROM tasks t
                  WHERE t.epic_id = e.id
                    AND t.status NOT IN (?, ?)
              )
            ORDER BY e.created_at DESC
        ';

        return $this->fetchAll($sql, ['closed', 'cancelled']);
    }

    /**
     * Check if an epic has any tasks.
     */
    public function hasTasks(int $epicId): bool
    {
        $result = $this->fetchOne(
            'SELECT COUNT(*) as count FROM tasks WHERE epic_id = ?',
            [$epicId]
        );

        return $result !== null && (int) $result['count'] > 0;
    }

    /**
     * Get task count for an epic.
     */
    public function getTaskCount(int $epicId): int
    {
        $result = $this->fetchOne(
            'SELECT COUNT(*) as count FROM tasks WHERE epic_id = ?',
            [$epicId]
        );

        return $result !== null ? (int) $result['count'] : 0;
    }

    /**
     * Find epic with support for partial ID matching.
     */
    public function findWithPartialMatch(string $id): ?array
    {
        if (str_starts_with($id, 'e-') && strlen($id) === 8) {
            return $this->findByShortId($id);
        }

        $epics = $this->fetchAll(
            'SELECT * FROM epics WHERE short_id LIKE ? OR short_id LIKE ?',
            [$id.'%', 'e-'.$id.'%']
        );

        if (count($epics) === 1) {
            return $epics[0];
        }

        if (count($epics) > 1) {
            $ids = array_column($epics, 'short_id');
            throw new \RuntimeException(
                sprintf("Ambiguous epic ID '%s'. Matches: %s", $id, implode(', ', $ids))
            );
        }

        return null;
    }

    /**
     * Get all epic short IDs (for resolution).
     */
    public function getAllShortIds(): array
    {
        $rows = $this->fetchAll('SELECT short_id FROM epics');
        return array_column($rows, 'short_id');
    }
}