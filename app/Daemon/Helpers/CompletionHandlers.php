<?php

declare(strict_types=1);

namespace App\Daemon\Helpers;

use App\Contracts\AgentHealthTrackerInterface;
use App\Contracts\ReviewServiceInterface;
use App\Enums\FailureType;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Process\CompletionResult;
use App\Services\ConfigService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

/**
 * Handles completion type-specific logic for CompletionHandler.
 * Extracted to reduce CompletionHandler size.
 */
final class CompletionHandlers
{
    public function __construct(
        private readonly TaskService $taskService,
        private readonly ConfigService $configService,
        private readonly ?AgentHealthTrackerInterface $healthTracker,
        private readonly ?ReviewServiceInterface $reviewService,
        private array $taskRetryAttempts = [],
        private array $preReviewTaskStatus = [],
        private bool $taskReviewEnabled = false,
    ) {}

    public function setTaskReviewEnabled(bool $enabled): void
    {
        $this->taskReviewEnabled = $enabled;
    }

    public function handleSuccess(CompletionResult $completion): void
    {
        $taskId = $completion->taskId;

        if ($this->healthTracker instanceof AgentHealthTrackerInterface) {
            $this->healthTracker->recordSuccess($completion->agentName);
        }

        unset($this->taskRetryAttempts[$taskId]);

        $task = $this->taskService->find($taskId);
        if (! $task instanceof Task) {
            return;
        }

        $originalStatus = $task->status;
        $wasAlreadyDone = $originalStatus === TaskStatus::Done;

        if ($this->taskReviewEnabled && $this->reviewService) {
            try {
                if ($wasAlreadyDone) {
                    $this->preReviewTaskStatus[$taskId] = $originalStatus;
                }

                $reviewTriggered = $this->reviewService->triggerReview($taskId, $completion->agentName);
                if (! $reviewTriggered) {
                    $this->fallbackAutoComplete($taskId);
                }
            } catch (\RuntimeException) {
                $this->fallbackAutoComplete($taskId);
            }
        } else {
            $this->fallbackAutoComplete($taskId);
        }
    }

    public function handleFailure(CompletionResult $completion): void
    {
        $taskId = $completion->taskId;
        $agentName = $completion->agentName;

        $failureType = $completion->toFailureType();
        if ($this->healthTracker && $failureType instanceof FailureType) {
            $this->healthTracker->recordFailure($agentName, $failureType);
        }

        $retryAttempts = $this->taskRetryAttempts[$taskId] ?? 0;
        $maxAttempts = $this->configService->getAgentMaxAttempts($agentName);

        if ($completion->isRetryable() && $retryAttempts < $maxAttempts - 1) {
            $this->taskRetryAttempts[$taskId] = $retryAttempts + 1;
            $this->taskService->reopen($taskId);
        }
    }

    public function handleNetworkError(CompletionResult $completion): void
    {
        $taskId = $completion->taskId;
        $agentName = $completion->agentName;

        $failureType = $completion->toFailureType();
        if ($this->healthTracker && $failureType instanceof FailureType) {
            $this->healthTracker->recordFailure($agentName, $failureType);
        }

        $retryAttempts = $this->taskRetryAttempts[$taskId] ?? 0;
        $maxAttempts = $this->configService->getAgentMaxAttempts($agentName);

        if ($retryAttempts < $maxAttempts - 1) {
            $this->taskRetryAttempts[$taskId] = $retryAttempts + 1;
            $this->taskService->reopen($taskId);
        }
    }

    public function handlePermissionBlocked(CompletionResult $completion): void
    {
        $taskId = $completion->taskId;
        $agentName = $completion->agentName;

        $failureType = $completion->toFailureType();
        if ($this->healthTracker && $failureType instanceof FailureType) {
            $this->healthTracker->recordFailure($agentName, $failureType);
        }

        unset($this->taskRetryAttempts[$taskId]);

        $humanTask = $this->taskService->create([
            'title' => 'Configure agent permissions for '.$agentName,
            'description' => "Agent {$agentName} was blocked from running commands while working on {$taskId}.\n\n".
                "To fix, either:\n".
                "1. Run the agent interactively and select 'Always allow' for tool permissions\n".
                "2. Or add autonomous flags to .fuel/config.yaml agent definition:\n".
                "   - Claude: args: [\"--dangerously-skip-permissions\"]\n".
                "   - cursor-agent: args: [\"--force\"]\n".
                "   - opencode: env: {OPENCODE_PERMISSION: '{\"permission\":\"allow\"}'}\n\n".
                "See README.md 'Agent Permissions' section for details.",
            'labels' => ['needs-human'],
            'priority' => 1,
        ]);

        $this->taskService->addDependency($taskId, $humanTask->short_id);
        $this->taskService->reopen($taskId);
    }

    private function fallbackAutoComplete(string $taskId): void
    {
        $this->taskService->update($taskId, ['add_labels' => ['auto-closed']]);
        Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Auto-completed by consume (agent exit 0)',
        ]);
    }

    public function getRetryAttempts(): array
    {
        return $this->taskRetryAttempts;
    }

    public function clearRetryAttempts(string $taskId): void
    {
        unset($this->taskRetryAttempts[$taskId]);
    }

    public function getPreReviewTaskStatus(): array
    {
        return $this->preReviewTaskStatus;
    }
}
