<?php

declare(strict_types=1);

namespace App\Models;

class Run extends Model
{
    /**
     * Check if the run is currently running.
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if the run is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the run failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Format duration_seconds as '1h 23m 45s' or '23m 45s' or '45s'.
     */
    public function getDurationFormatted(): string
    {
        $seconds = $this->duration_seconds;
        if ($seconds === null) {
            return '';
        }

        $seconds = (int) $seconds;
        $parts = [];

        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;

        $minutes = intdiv($seconds, 60);
        $seconds %= 60;

        if ($hours > 0) {
            $parts[] = $hours.'h';
        }

        if ($minutes > 0) {
            $parts[] = $minutes.'m';
        }

        if ($seconds > 0 || $parts === []) {
            $parts[] = $seconds.'s';
        }

        return implode(' ', $parts);
    }

    /**
     * Split output into lines.
     *
     * @return array<int, string>
     */
    public function getOutputLines(): array
    {
        if ($this->output === null || $this->output === '') {
            return [];
        }

        return explode("\n", $this->output);
    }

    /**
     * Create a Run instance from an array.
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }
}
