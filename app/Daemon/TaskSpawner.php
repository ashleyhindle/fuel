<?php

declare(strict_types=1);

namespace App\Daemon;

use App\Agents\Tasks\MergeEpicAgentTask;
use App\Agents\Tasks\SelfGuidedAgentTask;
use App\Agents\Tasks\UpdateRealityAgentTask;
use App\Agents\Tasks\WorkAgentTask;
use App\Contracts\AgentHealthTrackerInterface;
use App\Enums\MirrorStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Services\ConfigService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\ProcessManager;
use App\Services\RunService;
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
    // Instance ID will be set externally
    private string $instanceId = '';

    /** @var bool Whether the runner is shutting down */
    private bool $shuttingDown = false;

    /** @var bool Whether review is enabled for spawned tasks */
    private bool $reviewEnabled = false;

    /** @var callable|null Callback for epic completion */
    private $epicCompletionCallback;

    public function __construct(private readonly TaskService $taskService, private readonly ConfigService $configService, private readonly RunService $runService, private readonly ProcessManager $processManager, private readonly FuelContext $fuelContext, private readonly EpicService $epicService, private readonly ?AgentHealthTrackerInterface $healthTracker = null) {}

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
     * Set whether review is enabled for spawned tasks.
     */
    public function setReviewEnabled(bool $reviewEnabled): void
    {
        $this->reviewEnabled = $reviewEnabled;
    }

    /**
     * Set callback for epic completion.
     */
    public function setEpicCompletionCallback(?callable $epicCompletionCallback): void
    {
        $this->epicCompletionCallback = $epicCompletionCallback;
    }

    /**
     * Try to spawn a task if agent capacity allows.
     *
     * @return bool True if task was spawned, false otherwise
     */
    public function trySpawnTask(Task $task, ?string $agentOverride, ?callable $onTaskSpawned = null): bool
    {
        if ($this->shuttingDown) {
            return false;
        }

        $taskId = $task->short_id;
        $cwd = $this->fuelContext->getProjectPath();

        // Check if epic mirrors are enabled and handle mirror routing
        if ($this->configService->getEpicMirrorsEnabled()) {
            // If task has an epic, check mirror status
            if ($task->epic_id !== null) {
                $epic = Epic::find($task->epic_id);
                if ($epic !== null && $epic->mirror_status !== null) {
                    // Check mirror status to determine if we can work on this task
                    switch ($epic->mirror_status) {
                        case MirrorStatus::Ready:
                            // Use the mirror path as the working directory
                            $cwd = $epic->mirror_path;
                            break;
                        case MirrorStatus::Pending:
                        case MirrorStatus::Creating:
                        case MirrorStatus::MergeFailed:
                            // Mirror not ready yet, skip this task
                            return false;
                        default:
                            // For other statuses (None, Merging, Merged, Cleaned), use default behavior
                            break;
                    }
                }
            } elseif ($this->epicService->hasActiveMerge()) {
                // For standalone tasks (no epic_id), check if any epic is merging
                // Skip standalone tasks during merge to prevent git conflicts
                return false;
            }
        }

        // Debug logging to catch task/prompt mismatch issues
        DaemonLogger::getInstance()->debug('TaskSpawner.trySpawnTask', [
            'task_short_id' => $task->short_id,
            'task_id' => $task->id,
            'epic_id' => $task->epic_id,
            'agent' => $task->agent,
        ]);

        // Create appropriate AgentTask based on task type
        if ($task->type === 'reality') {
            $agentTask = UpdateRealityAgentTask::fromTaskModel($task);
        } elseif ($task->type === 'merge') {
            $agentTask = MergeEpicAgentTask::fromTaskModel($task);
        } elseif ($task->type === 'selfguided') {
            $agentTask = app(SelfGuidedAgentTask::class, ['task' => $task]);
        } else {
            $agentTask = app(WorkAgentTask::class, [
                'task' => $task,
                'reviewEnabled' => $this->reviewEnabled,
                'agentOverride' => $agentOverride,
            ]);
        }

        // Wire epicCompletionCallback if set
        if ($this->epicCompletionCallback !== null) {
            $agentTask->setEpicCompletionCallback($this->epicCompletionCallback);
        }

        // Get agent via WorkAgentTask
        $agentName = $agentTask->getAgentName($this->configService);
        if ($agentName === null || ! $this->isAgentAvailable($agentName)) {
            return false;
        }

        $this->markTaskConsumed($taskId);
        $runId = $this->createRunEntry($taskId, $agentName);

        // Replace spawnForTask with spawnAgentTask
        $result = $this->processManager->spawnAgentTask($agentTask, $cwd, $runId);
        if (! $result->success) {
            $this->handleSpawnFailure($taskId);

            return false;
        }

        $this->storePid($runId, $result->process->getPid());

        if ($onTaskSpawned !== null) {
            $onTaskSpawned($taskId, $runId, $agentName);
        }

        return true;
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

    private function createRunEntry(string $taskId, string $agentName): string
    {
        $agentDef = $this->configService->getAgentDefinition($agentName);

        return $this->runService->createRun($taskId, [
            'agent' => $agentName,
            'model' => $agentDef['model'] ?? null,
            'started_at' => date('c'),
            'runner_instance_id' => $this->instanceId,
        ]);
    }

    private function handleSpawnFailure(string $taskId): void
    {
        $this->taskService->reopen($taskId);
        $this->invalidateTaskCache();
    }

    private function storePid(string $runId, int $pid): void
    {
        $this->runService->updateRun($runId, ['pid' => $pid]);
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
