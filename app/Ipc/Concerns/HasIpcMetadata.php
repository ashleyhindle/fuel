<?php

declare(strict_types=1);

namespace App\Ipc\Concerns;

use DateTimeImmutable;

trait HasIpcMetadata
{
    protected DateTimeImmutable $timestamp;

    protected string $instanceId;

    protected ?string $requestId = null;

    public function timestamp(): DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function instanceId(): string
    {
        return $this->instanceId;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Convert the message to an array representation.
     * Implementers should call parent::toArray() and merge with their own fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'timestamp' => $this->timestamp->format('c'),
            'instance_id' => $this->instanceId,
            'request_id' => $this->requestId,
        ];
    }

    /**
     * Set the timestamp for this message.
     */
    protected function setTimestamp(DateTimeImmutable $timestamp): void
    {
        $this->timestamp = $timestamp;
    }

    /**
     * Set the instance ID for this message.
     */
    protected function setInstanceId(string $instanceId): void
    {
        $this->instanceId = $instanceId;
    }

    /**
     * Set the request ID for this message.
     */
    protected function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
    }
}
