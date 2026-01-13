<?php

declare(strict_types=1);

namespace App\Ipc;

use DateTimeImmutable;

interface IpcMessage
{
    /**
     * Get the message type identifier.
     */
    public function type(): string;

    /**
     * Get the message timestamp.
     */
    public function timestamp(): DateTimeImmutable;

    /**
     * Get the instance ID that created this message.
     */
    public function instanceId(): string;

    /**
     * Get the request ID if this is part of a request/response flow.
     */
    public function requestId(): ?string;

    /**
     * Convert the message to an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
