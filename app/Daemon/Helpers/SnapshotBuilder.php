<?php

declare(strict_types=1);

namespace App\Daemon\Helpers;

use App\Contracts\AgentHealthTrackerInterface;
use App\Daemon\LifecycleManager;
use App\DTO\ConsumeSnapshot;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Services\ConfigService;
use App\Services\ProcessManager;
use App\Services\TaskService;

/**
 * Builds snapshots for SnapshotManager.
 * Extracted to reduce SnapshotManager size.
 */
final readonly class SnapshotBuilder
{
    public function __construct(
        private TaskService $taskService,
        private ProcessManager $processManager,
        private ?AgentHealthTrackerInterface $healthTracker,
        private ?LifecycleManager $lifecycleManager,
    ) {}

    public function buildSnapshot(): ConsumeSnapshot
    {
        // Get board data from task service
        $allTasks = $this->taskService->all();
        $boardData = [
            'ready' => $this->taskService->ready(),
            'in_progress' => $allTasks->filter(fn ($t): bool => $t->status === TaskStatus::InProgress),
            'review' => $allTasks->filter(fn ($t): bool => $t->status === TaskStatus::Review)
                ->sortByDesc('updated_at')
                ->values(),
            'blocked' => $this->taskService->blocked(),
            'human' => $allTasks->filter(fn ($t): bool => $t->status === TaskStatus::Open && is_array($t->labels) && in_array('needs-human', $t->labels, true)),
            'done' => $allTasks->filter(fn ($t): bool => $t->status === TaskStatus::Done)
                ->sortByDesc('updated_at')
                ->values(),
        ];

        // Get active processes
        $activeProcesses = $this->processManager->getActiveProcesses();

        // Get health statuses
        $healthStatuses = [];
        if ($this->healthTracker instanceof AgentHealthTrackerInterface) {
            $healthStatuses = $this->healthTracker->getAllHealthStatus();
        }

        // Get agent limits from config
        $configService = app(ConfigService::class);
        $agentLimits = $configService->getAgentLimits();

        // Get all epics referenced by tasks (for display)
        $epicIds = $allTasks->pluck('epic_id')->filter()->unique()->values()->toArray();
        $epics = $epicIds !== [] ? Epic::whereIn('id', $epicIds)->get()->all() : [];

        // Get runner state info from LifecycleManager if available
        $paused = $this->lifecycleManager?->isPaused() ?? true;
        $startedAt = $this->lifecycleManager?->getStartedAt()->getTimestamp() ?? null;
        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';

        return ConsumeSnapshot::fromBoardData(
            boardData: $boardData,
            activeProcesses: $activeProcesses,
            healthStatuses: $healthStatuses,
            paused: $paused,
            startedAt: $startedAt,
            instanceId: $instanceId,
            intervalSeconds: 5, // Default interval
            agentLimits: $agentLimits,
            epics: $epics
        );
    }

    /**
     * Generate a hash of the snapshot for change detection.
     * Excludes volatile fields like timestamps.
     */
    public function hashSnapshot(ConsumeSnapshot $snapshot): string
    {
        // Hash the board state task IDs and statuses (not full task data which may have volatile timestamps)
        $hashData = [
            'board' => [],
            'active' => array_keys($snapshot->activeProcesses),
            'paused' => $snapshot->runnerState['paused'] ?? false,
        ];

        foreach ($snapshot->boardState as $status => $tasks) {
            $hashData['board'][$status] = $tasks->pluck('short_id')->sort()->values()->toArray();
        }

        return md5(json_encode($hashData, JSON_THROW_ON_ERROR));
    }
}
