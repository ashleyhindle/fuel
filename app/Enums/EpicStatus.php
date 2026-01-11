<?php

declare(strict_types=1);

namespace App\Enums;

enum EpicStatus: string
{
    case Planning = 'planning';
    case InProgress = 'in_progress';
    case ReviewPending = 'review_pending';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';

    /**
     * Check if this status is terminal (epic is complete).
     * Only Approved is considered terminal.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Approved => true,
            default => false,
        };
    }

    /**
     * Get human-readable label for this status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Planning',
            self::InProgress => 'In Progress',
            self::ReviewPending => 'Review Pending',
            self::Reviewed => 'Reviewed',
            self::Approved => 'Approved',
            self::ChangesRequested => 'Changes Requested',
        };
    }
}
