<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Types of agent failures for health tracking and retry decisions.
 */
enum FailureType: string
{
    case Network = 'network';
    case Timeout = 'timeout';
    case Permission = 'permission';
    case Crash = 'crash';

    /**
     * Check if this failure type is retryable.
     * Network and timeout errors are retryable, permission and crashes are not.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::Network, self::Timeout => true,
            self::Permission, self::Crash => false,
        };
    }

    /**
     * Get a human-readable description of the failure type.
     */
    public function description(): string
    {
        return match ($this) {
            self::Network => 'Network error (connection failed, DNS, etc.)',
            self::Timeout => 'Request timed out',
            self::Permission => 'Permission denied (agent blocked from running commands)',
            self::Crash => 'Agent crashed unexpectedly',
        };
    }
}
