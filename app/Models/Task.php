<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskStatus;

class Task extends Model
{
    /**
     * Create a Task instance from an array of data.
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Check if the task is blocked by other tasks.
     */
    public function isBlocked(): bool
    {
        return ! empty($this->attributes['blocked_by']);
    }

    /**
     * Check if the task is completed.
     */
    public function isCompleted(): bool
    {
        return $this->attributes['status'] === 'completed';
    }

    /**
     * Check if the task is in progress.
     */
    public function isInProgress(): bool
    {
        return $this->attributes['status'] === TaskStatus::InProgress->value;
    }

    /**
     * Check if the task is failed.
     */
    public function isFailed(): bool
    {
        return $this->attributes['status'] === 'failed';
    }

    /**
     * Check if the task is pending.
     */
    public function isPending(): bool
    {
        return $this->attributes['status'] === 'pending';
    }

    /**
     * Get labels as an array.
     */
    public function getLabelsArray(): array
    {
        $labels = $this->attributes['labels'] ?? '';

        if (empty($labels)) {
            return [];
        }

        return array_map(trim(...), explode(',', $labels));
    }

    /**
     * Get blocked_by task IDs as an array.
     */
    public function getBlockedByArray(): array
    {
        $blockedBy = $this->attributes['blocked_by'] ?? '';

        if (empty($blockedBy)) {
            return [];
        }

        return array_map(trim(...), explode(',', (string) $blockedBy));
    }
}
