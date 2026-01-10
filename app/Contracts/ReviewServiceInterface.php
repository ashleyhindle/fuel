<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Process\ReviewResult;

/**
 * Contract for review service.
 *
 * Defines the interface for triggering reviews of completed work,
 * building review prompts, and tracking review status.
 */
interface ReviewServiceInterface
{
    /**
     * Trigger a review for a completed task.
     *
     * Called by ConsumeCommand when an agent exits successfully.
     * Spawns a review agent to check the work.
     * Non-blocking - returns immediately, review runs in background.
     *
     * @param  string  $taskId  The ID of the task to review
     * @param  string  $agent  The agent name that completed the task
     */
    public function triggerReview(string $taskId, string $agent): void;

    /**
     * Build the prompt for the reviewing agent.
     *
     * Includes task title/description, git diff, git status.
     * Instructions to check: uncommitted changes, tests pass, task match.
     * Instructions to create follow-up tasks via 'fuel add' if issues found.
     *
     * @param  array<string, mixed>  $task  The task array to review
     * @param  string  $gitDiff  The git diff output
     * @param  string  $gitStatus  The git status output
     * @return string The review prompt
     */
    public function getReviewPrompt(array $task, string $gitDiff, string $gitStatus): string;

    /**
     * Get task IDs currently being reviewed.
     *
     * Used by consume to track review processes.
     *
     * @return array<string> Array of task IDs currently under review
     */
    public function getPendingReviews(): array;

    /**
     * Check if a review is complete for a given task.
     *
     * @param  string  $taskId  The task ID to check
     * @return bool True if review is complete, false otherwise
     */
    public function isReviewComplete(string $taskId): bool;

    /**
     * Get the review result for a completed review.
     *
     * @param  string  $taskId  The task ID to get the result for
     * @return ReviewResult|null The review result, or null if review not complete
     */
    public function getReviewResult(string $taskId): ?ReviewResult;

    /**
     * Recover stuck reviews by re-triggering reviews for tasks stuck in 'review' status.
     *
     * Tasks can get stuck in 'review' status if consume crashes after spawning a review,
     * or if 'fuel review' is run manually and exits. This method detects such tasks
     * and re-triggers their reviews.
     *
     * @return array<string> Array of task IDs that were recovered
     */
    public function recoverStuckReviews(): array;
}
