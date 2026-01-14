<?php

declare(strict_types=1);

namespace App\Ipc\Events;

use App\Enums\ConsumeEventType;
use App\Ipc\Concerns\HasIpcMetadata;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonSerializable;

final class BlockedTasksEvent implements IpcMessage, JsonSerializable
{
    use HasIpcMetadata;

    public function __construct(
        private readonly array $tasks,
        private readonly int $total,
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
        return ConsumeEventType::BlockedTasks->value;
    }

    public function tasks(): array
    {
        return $this->tasks;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function toArray(): array
    {
        return [
            'type' => ConsumeEventType::BlockedTasks->value,
            'timestamp' => $this->timestamp->format('c'),
            'instance_id' => $this->instanceId,
            'request_id' => $this->requestId,
            'tasks' => $this->tasks,
            'total' => $this->total,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
