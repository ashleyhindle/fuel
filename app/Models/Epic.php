<?php

declare(strict_types=1);

namespace App\Models;

class Epic extends Model
{
    /**
     * Create an Epic instance from an array of data.
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Check if the epic is open.
     */
    public function isOpen(): bool
    {
        return $this->attributes['status'] === 'open';
    }

    /**
     * Check if the epic is closed.
     */
    public function isClosed(): bool
    {
        return $this->attributes['status'] === 'closed';
    }

    /**
     * Check if the epic has been reviewed.
     */
    public function isReviewed(): bool
    {
        return ! empty($this->attributes['reviewed_at']);
    }

    /**
     * Check if the epic is in planning or open status.
     */
    public function isPlanningOrOpen(): bool
    {
        return in_array($this->attributes['status'], ['planning', 'open'], true);
    }
}
