<?php

declare(strict_types=1);

namespace App\Services;

use App\Agents\Tasks\UpdateRealityAgentTask;
use App\Contracts\ProcessManagerInterface;
use App\Models\Epic;
use App\Models\Task;

/**
 * Service for triggering reality.md updates after task/epic completion.
 *
 * Spawns UpdateRealityAgentTask in background (fire-and-forget, non-blocking).
 * Updates are best-effort: failures don't block the caller.
 */
class UpdateRealityService
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly FuelContext $fuelContext,
        private readonly ProcessManagerInterface $processManager,
    ) {}

    /**
     * Trigger a reality.md update after task or epic completion.
     *
     * Fire-and-forget: spawns agent in background and returns immediately.
     * Does not block caller. Failures are logged but don't affect caller.
     *
     * @param  Task|null  $task  The completed task (for solo task completion)
     * @param  Epic|null  $epic  The approved epic (for epic approval)
     */
    public function triggerUpdate(?Task $task = null, ?Epic $epic = null): void
    {
        // No-op if neither task nor epic provided
        if ($task === null && $epic === null) {
            return;
        }

        // Check if reality agent is configured
        $agentName = $this->configService->getRealityAgent();
        if ($agentName === null) {
            return;
        }

        // Create the appropriate agent task
        $cwd = $this->fuelContext->getProjectPath();

        if ($epic !== null) {
            $agentTask = UpdateRealityAgentTask::fromEpic($epic, $cwd);
        } else {
            $agentTask = UpdateRealityAgentTask::fromTask($task, $cwd);
        }

        // Fire-and-forget spawn - we don't track the result
        // The UpdateRealityAgentTask handles its own lifecycle (onSuccess/onFailure are no-ops)
        $this->processManager->spawnAgentTask($agentTask, $cwd);
    }
}
