<?php

declare(strict_types=1);

namespace App\Ipc\Commands;

use App\Enums\ConsumeCommandType;
use App\Ipc\Concerns\HasIpcMetadata;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonSerializable;

final class TaskCreateCommand implements IpcMessage, JsonSerializable
{
    use HasIpcMetadata;

    public function __construct(
        public string $title,
        public ?string $description,
        public ?string $labels,
        public ?int $priority,
        public ?string $type,
        public ?string $complexity,
        public ?string $epicId,
        public ?string $blockedBy,
        DateTimeImmutable $timestamp,
        string $instanceId,
        ?string $requestId = null
    ) {
        $this->setTimestamp($timestamp);
        $this->setInstanceId($instanceId);
        $this->setRequestId($requestId);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'],
            description: $data['description'] ?? null,
            labels: $data['labels'] ?? null,
            priority: $data['priority'] ?? null,
            type: $data['task_type'] ?? null,
            complexity: $data['complexity'] ?? null,
            epicId: $data['epic_id'] ?? null,
            blockedBy: $data['blocked_by'] ?? null,
            timestamp: new DateTimeImmutable($data['timestamp'] ?? 'now'),
            instanceId: $data['instance_id'] ?? '',
            requestId: $data['request_id'] ?? null
        );
    }

    public function type(): string
    {
        return ConsumeCommandType::TaskCreate->value;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toArray(): array
    {
        return [
            'type' => ConsumeCommandType::TaskCreate->value,
            'timestamp' => $this->timestamp->format('c'),
            'instance_id' => $this->instanceId,
            'request_id' => $this->requestId,
            'title' => $this->title,
            'description' => $this->description,
            'labels' => $this->labels,
            'priority' => $this->priority,
            'task_type' => $this->type,
            'complexity' => $this->complexity,
            'epic_id' => $this->epicId,
            'blocked_by' => $this->blockedBy,
        ];
    }
}
