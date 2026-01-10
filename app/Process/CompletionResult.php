<?php

declare(strict_types=1);

namespace App\Process;

/**
 * Result of a process completion.
 */
final readonly class CompletionResult
{
    public function __construct(
        public string $taskId,
        public string $agentName,
        public int $exitCode,
        public int $duration,
        public ?string $sessionId,
        public ?float $costUsd,
        public string $output,
        public CompletionType $type,
        public ?string $message = null,
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
            return $this->duration . 's';
        }

        $minutes = (int) ($this->duration / 60);
        $secs = $this->duration % 60;

        return sprintf('%dm %ds', $minutes, $secs);
    }
}
