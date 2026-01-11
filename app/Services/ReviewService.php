<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProcessManagerInterface;
use App\Contracts\ReviewServiceInterface;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Process\ProcessOutput;
use App\Process\ProcessType;
use App\Process\ReviewResult;
use App\Prompts\ReviewPrompt;
use App\Repositories\ReviewRepository;
use App\Repositories\RunRepository;
use Carbon\Carbon;
use Symfony\Component\Process\Process;

/**
 * Orchestrates the review process when agents complete tasks.
 */
class ReviewService implements ReviewServiceInterface
{
    /** @var array<string, array{reviewId: string, timestamp: int, runId: string}> Task IDs currently being reviewed with review ID, start timestamp, and run ID */
    private array $pendingReviews = [];

    public function __construct(
        private readonly ProcessManagerInterface $processManager,
        private readonly TaskService $taskService,
        private readonly ConfigService $configService,
        private readonly ReviewPrompt $reviewPrompt,
        private readonly DatabaseService $databaseService,
        private readonly RunService $runService,
        private readonly ReviewRepository $reviewRepository,
        private readonly RunRepository $runRepository,
    ) {}

    /**
     * Trigger a review for a completed task.
     *
     * Called by ConsumeCommand when an agent exits successfully.
     * Spawns a review agent to check the work.
     * Non-blocking - returns immediately, review runs in background.
     *
     * Returns false if no review agent is configured (review skipped).
     *
     * @param  string  $taskId  The ID of the task to review
     * @param  string  $agent  The agent name that completed the task
     * @return bool True if review was triggered, false if skipped (no review agent)
     */
    public function triggerReview(string $taskId, string $agent): bool
    {
        // 1. Get task details
        $task = $this->taskService->find($taskId);
        if (! $task instanceof Task) {
            throw new \RuntimeException(sprintf("Task '%s' not found", $taskId));
        }

        // 2. Get review agent from config (falls back to primary, then null)
        $reviewAgent = $this->configService->getReviewAgent();
        if ($reviewAgent === null) {
            // No review agent configured - skip review
            return false;
        }

        // 3. Capture git state
        $gitDiff = '';
        $gitStatus = '';

        try {
            $diffProcess = new Process(['git', 'diff', 'HEAD~1']);
            $diffProcess->run();
            $gitDiff = $diffProcess->getOutput();
        } catch (\Throwable) {
            // Silently handle errors (matching original behavior of 2>/dev/null)
        }

        try {
            $statusProcess = new Process(['git', 'status', '--porcelain']);
            $statusProcess->run();
            $gitStatus = $statusProcess->getOutput();
        } catch (\Throwable) {
            // Silently handle errors (matching original behavior of 2>/dev/null)
        }

        // 4. Build review prompt
        $prompt = $this->reviewPrompt->generate($task, $gitDiff, $gitStatus);

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
        $command = implode(' ', array_map(escapeshellarg(...), $commandParts));

        // 6. Create run entry for the review (upfront so we have run ID for directory)
        $runShortId = $this->runService->createRun($taskId, [
            'agent' => $reviewAgent,
            'started_at' => date('c'),
        ]);

        // 7. Spawn review process with run-based directory
        $reviewTaskId = 'review-'.$taskId;
        $this->processManager->spawn(
            $reviewTaskId,
            $reviewAgent,
            $command,
            getcwd(),
            ProcessType::Review,
            $runShortId  // Pass run short_id for run-based directory
        );

        // 8. Update task status to 'review'
        $this->taskService->update($taskId, ['status' => TaskStatus::Review->value]);

        // 9. Record review started in database (need to get integer ID for database)
        $runIntId = $this->runRepository->resolveToIntegerId($runShortId);
        $taskIntId = $this->reviewRepository->resolveTaskId($taskId);
        $reviewShortId = 'r-'.bin2hex(random_bytes(3));
        $this->reviewRepository->createReview($reviewShortId, $taskIntId, $reviewAgent, $runIntId);
        $reviewId = $reviewShortId;

        // 10. Track pending review with review ID and run short ID
        $this->pendingReviews[$taskId] = [
            'reviewId' => $reviewId,
            'timestamp' => Carbon::now('UTC')->timestamp,
            'runId' => $runShortId,  // Store run short_id for directory access
        ];

        return true;
    }

