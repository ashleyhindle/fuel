<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Review;
use App\Services\DatabaseService;

/**
 * ReviewRepository - Eloquent-based repository for Review operations.
 * Wraps Review model queries with a consistent interface for tests.
 */
class ReviewRepository
{
    public function __construct(
        private readonly DatabaseService $db
    ) {}

    /**
     * Find a review by its short_id.
     */
    public function findByShortId(string $shortId): ?array
    {
        $review = Review::where('short_id', $shortId)->first();

        return $review ? $this->formatReviewArray($review) : null;
    }

    /**
     * Format a Review model as an array matching the expected test format.
     * Converts issues array back to JSON string for backward compatibility.
     */
    private function formatReviewArray(Review $review): array
    {
        $array = $review->toArray();

        // Convert issues array back to JSON string for backward compatibility
        if (isset($array['issues']) && is_array($array['issues'])) {
            $array['issues'] = json_encode($array['issues']);
        } elseif ($array['issues'] === null) {
            // Keep null as-is for pending reviews
            $array['issues'] = null;
        }

        return $array;
    }

    /**
     * Get all reviews for a specific task.
     */
    public function findByTaskId(int $taskId): array
    {
        return Review::where('task_id', $taskId)
            ->orderBy('started_at', 'desc')
            ->get()
            ->map(fn (Review $review): array => $this->formatReviewArray($review))
            ->all();
    }

    /**
     * Get all reviews by status.
     */
    public function findByStatus(string $status): array
    {
        return Review::where('status', $status)
            ->orderBy('started_at', 'asc')
            ->get()
            ->map(fn (Review $review): array => $this->formatReviewArray($review))
            ->all();
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
        return Review::where('agent', $agent)
            ->orderBy('started_at', 'desc')
            ->get()
            ->map(fn (Review $review): array => $this->formatReviewArray($review))
            ->all();
    }

    /**
     * Get reviews for a specific run.
     */
    public function findByRunId(int $runId): array
    {
        return Review::where('run_id', $runId)
            ->orderBy('started_at', 'desc')
            ->get()
            ->map(fn (Review $review): array => $this->formatReviewArray($review))
            ->all();
    }

    /**
     * Get the latest review for a task.
     */
    public function getLatestForTask(int $taskId): ?array
    {
        $review = Review::where('task_id', $taskId)
            ->orderBy('started_at', 'desc')
            ->first();

        return $review ? $this->formatReviewArray($review) : null;
    }

    /**
     * Mark a review as completed.
     */
    public function markAsCompleted(string $shortId, bool $passed, array $issues = []): void
    {
        $status = $passed ? 'passed' : 'failed';
        $completedAt = now()->toIso8601String();

        Review::where('short_id', $shortId)->update([
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
        $review = Review::create([
            'short_id' => $shortId,
            'task_id' => $taskId,
            'agent' => $agent,
            'status' => 'pending',
            'started_at' => now()->toIso8601String(),
            'run_id' => $runId,
        ]);

        return $review->id;
    }

    /**
     * Find review with support for partial ID matching.
     */
    public function findWithPartialMatch(string $id): ?array
    {
        $review = Review::findByPartialId($id);

        return $review instanceof Review ? $this->formatReviewArray($review) : null;
    }

    /**
     * Get all reviews with limit option.
     */
    public function getAllWithLimit(?int $limit = null): array
    {
        $query = Review::query()->orderBy('started_at', 'desc');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()
            ->map(fn (Review $review): array => $this->formatReviewArray($review))
            ->all();
    }

    /**
     * Get reviews by status with limit.
     */
    public function findByStatusWithLimit(string $status, ?int $limit = null): array
    {
        $query = Review::where('status', $status)->orderBy('started_at', 'desc');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()
            ->map(fn (Review $review): array => $this->formatReviewArray($review))
            ->all();
    }

    /**
     * Count reviews by status.
     */
    public function countByStatus(): array
    {
        $rows = Review::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get();

        $result = [
            'pending' => 0,
            'passed' => 0,
            'failed' => 0,
        ];

        foreach ($rows as $row) {
            $status = $row->status;
            if (isset($result[$status])) {
                $result[$status] = (int) $row->count;
            }
        }

        return $result;
    }

    /**
     * Resolve a task short ID to its integer ID.
     */
    public function resolveTaskId(string $taskShortId): ?int
    {
        $result = $this->db->fetchOne('SELECT id FROM tasks WHERE short_id = ?', [$taskShortId]);

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

        $result = $this->db->fetchOne('SELECT short_id FROM tasks WHERE id = ?', [$taskIntId]);

        return $result !== null ? $result['short_id'] : null;
    }

    /**
     * Get the latest run ID for a task.
     */
    public function getLatestRunIdForTask(int $taskId): ?int
    {
        $result = $this->db->fetchOne(
            'SELECT id FROM runs WHERE task_id = ? ORDER BY started_at DESC, id DESC LIMIT 1',
            [$taskId]
        );

        return $result !== null ? (int) $result['id'] : null;
    }
}
