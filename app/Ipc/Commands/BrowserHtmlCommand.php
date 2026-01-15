<?php

declare(strict_types=1);

namespace App\Ipc\Commands;

use App\Enums\ConsumeCommandType;
use App\Ipc\Concerns\HasIpcMetadata;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonSerializable;

final class BrowserHtmlCommand implements IpcMessage, JsonSerializable
{
    use HasIpcMetadata;

    public function __construct(
        public readonly string $pageId,
        public readonly ?string $selector = null,
        public readonly ?string $ref = null,
        public readonly bool $inner = false,
        DateTimeImmutable $timestamp = new DateTimeImmutable,
        string $instanceId = '',
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
            ref: $data['ref'] ?? null,
            inner: $data['inner'] ?? false,
            timestamp: new DateTimeImmutable($data['timestamp'] ?? 'now'),
            instanceId: $data['instance_id'] ?? '',
            requestId: $data['request_id'] ?? null
        );
    }

    public function type(): string
    {
        return ConsumeCommandType::BrowserHtml->value;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toArray(): array
    {
        return [
            'type' => ConsumeCommandType::BrowserHtml->value,
            'timestamp' => $this->timestamp->format('c'),
            'instance_id' => $this->instanceId,
            'request_id' => $this->requestId,
            'pageId' => $this->pageId,
            'selector' => $this->selector,
            'ref' => $this->ref,
            'inner' => $this->inner,
        ];
    }
}
