<?php

declare(strict_types=1);

namespace App\Daemon;

use App\Contracts\ReviewServiceInterface;
use App\Enums\TaskStatus;
use App\Ipc\Events\ReviewCompletedEvent;
use App\Models\Review;
use App\Models\Task;
use App\Process\ReviewResult;
use App\Services\ConsumeIpcServer;
use App\Services\TaskService;
use App\Services\UpdateRealityService;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Manages review state machine for tasks under review.
 *
 * Responsibilities:
 * - Check for completed reviews via ReviewServiceInterface
 * - Handle review results (pass/fail)
 * - Reopen tasks that fail review
 * - Mark tasks complete that pass review
 * - Broadcast review completion events to IPC clients
 */
final readonly class ReviewManager
{
    public function __construct(
        private ReviewServiceInterface $reviewService,
        private TaskService $taskService,
        private TaskSpawner $taskSpawner,
        private ConsumeIpcServer $ipcServer,
        private LifecycleManager $lifecycleManager
    ) {}

    /**
     * Check for completed reviews and process their results.
     */
    public function checkCompletedReviews(): void
    {
        foreach ($this->reviewService->getPendingReviews() as $taskId) {
            if ($this->reviewService->isReviewComplete($taskId)) {
                // Get the review's original_status from ReviewService's tracking
                // Note: We need to get this before getReviewResult() which removes from pendingReviews
                $pendingReviewData = $this->reviewService->getPendingReviewData($taskId);
                $reviewId = $pendingReviewData['reviewId'] ?? null;
                $originalStatus = null;
                $wasAlreadyDone = false;

                if ($reviewId !== null) {
                    // Get the Review model to access original_status
                    $review = Review::where('short_id', $reviewId)->first();
                    $originalStatus = $review?->original_status;
                    $wasAlreadyDone = $originalStatus === TaskStatus::Done->value;
                }

                $result = $this->reviewService->getReviewResult($taskId);
                if (! $result instanceof ReviewResult) {
                    continue;
                }

                if ($result->passed) {
                    // Review passed
                    if ($wasAlreadyDone) {
                        // Task was already done - confirm done (maybe update reason)
                        $task = $this->taskService->find($taskId);
                        if ($task && $task->status !== TaskStatus::Done) {
                            // Task status changed (shouldn't happen, but handle gracefully)
                            Artisan::call('done', [
                                'ids' => [$taskId],
                                '--reason' => 'Review passed (was already done)',
                            ]);
                        }
                    } else {
                        // Task was in_progress - mark as done
                        Artisan::call('done', [
                            'ids' => [$taskId],
                            '--reason' => 'Review passed',
                        ]);
                    }

                    // Trigger reality update for solo tasks (no epic)
                    $task = $this->taskService->find($taskId);
                    if ($task instanceof Task && $task->epic_id === null) {
                        app(UpdateRealityService::class)->triggerUpdate($task);
                    }
                } else {
                    // Review found issues - reopen task if it was already done
                    $issuesSummary = $result->issues === [] ? 'issues found' : implode(', ', $result->issues);

                    // Store the review issues on the task for the next agent run
                    if ($result->issues !== []) {
                        $this->taskService->setLastReviewIssues($taskId, $result->issues);
                    }

                    if ($wasAlreadyDone) {
                        // Task was already done but review failed - reopen with issues
                        try {
                            $this->taskService->reopen($taskId);
                        } catch (RuntimeException) {
                            // Could not reopen - task may have been deleted or modified
                        }
                    } else {
                        // Task needs to be reopened so it can be retried
                        try {
                            $this->taskService->reopen($taskId);
                        } catch (RuntimeException) {
                            // Could not reopen
                        }
                    }
                }

                // Broadcast ReviewCompletedEvent to IPC clients
                $this->broadcastReviewCompleted($taskId, $result->passed, $result->issues, $wasAlreadyDone);

                $this->taskSpawner->invalidateTaskCache();
            }
        }
    }

    /**
     * Broadcast ReviewCompletedEvent to all connected clients.
     */
    private function broadcastReviewCompleted(string $taskId, bool $passed, array $issues, bool $wasAlreadyDone): void
    {
        $event = new ReviewCompletedEvent(
            taskId: $taskId,
            passed: $passed,
            issues: $issues,
            wasAlreadyDone: $wasAlreadyDone,
            instanceId: $this->lifecycleManager->getInstanceId()
        );
        $this->ipcServer->broadcast($event);
    }
}
