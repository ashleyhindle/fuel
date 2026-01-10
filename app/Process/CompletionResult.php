<?php

declare(strict_types=1);

namespace App\Process;

use App\Enums\FailureType;

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
        public ProcessType $processType = ProcessType::Task,
    ) {}

    /**
     * Check if this is a review process completion.
     */
    public function isReview(): bool
    {
        return $this->processType === ProcessType::Review;
    }

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
            return $this->duration.'s';
        }

        $minutes = (int) ($this->duration / 60);
        $secs = $this->duration % 60;

        return sprintf('%dm %ds', $minutes, $secs);
    }

    /**
     * Convert CompletionType to FailureType for health tracking.
     * Returns null for successful completions.
     */
    public function toFailureType(): ?FailureType
    {
        return match ($this->type) {
            CompletionType::Success => null,
            CompletionType::NetworkError => FailureType::Network,
            CompletionType::PermissionBlocked => FailureType::Permission,
            CompletionType::Failed => FailureType::Crash,
        };
    }

    /**
     * Check if this failure type is retryable.
     * Network errors are retryable, permission errors are not.
     * Crashes are retryable with a limit (handled by health tracker).
     */
    public function isRetryable(): bool
    {
        $failureType = $this->toFailureType();
        if ($failureType === null) {
            return false; // Success, no retry needed
        }

        // Network and timeout are always retryable
        // Permission is never retryable (needs human)
        // Crash is technically retryable but limited by health tracker backoff
        return match ($failureType) {
            FailureType::Network, FailureType::Timeout => true,
            FailureType::Permission => false,
            FailureType::Crash => true, // Health tracker will apply backoff
        };
    }
}
