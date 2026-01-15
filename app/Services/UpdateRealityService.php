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
        private readonly RunService $runService,
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

        $realityTaskId = $agentTask->getTaskId();
        $agentName = $agentTask->getAgentName($this->configService);
        if ($agentName === null) {
            return;
        }

        $model = null;
        try {
            $agentDef = $this->configService->getAgentDefinition($agentName);
            $model = $agentDef['model'] ?? null;
        } catch (\RuntimeException) {
            $model = null;
        }

        $runId = $this->runService->createRun($realityTaskId, [
            'agent' => $agentName,
            'model' => $model,
            'started_at' => date('c'),
        ]);

        // Fire-and-forget spawn - we don't track the result
        // The UpdateRealityAgentTask handles its own lifecycle
        $result = $this->processManager->spawnAgentTask($agentTask, $cwd, $runId);

        if ($result->success && $result->process) {
            $pid = $result->process->getPid();
            if ($pid !== null) {
                $this->runService->updateRun($runId, ['pid' => $pid]);
            }

            app(TaskService::class)->update($realityTaskId, [
                'consumed' => true,
                'consumed_at' => now()->toIso8601String(),
            ]);

            return;
        }

        // Spawn failed: cancel the reality task and close the run entry
        app(TaskService::class)->delete($realityTaskId);
        $this->runService->updateRun($runId, [
            'ended_at' => date('c'),
            'exit_code' => -1,
            'output' => '[Reality update spawn failed]',
        ]);
    }
}
