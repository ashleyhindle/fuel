<?php

declare(strict_types=1);

namespace App\Daemon;

use App\Contracts\AgentHealthTrackerInterface;
use App\Daemon\Helpers\EventBroadcaster;
use App\Daemon\Helpers\SnapshotBuilder;
use App\DTO\ConsumeSnapshot;
use App\Process\CompletionType;
use App\Services\ConsumeIpcServer;
use App\Services\ProcessManager;
use App\Services\TaskService;

/**
 * Manages snapshot generation and broadcasting for the daemon.
 *
 * Responsibilities:
 * - Building snapshots from current state
 * - Broadcasting snapshots to IPC clients
 * - Detecting snapshot changes
 * - Managing output ring buffers
 * - Broadcasting task events (spawned, completed)
 * - Tracking and broadcasting health changes
 */
final class SnapshotManager
{
    private const RING_BUFFER_SIZE = 4096;

    private const SNAPSHOT_BROADCAST_INTERVAL = 2;

    private array $outputRingBuffers = [];

    private array $previousHealthStatus = [];

    private int $lastSnapshotBroadcast = 0;

    private ?string $lastSnapshotHash = null;

    private readonly SnapshotBuilder $builder;

    private readonly EventBroadcaster $broadcaster;

    public function __construct(
        private readonly ConsumeIpcServer $ipcServer,
        private readonly TaskService $taskService,
        private readonly ProcessManager $processManager,
        private readonly ?AgentHealthTrackerInterface $healthTracker = null,
        private readonly ?LifecycleManager $lifecycleManager = null,
    ) {
        $this->builder = new SnapshotBuilder(
            $taskService,
            $processManager,
            $healthTracker,
            $lifecycleManager
        );
        $this->broadcaster = new EventBroadcaster(
            $ipcServer,
            $lifecycleManager
        );
    }

    public function buildSnapshot(): ConsumeSnapshot
    {
        return $this->builder->buildSnapshot();
    }

    public function hashSnapshot(ConsumeSnapshot $snapshot): string
    {
        return $this->builder->hashSnapshot($snapshot);
    }

    public function broadcastHelloAndSnapshot(): void
    {
        $this->broadcaster->broadcastHello();
        try {
            $snapshot = $this->buildSnapshot();
            $this->broadcaster->broadcastSnapshot($snapshot);
            $this->lastSnapshotHash = $this->hashSnapshot($snapshot);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function sendSnapshot(string $clientId): void
    {
        $snapshot = $this->buildSnapshot();
        $this->broadcaster->sendSnapshot($clientId, $snapshot);
    }

    public function broadcastSnapshot(): void
    {
        $snapshot = $this->buildSnapshot();
        $this->broadcaster->broadcastSnapshot($snapshot);
        $this->lastSnapshotHash = $this->hashSnapshot($snapshot);
    }

    public function broadcastSnapshotIfChanged(): void
    {
        $snapshot = $this->buildSnapshot();
        $hash = $this->hashSnapshot($snapshot);
        if ($hash === $this->lastSnapshotHash) {
            return;
        }
        $this->broadcaster->broadcastSnapshot($snapshot);
        $this->lastSnapshotHash = $hash;
    }

    public function broadcastTaskSpawned(string $taskId, string $runId, string $agent): void
    {
        $this->broadcaster->broadcastTaskSpawned($taskId, $runId, $agent);
    }

    public function broadcastTaskCompleted(string $taskId, ?string $runId, int $exitCode, CompletionType $completionType): void
    {
        $this->broadcaster->broadcastTaskCompleted($taskId, $runId, $exitCode, $completionType);
    }

    public function broadcastConfigReloaded(): void
    {
        $this->broadcaster->broadcastConfigReloaded();
    }

    public function handleOutputChunk(string $taskId, string $stream, string $chunk): void
    {
        if (! isset($this->outputRingBuffers[$taskId])) {
            $this->outputRingBuffers[$taskId] = '';
        }
        $this->outputRingBuffers[$taskId] .= $chunk;
        if (strlen($this->outputRingBuffers[$taskId]) > self::RING_BUFFER_SIZE) {
            $this->outputRingBuffers[$taskId] = substr($this->outputRingBuffers[$taskId], -self::RING_BUFFER_SIZE);
        }

        $runId = null;
        foreach ($this->processManager->getActiveProcesses() as $process) {
            if ($process->getTaskId() === $taskId) {
                $runId = $process->getRunId();
                break;
            }
        }

        $this->broadcaster->broadcastOutputChunk($taskId, $runId ?? '', $stream, $chunk);
    }

    public function checkHealthChanges(): void
    {
        if (! $this->healthTracker instanceof AgentHealthTrackerInterface) {
            return;
        }

        foreach ($this->healthTracker->getAllHealthStatus() as $health) {
            $agent = $health->agent;
            $currentStatus = $health->getStatus();
            $previousStatus = $this->previousHealthStatus[$agent] ?? null;

            if ($previousStatus !== $currentStatus) {
                $this->broadcaster->broadcastHealthChange($agent, $currentStatus);
                $this->previousHealthStatus[$agent] = $currentStatus;
            }
        }
    }

    public function cleanupTaskBuffer(string $taskId): void
    {
        unset($this->outputRingBuffers[$taskId]);
    }

    public function shouldBroadcastSnapshot(): bool
    {
        $now = time();
        if (($now - $this->lastSnapshotBroadcast) >= self::SNAPSHOT_BROADCAST_INTERVAL) {
            $this->lastSnapshotBroadcast = $now;

            return true;
        }

        return false;
    }

    public function setLifecycleManager(LifecycleManager $lifecycleManager): void
    {
        // Workaround for circular dependencies - will be removed in future refactor
    }
}
