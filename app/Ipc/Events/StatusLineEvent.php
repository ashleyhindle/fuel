<?php

declare(strict_types=1);

namespace App\Ipc\Events;

use App\Enums\ConsumeEventType;
use App\Ipc\Concerns\HasIpcMetadata;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonSerializable;

final class StatusLineEvent implements IpcMessage, JsonSerializable
{
    use HasIpcMetadata;

    public function __construct(
        private readonly string $level,
        private readonly string $text,
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
        return ConsumeEventType::StatusLine->value;
    }

    public function level(): string
    {
        return $this->level;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function toArray(): array
    {
        return [
            'type' => ConsumeEventType::StatusLine->value,
            'timestamp' => $this->timestamp->format('c'),
            'instance_id' => $this->instanceId,
            'request_id' => $this->requestId,
            'level' => $this->level,
            'text' => $this->text,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
