<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProcessManagerInterface;
use App\Contracts\ReviewServiceInterface;
use App\Process\ProcessType;
use App\Process\ReviewResult;
use App\Prompts\ReviewPrompt;
use Carbon\Carbon;
use Symfony\Component\Process\Process;

/**
 * Orchestrates the review process when agents complete tasks.
 */
class ReviewService implements ReviewServiceInterface
{
    /** @var array<string, array{reviewId: string, timestamp: int}> Task IDs currently being reviewed with review ID and start timestamp */
    private array $pendingReviews = [];

    public function __construct(
        private readonly ProcessManagerInterface $processManager,
        private readonly TaskService $taskService,
        private readonly ConfigService $configService,
        private readonly ReviewPrompt $reviewPrompt,
        private readonly DatabaseService $databaseService,
    ) {}

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
    public function triggerReview(string $taskId, string $agent): void
    {
        // 1. Get task details
        $task = $this->taskService->find($taskId);
        if ($task === null) {
            throw new \RuntimeException(sprintf("Task '%s' not found", $taskId));
        }

        // 2. Capture git state
        $gitDiff = '';
        $gitStatus = '';

        try {
            $diffProcess = new Process(['git', 'diff', 'HEAD~1']);
            $diffProcess->run();
            $gitDiff = $diffProcess->getOutput();
        } catch (\Throwable $e) {
            // Silently handle errors (matching original behavior of 2>/dev/null)
        }

        try {
            $statusProcess = new Process(['git', 'status', '--porcelain']);
            $statusProcess->run();
            $gitStatus = $statusProcess->getOutput();
        } catch (\Throwable $e) {
            // Silently handle errors (matching original behavior of 2>/dev/null)
        }

        // 3. Build review prompt
        $prompt = $this->reviewPrompt->generate($task, $gitDiff, $gitStatus);

        // 4. Get review agent from config (or use same agent that did the work)
        $reviewAgent = $this->configService->getReviewAgent() ?? $agent;

        // 5. Build the command for the review agent
        $agentDef = $this->configService->getAgentDefinition($reviewAgent);
        $commandParts = [$agentDef['command']];

        foreach ($agentDef['prompt_args'] as $promptArg) {
            $commandParts[] = $promptArg;
        }

        $commandParts[] = $prompt;

        // Add model if specified
        if (! empty($agentDef['model'])) {
            $commandParts[] = '--model';
            $commandParts[] = $agentDef['model'];
        }

        // Add additional args
        foreach ($agentDef['args'] as $arg) {
            $commandParts[] = $arg;
        }

        // Build command string with proper escaping
        $command = implode(' ', array_map('escapeshellarg', $commandParts));

        // 6. Spawn review process with special task ID format for reviews
        $reviewTaskId = 'review-'.$taskId;
        $this->processManager->spawn(
            $reviewTaskId,
            $reviewAgent,
            $command,
            getcwd(),
            ProcessType::Review
        );

        // 7. Update task status to 'review'
        $this->taskService->update($taskId, ['status' => 'review']);

        // 8. Record review started in database
        $reviewId = $this->databaseService->recordReviewStarted($taskId, $reviewAgent);

        // 9. Track pending review with review ID
        $this->pendingReviews[$taskId] = ['reviewId' => $reviewId, 'timestamp' => Carbon::now('UTC')->timestamp];
    }

    /**
     * Build the prompt for the reviewing agent.
     *
     * @param  array<string, mixed>  $task  The task array to review
     * @param  string  $gitDiff  The git diff output
     * @param  string  $gitStatus  The git status output
     * @return string The review prompt
     */
    public function getReviewPrompt(array $task, string $gitDiff, string $gitStatus): string
    {
        return $this->reviewPrompt->generate($task, $gitDiff, $gitStatus);
    }

    /**
     * Get task IDs currently being reviewed.
     *
     * Used by consume to track review processes.
     * Prunes completed reviews from the list.
     *
     * @return array<string> Array of task IDs currently under review
     */
    public function getPendingReviews(): array
    {
        return array_keys($this->pendingReviews);
    }

    /**
     * Check if a review is complete for a given task.
     *
     * @param  string  $taskId  The task ID to check
     * @return bool True if review is complete, false otherwise
     */
    public function isReviewComplete(string $taskId): bool
    {
        if (! isset($this->pendingReviews[$taskId])) {
            return false;
        }

        $reviewTaskId = 'review-'.$taskId;

        return ! $this->processManager->isRunning($reviewTaskId);
    }

    /**
     * Get the review result for a completed review.
     *
     * Parses review process output to determine result.
     * Checks if follow-up tasks were created (tasks blocked-by this one with review-fix label).
     *
     * @param  string  $taskId  The task ID to get the result for
     * @return ReviewResult|null The review result, or null if review not complete
     */
    public function getReviewResult(string $taskId): ?ReviewResult
    {
        if (! $this->isReviewComplete($taskId)) {
            return null;
        }

        $reviewTaskId = 'review-'.$taskId;
        $output = $this->processManager->getOutput($reviewTaskId);

        // Check for follow-up tasks created by the reviewer
        // These are tasks with review-fix label that are blocked by this task
        $allTasks = $this->taskService->all();
        $followUpTaskIds = $allTasks
            ->filter(function (array $t) use ($taskId): bool {
                // Check if task has review-fix label
                $labels = $t['labels'] ?? [];
                if (! is_array($labels) || ! in_array('review-fix', $labels, true)) {
                    return false;
                }

                // Check if blocked by the reviewed task
                $blockedBy = $t['blocked_by'] ?? [];

                return is_array($blockedBy) && in_array($taskId, $blockedBy, true);
            })
            ->pluck('id')
            ->values()
            ->toArray();

        // Determine if review passed
        // Review passes if no follow-up tasks were created
        $passed = empty($followUpTaskIds);

        // Detect issues from output
        $issues = [];
        $combinedOutput = $output->stdout.$output->stderr;

        if (preg_match('/uncommitted.*change/i', $combinedOutput)) {
            $issues[] = 'uncommitted_changes';
        }
        if (preg_match('/(test.*fail|fail.*test)/i', $combinedOutput)) {
            $issues[] = 'tests_failing';
        }
        if (preg_match('/(task.*incomplete|incomplete.*task|missing.*work)/i', $combinedOutput)) {
            $issues[] = 'task_incomplete';
        }
        if (preg_match('/task.*mismatch/i', $combinedOutput)) {
            $issues[] = 'task_mismatch';
        }

        // If there are follow-up tasks but no specific issues detected, mark as generic issue
        if (! $passed && empty($issues)) {
            $issues[] = 'review_issues_found';
        }

        // Record review completed in database
        $reviewId = $this->pendingReviews[$taskId]['reviewId'] ?? null;
        if ($reviewId !== null) {
            $this->databaseService->recordReviewCompleted($reviewId, $passed, $issues, $followUpTaskIds);
        }

        // Remove from pending reviews
        unset($this->pendingReviews[$taskId]);

        return new ReviewResult(
            taskId: $taskId,
            passed: $passed,
            issues: $issues,
            followUpTaskIds: $followUpTaskIds,
            completedAt: Carbon::now('UTC')->toIso8601String()
        );
    }
}
