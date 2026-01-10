<?php

declare(strict_types=1);

namespace App\Process;

/**
 * Result of a process completion.
 */
final class CompletionResult
{
    public function __construct(
        public readonly string $taskId,
        public readonly string $agentName,
        public readonly int $exitCode,
        public readonly int $duration,
        public readonly ?string $sessionId,
        public readonly ?float $costUsd,
        public readonly string $output,
        public readonly CompletionType $type,
        public readonly ?string $message = null,
    ) {}

    /**
     * Check if this was a successful completion.
     */
    public function isSuccess(): bool
    {
        return $this->type === CompletionType::Success;
    }

    /**
     * Check if this was a network error (should retry).
     */
    public function isNetworkError(): bool
    {
        return $this->type === CompletionType::NetworkError;
    }

    /**
     * Check if this was blocked by permissions.
     */
    public function isPermissionBlocked(): bool
    {
        return $this->type === CompletionType::PermissionBlocked;
    }

    /**
     * Check if this was a failure.
     */
    public function isFailed(): bool
    {
        return $this->type === CompletionType::Failed;
    }

    /**
     * Get formatted duration string.
     */
    public function getFormattedDuration(): string
    {
        if ($this->duration < 60) {
            return "{$this->duration}s";
        }

        $minutes = (int) ($this->duration / 60);
        $secs = $this->duration % 60;

        return "{$minutes}m {$secs}s";
    }
}
