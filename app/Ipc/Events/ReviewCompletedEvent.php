<?php

declare(strict_types=1);

namespace App\Ipc\Events;

use App\Enums\ConsumeEventType;
use App\Ipc\Concerns\HasIpcMetadata;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonSerializable;

final class ReviewCompletedEvent implements IpcMessage, JsonSerializable
{
    use HasIpcMetadata;

    public function __construct(
        private readonly string $taskId,
        private readonly bool $passed,
        private readonly array $issues,
        private readonly bool $wasAlreadyDone,
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
        return ConsumeEventType::ReviewCompleted->value;
    }

    public function taskId(): string
    {
        return $this->taskId;
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function issues(): array
    {
        return $this->issues;
    }

    public function wasAlreadyDone(): bool
    {
        return $this->wasAlreadyDone;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'timestamp' => $this->timestamp->format('c'),
            'instance_id' => $this->instanceId,
            'request_id' => $this->requestId,
            'task_id' => $this->taskId,
            'passed' => $this->passed,
            'issues' => $this->issues,
            'was_already_done' => $this->wasAlreadyDone,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
