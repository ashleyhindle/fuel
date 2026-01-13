<?php

declare(strict_types=1);

namespace App\Ipc\Events;

use App\Enums\ConsumeEventType;
use App\Ipc\Concerns\HasIpcMetadata;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonSerializable;

final class TaskCompletedEvent implements IpcMessage, JsonSerializable
{
    use HasIpcMetadata;

    public function __construct(
        private readonly string $taskId,
        private readonly string $runId,
        private readonly int $exitCode,
        private readonly string $completionType,
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
        return ConsumeEventType::TaskCompleted->value;
    }

    public function taskId(): string
    {
        return $this->taskId;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function completionType(): string
    {
        return $this->completionType;
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
            'exit_code' => $this->exitCode,
            'completion_type' => $this->completionType,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
