<?php

declare(strict_types=1);

namespace App\Ipc\Commands;

use App\Enums\ConsumeCommandType;
use App\Ipc\Concerns\HasIpcMetadata;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonSerializable;

final class BrowserWaitCommand implements IpcMessage, JsonSerializable
{
    use HasIpcMetadata;

    public function __construct(
        public readonly string $pageId,
        public readonly ?string $selector = null,
        public readonly ?string $ref = null,
        public readonly ?int $delay = null,
        public readonly ?string $url = null,
        public readonly ?string $text = null,
        public readonly string $state = 'visible',
        public readonly int $timeout = 30000,
        ?DateTimeImmutable $timestamp = null,
        ?string $instanceId = null,
        ?string $requestId = null
    ) {
        $this->setTimestamp($timestamp ?? new DateTimeImmutable);
        $this->setInstanceId($instanceId ?? '');
        $this->setRequestId($requestId);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            pageId: $data['page_id'] ?? '',
            selector: $data['selector'] ?? null,
            ref: $data['ref'] ?? null,
            delay: isset($data['delay']) ? (int) $data['delay'] : null,
            url: $data['url'] ?? null,
            text: $data['text'] ?? null,
            state: $data['state'] ?? 'visible',
            timeout: $data['timeout'] ?? 30000,
            timestamp: new DateTimeImmutable($data['timestamp'] ?? 'now'),
            instanceId: $data['instance_id'] ?? '',
            requestId: $data['request_id'] ?? null
        );
    }

    public function type(): string
    {
        return ConsumeCommandType::BrowserWait->value;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toArray(): array
    {
        return [
            'type' => ConsumeCommandType::BrowserWait->value,
            'timestamp' => $this->timestamp->format('c'),
            'instance_id' => $this->instanceId,
            'request_id' => $this->requestId,
            'page_id' => $this->pageId,
            'selector' => $this->selector,
            'ref' => $this->ref,
            'delay' => $this->delay,
            'url' => $this->url,
            'text' => $this->text,
            'state' => $this->state,
            'timeout' => $this->timeout,
        ];
    }
}
