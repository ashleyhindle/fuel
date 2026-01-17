<?php

declare(strict_types=1);

namespace App\Enums;

enum MirrorStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Creating = 'creating';
    case Ready = 'ready';
    case Merging = 'merging';
    case MergeFailed = 'merge_failed';
    case Merged = 'merged';
    case Cleaned = 'cleaned';

    /**
     * Check if this mirror status allows work to be done.
     * Only Ready mirrors are workable.
     */
    public function isWorkable(): bool
    {
        return match ($this) {
            self::Ready => true,
            default => false,
        };
    }

    /**
     * Check if this mirror status requires human attention.
     */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::MergeFailed => true,
            default => false,
        };
    }

    /**
     * Get human-readable label for this status.
     */
    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Pending => 'Pending',
            self::Creating => 'Creating',
            self::Ready => 'Ready',
            self::Merging => 'Merging',
            self::MergeFailed => 'Merge Failed',
            self::Merged => 'Merged',
            self::Cleaned => 'Cleaned',
        };
    }
}
