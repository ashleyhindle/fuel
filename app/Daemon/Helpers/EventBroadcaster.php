<?php

declare(strict_types=1);

namespace App\Daemon\Helpers;

use App\Daemon\LifecycleManager;
use App\DTO\ConsumeSnapshot;
use App\Process\CompletionType;
use App\Services\ConsumeIpcServer;

/**
 * Handles IPC event broadcasting for SnapshotManager.
 * Extracted to reduce SnapshotManager size.
 */
final class EventBroadcaster
{
    public function __construct(
        private readonly ConsumeIpcServer $ipcServer,
        private readonly ?LifecycleManager $lifecycleManager,
    ) {}

    public function broadcastHello(): void
    {
        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';
        $helloEvent = new \App\Ipc\Events\HelloEvent(
            version: '1.0.0',
            instanceId: $instanceId
        );
        $this->ipcServer->broadcast($helloEvent);
    }

    public function broadcastSnapshot(ConsumeSnapshot $snapshot): void
    {
        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';
        $snapshotEvent = new \App\Ipc\Events\SnapshotEvent(
            snapshot: $snapshot,
            instanceId: $instanceId
        );
        $this->ipcServer->broadcast($snapshotEvent);
    }

    public function sendSnapshot(string $clientId, ConsumeSnapshot $snapshot): void
    {
        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';
        $snapshotEvent = new \App\Ipc\Events\SnapshotEvent(
            snapshot: $snapshot,
            instanceId: $instanceId
        );
        $this->ipcServer->sendTo($clientId, $snapshotEvent);
    }

    public function broadcastTaskSpawned(string $taskId, string $runId, string $agent): void
    {
        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';
        $event = new \App\Ipc\Events\TaskSpawnedEvent(
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
        $event = new \App\Ipc\Events\TaskCompletedEvent(
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
        $event = new \App\Ipc\Events\OutputChunkEvent(
            taskId: $taskId,
            runId: $runId,
            stream: $stream,
            chunk: $chunk,
            instanceId: $instanceId
        );
        $this->ipcServer->broadcast($event);
    }

    public function broadcastHealthChange(string $agent, string $status): void
    {
        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';
        $event = new \App\Ipc\Events\HealthChangeEvent(
            agent: $agent,
            status: $status,
            instanceId: $instanceId
        );
        $this->ipcServer->broadcast($event);
    }

    public function broadcastConfigReloaded(): void
    {
        $instanceId = $this->lifecycleManager?->getInstanceId() ?? 'unknown';
        $event = new \App\Ipc\Events\ConfigReloadedEvent(
            instanceId: $instanceId
        );
        $this->ipcServer->broadcast($event);
    }
}
