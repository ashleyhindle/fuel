<?php

declare(strict_types=1);

namespace App\Commands;

use App\Contracts\ReviewServiceInterface;
use App\Models\Run;
use App\Models\Task;
use App\Services\ConfigService;
use App\Services\RunService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class ReviewCommand extends Command
{
    protected $signature = 'review {taskId : The task ID to review}';

    protected $description = 'Trigger a review of a completed task';

    public function handle(
        ReviewServiceInterface $reviewService,
        TaskService $taskService,
        RunService $runService,
        ConfigService $configService
    ): int {
        $taskId = $this->argument('taskId');

        // Resolve partial ID
        $task = $taskService->find($taskId);
        if (! $task instanceof Task) {
            $this->error('Task not found: '.$taskId);

            return self::FAILURE;
        }

        // Check task is in reviewable state (in_progress, review, or closed)
        if ($task->status === 'open') {
            $this->error('Cannot review a task that has not been started');

            return self::FAILURE;
        }

        $this->info(sprintf('Triggering review for %s...', $task->id));

        // Get the agent that worked on it (from runs table or default)
        $agent = $this->determineReviewAgent($task->id, $runService, $configService);

        $reviewTriggered = $reviewService->triggerReview($task->id, $agent);

        if (! $reviewTriggered) {
            $this->warn('No review agent configured. Set "review" or "primary" in .fuel/config.yaml');

            return self::FAILURE;
        }

        $this->info('Review spawned. Check `fuel board` for status.');

        return self::SUCCESS;
    }

    /**
     * Determine the review agent for a task.
     *
     * Priority:
     * 1. Agent from latest run (if exists)
     * 2. Config review agent (if configured)
     * 3. Primary agent (fallback)
     *
     * @param  string  $taskId  The task ID
     * @param  RunService  $runService  The run service
     * @param  ConfigService  $configService  The config service
     * @return string The agent name to use for review
     */
    private function determineReviewAgent(
        string $taskId,
        RunService $runService,
        ConfigService $configService
    ): string {
        // Try to get agent from latest run
        $latestRun = $runService->getLatestRun($taskId);
        if ($latestRun instanceof Run && isset($latestRun->agent) && $latestRun->agent !== null) {
            return $latestRun->agent;
        }

        // Fallback to config review agent or primary agent
        $reviewAgent = $configService->getReviewAgent();
        if ($reviewAgent !== null) {
            return $reviewAgent;
        }

        return $configService->getPrimaryAgent();
    }
}
