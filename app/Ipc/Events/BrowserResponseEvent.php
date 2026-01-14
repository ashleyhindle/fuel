<?php

declare(strict_types=1);

namespace App\Ipc\Events;

use App\Enums\ConsumeEventType;
use App\Ipc\Concerns\HasIpcMetadata;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonSerializable;

/**
 * Response event sent after browser automation commands are processed.
 * Contains the result or error information.
 */
final class BrowserResponseEvent implements IpcMessage, JsonSerializable
{
    use HasIpcMetadata;

    public function __construct(
        public bool $success,
        public ?array $result,
        public ?string $error,
        public ?string $errorCode,
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
            success: $data['success'] ?? false,
            result: $data['result'] ?? null,
            error: $data['error'] ?? null,
            errorCode: $data['error_code'] ?? null,
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : new DateTimeImmutable,
            instanceId: $data['instance_id'] ?? '',
            requestId: $data['request_id'] ?? null
        );
    }

    public function type(): string
    {
        return ConsumeEventType::BrowserResponse->value;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toArray(): array
    {
        return [
            'type' => ConsumeEventType::BrowserResponse->value,
            'timestamp' => $this->timestamp->format('c'),
            'instance_id' => $this->instanceId,
            'request_id' => $this->requestId,
            'success' => $this->success,
            'result' => $this->result,
            'error' => $this->error,
            'error_code' => $this->errorCode,
        ];
    }
}
