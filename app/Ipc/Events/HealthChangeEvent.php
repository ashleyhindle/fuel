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
        private readonly int $consecutiveFailures,
        private readonly bool $inBackoff,
        private readonly bool $isDead,
        private readonly int $backoffSeconds,
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

    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function inBackoff(): bool
    {
        return $this->inBackoff;
    }

    public function isDead(): bool
    {
        return $this->isDead;
    }

    public function backoffSeconds(): int
    {
        return $this->backoffSeconds;
    }

    public function toArray(): array
    {
        return [
            'type' => ConsumeEventType::HealthChange->value,
            'timestamp' => $this->timestamp->format('c'),
            'instance_id' => $this->instanceId,
            'request_id' => $this->requestId,
            'agent' => $this->agent,
            'status' => $this->status,
            'consecutive_failures' => $this->consecutiveFailures,
            'in_backoff' => $this->inBackoff,
            'is_dead' => $this->isDead,
            'backoff_seconds' => $this->backoffSeconds,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
