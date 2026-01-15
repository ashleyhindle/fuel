<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Done = 'done';
    case Cancelled = 'cancelled';
    case Someday = 'someday';
    case Paused = 'paused';
}
