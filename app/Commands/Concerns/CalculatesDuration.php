<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

/**
 * Provides duration calculation between two timestamps.
 */
trait CalculatesDuration
{
    /**
     * Calculate duration between two timestamps.
     */
    protected function calculateDuration(?string $startedAt, ?string $endedAt): string
    {
        if ($startedAt === null) {
            return '';
        }

        try {
            $start = new \DateTime($startedAt);
            $end = $endedAt !== null ? new \DateTime($endedAt) : new \DateTime;

            $diff = $start->diff($end);

            // Format duration
            $parts = [];

            if ($diff->days > 0) {
                $parts[] = $diff->days.'d';
            }

            if ($diff->h > 0) {
                $parts[] = $diff->h.'h';
            }

            if ($diff->i > 0) {
                $parts[] = $diff->i.'m';
            }

            if ($diff->s > 0 || empty($parts)) {
                $parts[] = $diff->s.'s';
            }

            return implode(' ', $parts);
        } catch (\Exception $e) {
            return '';
        }
    }
}
