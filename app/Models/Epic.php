<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EpicStatus;

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
     * Check if the epic is in planning status.
     */
    public function isPlanning(): bool
    {
        return $this->attributes['status'] === EpicStatus::Planning->value;
    }

    /**
     * Check if the epic is approved (terminal state).
     */
    public function isApproved(): bool
    {
        return $this->attributes['status'] === EpicStatus::Approved->value;
    }

    /**
     * Check if the epic has been reviewed.
     */
    public function isReviewed(): bool
    {
        return ! empty($this->attributes['reviewed_at']);
    }

    /**
     * Check if the epic is in planning or in_progress status.
     */
    public function isPlanningOrInProgress(): bool
    {
        return in_array($this->attributes['status'], [
            EpicStatus::Planning->value,
            EpicStatus::InProgress->value,
        ], true);
    }
}
