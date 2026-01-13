<?php

declare(strict_types=1);

namespace App\Ipc\Events;

use App\Ipc\Concerns\HasIpcMetadata;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonSerializable;

/**
 * Response event sent after a TaskCreateCommand is processed.
 * Contains the created task's ID for correlation.
 */
final class TaskCreateResponseEvent implements IpcMessage, JsonSerializable
{
    use HasIpcMetadata;

    public function __construct(
        public string $taskId,
        public bool $success,
        public ?string $error,
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
            taskId: $data['task_id'] ?? '',
            success: $data['success'] ?? false,
            error: $data['error'] ?? null,
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : new DateTimeImmutable,
            instanceId: $data['instance_id'] ?? '',
            requestId: $data['request_id'] ?? null
        );
    }

    public function type(): string
    {
        return 'task_create_response';
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'timestamp' => $this->timestamp->format('c'),
            'instance_id' => $this->instanceId,
            'request_id' => $this->requestId,
            'task_id' => $this->taskId,
            'success' => $this->success,
            'error' => $this->error,
        ];
    }
}
