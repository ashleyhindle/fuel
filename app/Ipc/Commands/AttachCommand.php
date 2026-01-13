<?php

declare(strict_types=1);

namespace App\Ipc\Commands;

use App\Enums\ConsumeCommandType;
use App\Ipc\Concerns\HasIpcMetadata;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonSerializable;

final class AttachCommand implements IpcMessage, JsonSerializable
{
    use HasIpcMetadata;

    public function __construct(
        public ?int $last_event_id,
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
            last_event_id: $data['last_event_id'] ?? null,
            timestamp: new DateTimeImmutable($data['timestamp'] ?? 'now'),
            instanceId: $data['instance_id'] ?? '',
            requestId: $data['request_id'] ?? null
        );
    }

    public function type(): string
    {
        return ConsumeCommandType::Attach->value;
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
            'last_event_id' => $this->last_event_id,
        ];
    }
}
