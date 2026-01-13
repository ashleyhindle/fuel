<?php

declare(strict_types=1);

namespace App\Ipc\Events;

use App\Enums\ConsumeEventType;
use App\Ipc\Concerns\HasIpcMetadata;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonSerializable;

final class HealthChangeEvent implements IpcMessage, JsonSerializable
{
    use HasIpcMetadata;

    public function __construct(
        private readonly string $agent,
        private readonly string $status,
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
        return ConsumeEventType::HealthChange->value;
    }

    public function agent(): string
    {
        return $this->agent;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'timestamp' => $this->timestamp->format('c'),
            'instance_id' => $this->instanceId,
            'request_id' => $this->requestId,
            'agent' => $this->agent,
            'status' => $this->status,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
