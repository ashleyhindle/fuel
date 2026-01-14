<?php

declare(strict_types=1);

namespace App\Daemon;

use App\Contracts\AgentHealthTrackerInterface;
use App\Contracts\ReviewServiceInterface;
use App\Daemon\Helpers\CompletionHandlers;
use App\Models\Task;
use App\Process\CompletionResult;
use App\Process\CompletionType;
use App\Services\ConfigService;
use App\Services\ProcessManager;
use App\Services\RunService;
use App\Services\TaskService;

/**
 * Handles process completion results and orchestrates follow-up actions.
 *
 * Responsibilities:
 * - Poll running processes for completion
 * - Handle success/failure/error completion types
 * - Trigger reviews for successful completions
 * - Create needs-human tasks for permission blocks
 * - Track and manage retry attempts
 * - Update run records with completion data
 */
final class CompletionHandler
{
    private readonly CompletionHandlers $handlers;

    public function __construct(
        private readonly ProcessManager $processManager,
        private readonly TaskService $taskService,
        private readonly RunService $runService,
        private readonly ConfigService $configService,
        private readonly ?AgentHealthTrackerInterface $healthTracker = null,
        private readonly ?ReviewServiceInterface $reviewService = null,
    ) {
        $this->handlers = new CompletionHandlers(
            $taskService,
            $configService,
            $healthTracker,
            $reviewService
        );
    }

    public function setTaskReviewEnabled(bool $enabled): void
    {
        $this->handlers->setTaskReviewEnabled($enabled);
    }

    public function isTaskReviewEnabled(): bool
    {
        return $this->handlers->taskReviewEnabled ?? false;
    }

    /**
     * Poll all running processes and handle completions.
     *
     * @return array<CompletionResult> Array of completion results that were handled
     */
    public function pollAndHandleCompletions(): array
    {
        // Update session_id in run service as processes are polled
        // Skip review processes as they don't have run entries
        foreach ($this->processManager->getActiveProcesses() as $process) {
            if ($process->getProcessType() === \App\Process\ProcessType::Review) {
                continue;
            }

            if ($process->getSessionId() !== null) {
                $this->updateLatestRunIfTaskExists($process->getTaskId(), [
                    'session_id' => $process->getSessionId(),
                ]);
            }
        }

        $completions = $this->processManager->poll();
        $handledCompletions = [];

        foreach ($completions as $completion) {
            $this->handleCompletion($completion);
            $handledCompletions[] = $completion;
        }

        return $handledCompletions;
    }

    /**
     * Handle a completed process result.
     */
    public function handleCompletion(CompletionResult $completion): void
    {
        // Review completions are handled separately by checkCompletedReviews()
        if ($completion->isReview()) {
            return;
        }

        $taskId = $completion->taskId;
        $agentName = $completion->agentName;

        // Get run ID before updating
        $task = $this->taskService->find($taskId);
        $latestRun = $task ? $this->runService->getLatestRun($taskId) : null;
        $runId = $latestRun?->short_id;

        // Update run entry with completion data
        $runData = [
            'ended_at' => date('c'),
            'exit_code' => $completion->exitCode,
            'output' => $completion->output,
        ];
        if ($completion->sessionId !== null) {
            $runData['session_id'] = $completion->sessionId;
        }

        if ($completion->costUsd !== null) {
            $runData['cost_usd'] = $completion->costUsd;
        }

        if ($completion->model !== null) {
            $runData['model'] = $completion->model;
        }

        $this->updateLatestRunIfTaskExists($taskId, $runData);

        // Clear PID from task
        $this->taskService->update($taskId, [
            'consume_pid' => null,
        ]);

        // For Task processes, WorkAgentTask.onSuccess() handles business logic via ProcessManager.poll()
        // We only need to do infra work here (health tracking, retry clearing)
        if ($completion->processType === \App\Process\ProcessType::Task) {
            if ($completion->isSuccess() && $this->healthTracker) {
                $this->healthTracker->recordSuccess($completion->agentName);
            }
            if ($completion->isSuccess()) {
                $this->handlers->clearRetryAttempts($completion->taskId);
            }
            // Failure/NetworkError/PermissionBlocked still need handling
            if (! $completion->isSuccess()) {
                match ($completion->type) {
                    CompletionType::Failed => $this->handlers->handleFailure($completion),
                    CompletionType::NetworkError => $this->handlers->handleNetworkError($completion),
                    CompletionType::PermissionBlocked => $this->handlers->handlePermissionBlocked($completion),
                    default => null,
                };
            }

            return; // Skip business logic - WorkAgentTask.onSuccess() already handled it
        }

        // Handle by completion type for non-Task processes
        match ($completion->type) {
            CompletionType::Success => $this->handlers->handleSuccess($completion),
            CompletionType::Failed => $this->handlers->handleFailure($completion),
            CompletionType::NetworkError => $this->handlers->handleNetworkError($completion),
            CompletionType::PermissionBlocked => $this->handlers->handlePermissionBlocked($completion),
        };
    }

    /**
     * Update the latest run for a task, skipping if the task no longer exists.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateLatestRunIfTaskExists(string $taskId, array $data): void
    {
        if (! $this->taskService->find($taskId) instanceof Task) {
            return;
        }

        $this->runService->updateLatestRun($taskId, $data);
    }

    public function getRetryAttempts(): array
    {
        return $this->handlers->getRetryAttempts();
    }

    public function clearRetryAttempts(string $taskId): void
    {
        $this->handlers->clearRetryAttempts($taskId);
    }
}
