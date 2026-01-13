<?php

declare(strict_types=1);

namespace App\Agents\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Process\CompletionResult;
use App\Process\ProcessType;
use App\Prompts\ReviewPrompt;
use App\Services\ConfigService;
use App\Services\TaskService;

/**
 * Agent task for reviewing completed work.
 *
 * Encapsulates:
 * - 'review' agent from config
 * - ReviewPrompt for prompt construction
 * - Success/failure: handled by ReviewService after result parsing
 */
class ReviewAgentTask extends AbstractAgentTask
{
    public function __construct(
        Task $task,
        TaskService $taskService,
        private readonly ReviewPrompt $reviewPrompt,
        private readonly string $gitDiff,
        private readonly string $gitStatus,
    ) {
        parent::__construct($task, $taskService);
    }

    /**
     * Get the review task ID (prefixed with 'review-').
     *
     * Reviews use a prefixed task ID to distinguish them from work processes.
     */
    public function getTaskId(): string
    {
        return 'review-'.$this->task->short_id;
    }

    /**
     * Get the original task ID without the review prefix.
     */
    public function getOriginalTaskId(): string
    {
        return $this->task->short_id;
    }

    /**
     * Get agent name using 'review' agent from config.
     *
     * Returns null if no review agent is configured.
     */
    public function getAgentName(ConfigService $configService): ?string
    {
        return $configService->getReviewAgent();
    }

    /**
     * Build the review prompt using ReviewPrompt.
     */
    public function buildPrompt(string $cwd): string
    {
        return $this->reviewPrompt->generate($this->task, $this->gitDiff, $this->gitStatus);
    }

    public function getProcessType(): ProcessType
    {
        return ProcessType::Review;
    }

    /**
     * Handle successful completion.
     *
     * Note: Result parsing and task status updates are handled by ReviewService
     * in checkCompletedReviews(). The hooks here are for any immediate actions.
     */
    public function onSuccess(CompletionResult $result): void
    {
        // Result parsing happens in ReviewService::getReviewResult()
        // which is called from ConsumeCommand::checkCompletedReviews()
    }

    /**
     * Handle failed completion.
     *
     * Review crashed without producing a result - reopen the task.
     */
    public function onFailure(CompletionResult $result): void
    {
        // If the review agent crashed, reopen the task so it can be retried
        $originalTaskId = $this->task->short_id;
        $task = $this->taskService->find($originalTaskId);

        if ($task instanceof Task && $task->status === TaskStatus::Review) {
            try {
                $this->taskService->reopen($originalTaskId);
            } catch (\RuntimeException) {
                // Task may have been modified, ignore
            }
        }
    }
}
