<?php

declare(strict_types=1);

namespace App\Ipc\Events;

use App\Enums\ConsumeEventType;
use App\Ipc\Concerns\HasIpcMetadata;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonSerializable;

final class OutputChunkEvent implements IpcMessage, JsonSerializable
{
    use HasIpcMetadata;

    public function __construct(
        private readonly string $taskId,
        private readonly string $runId,
        private readonly string $stream,
        private readonly string $chunk,
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
        return ConsumeEventType::OutputChunk->value;
    }

    public function taskId(): string
    {
        return $this->taskId;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function stream(): string
    {
        return $this->stream;
    }

    public function chunk(): string
    {
        return $this->chunk;
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
            'stream' => $this->stream,
            'chunk' => $this->chunk,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
