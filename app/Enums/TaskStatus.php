<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
