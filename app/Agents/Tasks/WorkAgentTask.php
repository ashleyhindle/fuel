<?php

declare(strict_types=1);

namespace App\Agents\Tasks;

use App\Contracts\ReviewServiceInterface;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Process\CompletionResult;
use App\Process\ProcessType;
use App\Services\ConfigService;
use App\Services\TaskPromptBuilder;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

/**
 * Agent task for executing work on a fuel task.
 *
 * Encapsulates:
 * - Complexity-based agent routing
 * - TaskPromptBuilder for prompt construction
 * - Success: trigger review or auto-complete
 * - Failure: reopen task for retry
 */
class WorkAgentTask extends AbstractAgentTask
{
    /** @var callable|null Callback for epic completion sound notification */
    private $epicCompletionCallback = null;

    public function __construct(
        Task $task,
        TaskService $taskService,
        private readonly TaskPromptBuilder $promptBuilder,
        private readonly ?ReviewServiceInterface $reviewService = null,
        private readonly bool $reviewEnabled = false,
    ) {
        parent::__construct($task, $taskService);
    }

    /**
     * Set callback for epic completion sound notification.
     *
     * @param  callable  $callback  Function that takes taskId and checks/plays epic completion sound
     */
    public function setEpicCompletionCallback(callable $callback): void
    {
        $this->epicCompletionCallback = $callback;
    }

    /**
     * Get agent name using complexity-based routing.
     */
    public function getAgentName(ConfigService $configService): ?string
    {
        $complexity = $this->task->complexity ?? 'simple';

        return $configService->getAgentForComplexity($complexity);
    }

    /**
     * Build the work prompt using TaskPromptBuilder.
     */
    public function buildPrompt(string $cwd): string
    {
        return $this->promptBuilder->build($this->task, $cwd);
    }

    public function getProcessType(): ProcessType
    {
        return ProcessType::Task;
    }

    /**
     * Handle successful completion.
     *
     * Business logic:
     * - If review is enabled and ReviewService available, trigger review
     * - Otherwise, check task status and auto-complete if needed
     */
    public function onSuccess(CompletionResult $result): void
    {
        $taskId = $this->task->short_id;

        // Refresh task to get current status
        $task = $this->taskService->find($taskId);
        if (! $task instanceof Task) {
            return; // Task was deleted
        }

        $wasAlreadyDone = $task->status === TaskStatus::Done;

        if (! $this->reviewEnabled) {
            // Skip review and mark done directly
            if (! $wasAlreadyDone) {
                $this->taskService->done($taskId, 'Auto-completed by consume (review skipped)');
            }

            $this->notifyEpicCompletion($taskId);

            return;
        }

        if ($this->reviewService instanceof ReviewServiceInterface) {
            // Trigger review if ReviewService is available
            try {
                $reviewTriggered = $this->reviewService->triggerReview($taskId, $result->agentName);
                if (! $reviewTriggered) {
                    // No review agent configured - auto-complete with warning
                    $this->fallbackAutoComplete($taskId, $wasAlreadyDone);
                }
                // If review was triggered, completion handling happens in checkCompletedReviews()
            } catch (\RuntimeException) {
                // Review failed to trigger - fall back to auto-complete
                $this->fallbackAutoComplete($taskId, $wasAlreadyDone);
            }
        } else {
            // No ReviewService - fall back to auto-complete
            $this->fallbackAutoComplete($taskId, $wasAlreadyDone);
        }
    }

    /**
     * Handle failed completion.
     *
     * Business logic: Reopen task for retry.
     * Note: Retry counting and backoff are handled by ConsumeCommand/ProcessManager.
     */
    public function onFailure(CompletionResult $result): void
    {
        // Task will be reopened by ConsumeCommand's handleFailure method
        // which also handles retry counting and backoff logic.
        // This hook exists for future extension if needed.
    }

    /**
     * Fall back to auto-completing the task when review is not available.
     */
    private function fallbackAutoComplete(string $taskId, bool $wasAlreadyDone): void
    {
        if ($wasAlreadyDone) {
            // Task was already done - just notify
            $this->notifyEpicCompletion($taskId);

            return;
        }

        // Add 'auto-closed' label to indicate it wasn't self-reported
        $this->taskService->update($taskId, [
            'add_labels' => ['auto-closed'],
        ]);

        // Use DoneCommand logic so future done enhancements apply automatically
        Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Auto-completed by consume (agent exit 0)',
        ]);

        $this->notifyEpicCompletion($taskId);
    }

    /**
     * Notify epic completion if callback is set.
     */
    private function notifyEpicCompletion(string $taskId): void
    {
        if ($this->epicCompletionCallback !== null) {
            ($this->epicCompletionCallback)($taskId);
        }
    }
}
