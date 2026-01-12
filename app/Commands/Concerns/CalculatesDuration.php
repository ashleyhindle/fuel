<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use DateTimeInterface;

/**
 * Provides duration calculation between two timestamps.
 */
trait CalculatesDuration
{
    /**
     * Calculate duration between two timestamps.
     */
    protected function calculateDuration(?DateTimeInterface $startedAt, ?DateTimeInterface $endedAt): string
    {
        if (!$startedAt instanceof \DateTimeInterface) {
            return '';
        }

        try {
            $start = $startedAt;
            $end = $endedAt ?? new \DateTime;

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
        } catch (\Exception) {
            return '';
        }
    }
}
