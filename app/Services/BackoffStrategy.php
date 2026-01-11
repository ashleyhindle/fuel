<?php

declare(strict_types=1);

namespace App\Services;

class BackoffStrategy
{
    /**
     * Calculate exponential backoff delay in seconds.
     *
     * @param  int  $attempts  Number of failed attempts (0-indexed)
     * @param  int  $baseDelay  Base delay in seconds (default: 5)
     * @param  int  $maxDelay  Maximum delay cap in seconds (default: 300 = 5 minutes)
     */
    public function calculateDelay(int $attempts, int $baseDelay = 5, int $maxDelay = 300): int
    {
        if ($attempts < 0) {
            $attempts = 0;
        }

        // Exponential backoff: baseDelay * 2^attempts
        $delay = $baseDelay * (2 ** $attempts);

        // Cap at maximum delay
        return min($delay, $maxDelay);
    }

    /**
     * Format backoff seconds into human-readable string.
     *
     * @param  int  $backoffSeconds  Backoff time in seconds
     * @return string Formatted string (e.g., "30s" or "2m 15s")
     */
    public function formatBackoffTime(int $backoffSeconds): string
    {
        if ($backoffSeconds < 60) {
            return $backoffSeconds.'s';
        }

        $minutes = (int) ($backoffSeconds / 60);
        $seconds = $backoffSeconds % 60;

        return sprintf('%dm %ds', $minutes, $seconds);
    }
}
