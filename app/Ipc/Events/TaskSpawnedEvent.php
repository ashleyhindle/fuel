<?php

declare(strict_types=1);

namespace App\Ipc\Events;

use App\Enums\ConsumeEventType;
use App\Ipc\Concerns\HasIpcMetadata;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonSerializable;

final class TaskSpawnedEvent implements IpcMessage, JsonSerializable
{
    use HasIpcMetadata;

    public function __construct(
        private readonly string $taskId,
        private readonly string $runId,
        private readonly string $agent,
        string $instanceId,
        ?DateTimeImmutable $timestamp = null,
        ?string $requestId = null
    ) {
        $this->setInstanceId($instanceId);
        $this->setTimestamp($timestamp ?? new DateTimeImmutable);
        $this->setRequestId($requestId);
    }

    public function type(): string
    {
        return ConsumeEventType::TaskSpawned->value;
    }

    public function taskId(): string
    {
        return $this->taskId;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function agent(): string
    {
        return $this->agent;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'timestamp' => $this->timestamp->format('c'),
            'instance_id' => $this->instanceId,
            'request_id' => $this->requestId,
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'agent' => $this->agent,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
