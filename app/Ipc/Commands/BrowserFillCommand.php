<?php

declare(strict_types=1);

namespace App\Ipc\Commands;

use App\Enums\ConsumeCommandType;
use App\Ipc\Concerns\HasIpcMetadata;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonSerializable;

final class BrowserFillCommand implements IpcMessage, JsonSerializable
{
    use HasIpcMetadata;

    public function __construct(
        public string $pageId,
        public ?string $selector,
        public string $value,
        public ?string $ref,
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
            pageId: $data['pageId'],
            selector: $data['selector'] ?? null,
            value: $data['value'],
            ref: $data['ref'] ?? null,
            timestamp: new DateTimeImmutable($data['timestamp'] ?? 'now'),
            instanceId: $data['instance_id'] ?? '',
            requestId: $data['request_id'] ?? null
        );
    }

    public function type(): string
    {
        return ConsumeCommandType::BrowserFill->value;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toArray(): array
    {
        return [
            'type' => ConsumeCommandType::BrowserFill->value,
            'timestamp' => $this->timestamp->format('c'),
            'instance_id' => $this->instanceId,
            'request_id' => $this->requestId,
            'pageId' => $this->pageId,
            'selector' => $this->selector,
            'value' => $this->value,
            'ref' => $this->ref,
        ];
    }
}