    /**
     * Build the prompt for the reviewing agent.
     *
     * @param  Task  $task  The task model to review
     * @param  string  $gitDiff  The git diff output
     * @param  string  $gitStatus  The git status output
     * @return string The review prompt
     */
    public function getReviewPrompt(Task $task, string $gitDiff, string $gitStatus): string
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
     * Parses structured JSON from review agent output to determine result.
     *
     * @param  string  $taskId  The task ID to get the result for
     * @return ReviewResult|null The review result, or null if review not complete
     */
    public function getReviewResult(string $taskId): ?ReviewResult
    {
        if (! $this->isReviewComplete($taskId)) {
            return null;
        }

        // Get run ID from pending reviews to access run-based directory
        $runId = $this->pendingReviews[$taskId]['runId'] ?? null;
        if ($runId === null) {
            // Fallback to old task-based directory for backward compatibility
            $runId = 'review-'.$taskId;
        }

        $output = $this->processManager->getOutput($runId);

        // Parse structured JSON from review agent output
        $parsedResult = $this->parseReviewOutput($output);

        $issues = [];
        $passed = true;

        if ($parsedResult !== null) {
            // Use parsed JSON result
            $passed = $parsedResult['passed'];
            $issues = $parsedResult['issues'];
        } else {
            // No valid JSON found - check if the review agent ran `fuel done`
            // If so, the task status would be 'closed', meaning review passed
            $task = $this->taskService->find($taskId);
            if ($task instanceof Task && $task->status === TaskStatus::Closed->value) {
                $passed = true;
            } else {
                // No JSON and task not done - review failed or agent crashed
                $passed = false;
                $issues[] = 'Review agent did not output structured result';
            }
        }

        // Record review completed in database
        $reviewId = $this->pendingReviews[$taskId]['reviewId'] ?? null;
        if ($reviewId !== null) {
            $this->reviewRepository->markAsCompleted($reviewId, $passed, $issues);
        }

        // Remove from pending reviews
        unset($this->pendingReviews[$taskId]);

        return new ReviewResult(
            taskId: $taskId,
            passed: $passed,
            issues: $issues,
            completedAt: Carbon::now('UTC')->toIso8601String()
        );
    }

    /**
     * Parse structured JSON from review agent output.
     *
     * Looks for JSON like: {"result": "pass|fail", "issues": [...]}
     *
     * @return array{passed: bool, issues: array<string>}|null Parsed result or null if no valid JSON found
     */
    private function parseReviewOutput(ProcessOutput $output): ?array
    {
        $combined = $output->getCombined();

        // Find all positions where JSON might start with {"result"
        // Then try to parse valid JSON from each position
        $pattern = '/\{"result"\s*:\s*"(pass|fail)"/';
        if (! preg_match_all($pattern, $combined, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        // Try each match position, keeping the last valid one
        $lastValidData = null;
        foreach ($matches[0] as $match) {
            $startPos = $match[1];
            $substring = substr($combined, $startPos);

            // Try to find valid JSON by looking for balanced braces
            $braceCount = 0;
            $inString = false;
            $escape = false;
            $endPos = 0;

            for ($i = 0; $i < strlen($substring); $i++) {
                $char = $substring[$i];

                if ($escape) {
                    $escape = false;

                    continue;
                }

                if ($char === '\\' && $inString) {
                    $escape = true;

                    continue;
                }

                if ($char === '"' && ! $escape) {
                    $inString = ! $inString;

                    continue;
                }

                if (! $inString) {
                    if ($char === '{') {
                        $braceCount++;
                    } elseif ($char === '}') {
                        $braceCount--;
                        if ($braceCount === 0) {
                            $endPos = $i + 1;
                            break;
                        }
                    }
                }
            }

            if ($endPos > 0) {
                $jsonStr = substr($substring, 0, $endPos);
                $data = json_decode($jsonStr, true);
                if ($data !== null && isset($data['result'], $data['issues'])) {
                    $lastValidData = $data;
                }
            }
        }

        if ($lastValidData === null) {
            return null;
        }

        $passed = $lastValidData['result'] === 'pass';
        $issues = [];

        if (isset($lastValidData['issues']) && is_array($lastValidData['issues'])) {
            foreach ($lastValidData['issues'] as $issue) {
                if (is_array($issue) && isset($issue['description'])) {
                    $issues[] = $issue['description'];
                } elseif (is_array($issue) && isset($issue['type'])) {
                    $issues[] = $issue['type'];
                } elseif (is_string($issue)) {
                    $issues[] = $issue;
                }
            }
        }

        return ['passed' => $passed, 'issues' => $issues];
    }

    /**
     * Recover stuck reviews by re-triggering reviews for tasks stuck in 'review' status.
     *
     * Tasks can get stuck in 'review' status if consume crashes after spawning a review,
     * or if 'fuel review' is run manually and exits. This method detects such tasks
     * and re-triggers their reviews.
     *
     * @return array<string> Array of task IDs that were recovered
     */
    public function recoverStuckReviews(): array
    {
        $recovered = [];

        // Find all tasks in 'review' status
        $allTasks = $this->taskService->all();
        $reviewTasks = $allTasks->filter(fn (Task $task): bool => ($task->status ?? '') === TaskStatus::Review->value);

        foreach ($reviewTasks as $task) {
            $taskId = $task->id;
            $reviewTaskId = 'review-'.$taskId;

            // Check if review process is still running
            if ($this->processManager->isRunning($reviewTaskId)) {
                // Review is still running, not stuck
                continue;
            }

            // Review process is not running - task is stuck
            // Get the agent that completed the task from run history
            $latestRun = $this->runService->getLatestRun($taskId);
            $agent = $latestRun?->agent ?? null;

            if ($agent === null) {
                // No run history, skip this task
                continue;
            }

            // Re-trigger the review
            try {
                $triggered = $this->triggerReview($taskId, $agent);
                if ($triggered) {
                    $recovered[] = $taskId;
                } else {
                    // No review agent configured - mark task as closed
                    $this->taskService->update($taskId, ['status' => TaskStatus::Closed->value]);
                }
            } catch (\Throwable) {
                // Failed to re-trigger, skip this task
                continue;
            }
        }

        return $recovered;
    }
}
