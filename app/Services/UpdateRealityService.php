<?php

declare(strict_types=1);

namespace App\Services;

use App\Agents\Tasks\UpdateRealityAgentTask;
use App\Models\Epic;
use App\Models\Task;

/**
 * Service for triggering reality.md updates after task/epic completion.
 *
 * Creates a reality task that gets consumed through the normal daemon queue.
 * This ensures reality updates respect pause state and agent health.
 */
class UpdateRealityService
{
    public function __construct(
        private readonly ConfigService $configService,
    ) {}

    /**
     * Trigger a reality.md update after task or epic completion.
     *
     * Creates a reality task that will be picked up by the daemon's normal task queue.
     * This respects pause state, agent health, and concurrent limits.
     *
     * @param  Task|null  $task  The completed task (for solo task completion)
     * @param  Epic|null  $epic  The approved epic (for epic approval)
     * @return Task|null The created reality task, or null if reality agent not configured
     */
    public function triggerUpdate(?Task $task = null, ?Epic $epic = null): ?Task
    {
        // No-op if neither task nor epic provided
        if (! $task instanceof Task && ! $epic instanceof Epic) {
            return null;
        }

        // Check if reality agent is configured
        $agentName = $this->configService->getRealityAgent();
        if ($agentName === null) {
            return null;
        }

        // Create the reality task - daemon will pick it up via normal queue
        if ($epic instanceof Epic) {
            return UpdateRealityAgentTask::createForEpic($epic);
        }

        return UpdateRealityAgentTask::createForTask($task);
    }
}
