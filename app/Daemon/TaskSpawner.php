<?php

declare(strict_types=1);

namespace App\Daemon;

use App\Contracts\AgentHealthTrackerInterface;
use App\Models\Task;
use App\Services\ConfigService;
use App\Services\FuelContext;
use App\Services\ProcessManager;
use App\Services\RunService;
use App\Services\TaskPromptBuilder;
use App\Services\TaskService;
use Illuminate\Support\Collection;

/**
 * Handles task spawning logic for the consume runner.
 *
 * Responsibilities:
 * - Select appropriate agent based on complexity
 * - Check agent capacity and health before spawning
 * - Manage task state mutations (start, set consumed)
 * - Create run entries
 * - Spawn processes via ProcessManager
 * - Cache ready tasks for performance
 */
final class TaskSpawner
{
    /** Cache TTL for task data in seconds */
    private const TASK_CACHE_TTL = 2;

    /** @var array{ready: Collection|null, timestamp: int} */
    private array $taskCache = ['ready' => null, 'timestamp' => 0];

    /** @var string The runner instance ID */
    private string $instanceId;

    /** @var bool Whether the runner is shutting down */
    private bool $shuttingDown = false;

    public function __construct(
        private readonly TaskService $taskService,
        private readonly ConfigService $configService,
        private readonly RunService $runService,
        private readonly ProcessManager $processManager,
        private readonly TaskPromptBuilder $promptBuilder,
        private readonly FuelContext $fuelContext,
        private readonly ?AgentHealthTrackerInterface $healthTracker = null,
    ) {
        // Instance ID will be set externally
        $this->instanceId = '';
    }

    /**
     * Set the instance ID (for cases where it needs to be synchronized with ConsumeRunner).
     */
    public function setInstanceId(string $instanceId): void
    {
        $this->instanceId = $instanceId;
    }

    /**
     * Get the instance ID.
     */
    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    /**
     * Set whether the runner is shutting down.
     */
    public function setShuttingDown(bool $shuttingDown): void
    {
        $this->shuttingDown = $shuttingDown;
    }

    /**
     * Try to spawn a task if agent capacity allows.
     * @return bool True if task was spawned, false otherwise
     */
    public function trySpawnTask(Task $task, ?string $agentOverride, ?callable $onTaskSpawned = null): bool
    {
        if ($this->shuttingDown) {
            return false;
        }

        $taskId = $task->short_id;
        $cwd = $this->fuelContext->getProjectPath();
        $fullPrompt = $this->promptBuilder->build($task, $cwd);

        $agentName = $this->determineAgent($task, $agentOverride);
        if ($agentName === null || ! $this->isAgentAvailable($agentName)) {
            return false;
        }

        $this->markTaskConsumed($taskId);
        $runId = $this->createRunEntry($taskId, $agentName, $agentOverride);

        $result = $this->processManager->spawnForTask($task, $fullPrompt, $cwd, $agentOverride, $runId);
        if (! $result->success) {
            $this->handleSpawnFailure($taskId, $result->isInBackoff());
            return false;
        }

        $this->storePid($taskId, $runId, $result->process->getPid());

        if ($onTaskSpawned !== null) {
            $onTaskSpawned($taskId, $runId, $agentName);
        }

        return true;
    }

    private function determineAgent(Task $task, ?string $agentOverride): ?string
    {
        if ($agentOverride !== null) {
            return $agentOverride;
        }
        $complexity = $task->complexity ?? 'simple';
        try {
            return $this->configService->getAgentForComplexity($complexity);
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    private function isAgentAvailable(string $agentName): bool
    {
        if (! $this->processManager->canSpawn($agentName)) {
            return false;
        }
        if ($this->healthTracker instanceof AgentHealthTrackerInterface) {
            if (! $this->healthTracker->isAvailable($agentName)) {
                return false;
            }
            $maxRetries = $this->configService->getAgentMaxRetries($agentName);
            if ($this->healthTracker->isDead($agentName, $maxRetries)) {
                return false;
            }
        }
        return true;
    }

    private function markTaskConsumed(string $taskId): void
    {
        $this->taskService->start($taskId);
        $this->taskService->update($taskId, ['consumed' => true]);
        $this->invalidateTaskCache();
    }

    private function createRunEntry(string $taskId, string $agentName, ?string $agentOverride): string
    {
        $agentDef = $this->configService->getAgentDefinition($agentName);
        return $this->runService->createRun($taskId, [
            'agent' => $agentName,
            'model' => $agentDef['model'] ?? null,
            'started_at' => date('c'),
            'runner_instance_id' => $this->instanceId,
        ]);
    }

    private function handleSpawnFailure(string $taskId, bool $isInBackoff): void
    {
        $this->taskService->reopen($taskId);
        $this->invalidateTaskCache();
    }

    private function storePid(string $taskId, string $runId, int $pid): void
    {
        $this->runService->updateRun($runId, ['pid' => $pid]);
        $this->taskService->update($taskId, ['consume_pid' => $pid]);
    }

    /**
     * Get cached ready tasks (refreshes if cache expired or after task mutations).
     *
     * @return Collection<int, Task>
     */
    public function getCachedReadyTasks(): Collection
    {
        $now = time();
        if ($this->taskCache['ready'] === null || ($now - $this->taskCache['timestamp']) >= self::TASK_CACHE_TTL) {
            $this->taskCache['ready'] = $this->taskService->ready();
            $this->taskCache['timestamp'] = $now;
        }

        return $this->taskCache['ready'];
    }

    /**
     * Invalidate task cache (call after mutations like start, update, done).
     */
    public function invalidateTaskCache(): void
    {
        $this->taskCache = ['ready' => null, 'timestamp' => 0];
    }
}
