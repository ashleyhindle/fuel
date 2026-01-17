<?php

declare(strict_types=1);

namespace App\Daemon\Helpers;

use App\Daemon\LifecycleManager;
use App\DTO\ConsumeSnapshot;
use App\Ipc\Events\ConfigReloadedEvent;
use App\Ipc\Events\HealthChangeEvent;
use App\Ipc\Events\HelloEvent;
use App\Ipc\Events\OutputChunkEvent;
use App\Ipc\Events\SnapshotEvent;
use App\Ipc\Events\TaskCompletedEvent;
use App\Ipc\Events\TaskSpawnedEvent;
use App\Process\AgentHealth;
use App\Process\CompletionType;
use App\Services\ConsumeIpcServer;

/**
 * Handles IPC event broadcasting for SnapshotManager.
 * Extracted to reduce SnapshotManager size.
 */
final readonly class EventBroadcaster
{
    public function __construct(
        private ConsumeIpcServer $ipcServer,
        private ?LifecycleManager $lifecycleManager,
    ) {}

    public function broadcastHello(): void
    {
        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';
        $helloEvent = new HelloEvent(
            version: '1.0.0',
            instanceId: $instanceId
        );
        $this->ipcServer->broadcast($helloEvent);
    }

    public function broadcastSnapshot(ConsumeSnapshot $snapshot): void
    {
        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';
        $snapshotEvent = new SnapshotEvent(
            snapshot: $snapshot,
            instanceId: $instanceId
        );
        $this->ipcServer->broadcast($snapshotEvent);
    }

    public function sendSnapshot(string $clientId, ConsumeSnapshot $snapshot): void
    {
        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';
        $snapshotEvent = new SnapshotEvent(
            snapshot: $snapshot,
            instanceId: $instanceId
        );
        $this->ipcServer->sendTo($clientId, $snapshotEvent);
    }

    public function broadcastTaskSpawned(string $taskId, string $runId, string $agent): void
    {
        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';
        $event = new TaskSpawnedEvent(
            taskId: $taskId,
            runId: $runId,
            agent: $agent,
            instanceId: $instanceId
        );
        $this->ipcServer->broadcast($event);
    }

    public function broadcastTaskCompleted(string $taskId, ?string $runId, int $exitCode, CompletionType $completionType): void
    {
        $typeString = match ($completionType) {
            CompletionType::Success => 'success',
            CompletionType::Failed => 'failed',
            CompletionType::NetworkError => 'network_error',
            CompletionType::PermissionBlocked => 'permission_blocked',
        };

        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';
        $event = new TaskCompletedEvent(
            taskId: $taskId,
            runId: $runId ?? '',
            exitCode: $exitCode,
            completionType: $typeString,
            instanceId: $instanceId
        );
        $this->ipcServer->broadcast($event);
    }

    public function broadcastOutputChunk(string $taskId, string $runId, string $stream, string $chunk): void
    {
        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';
        $event = new OutputChunkEvent(
            taskId: $taskId,
            runId: $runId,
            stream: $stream,
            chunk: $chunk,
            instanceId: $instanceId
        );
        $this->ipcServer->broadcast($event);
    }

    public function broadcastHealthChange(AgentHealth $health): void
    {
        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';
        $event = new HealthChangeEvent(
            agent: $health->agent,
            status: $health->getStatus(),
            consecutiveFailures: $health->consecutiveFailures,
            inBackoff: ! $health->isAvailable(),
            isDead: $health->consecutiveFailures >= 5, // Default threshold
            backoffSeconds: $health->getBackoffSeconds(),
            instanceId: $instanceId
        );
        $this->ipcServer->broadcast($event);
    }

    public function broadcastConfigReloaded(): void
    {
        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';
        $event = new ConfigReloadedEvent(
            instanceId: $instanceId
        );
        $this->ipcServer->broadcast($event);
    }
}
