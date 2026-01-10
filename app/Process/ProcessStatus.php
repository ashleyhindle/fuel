<?php

declare(strict_types=1);

namespace App\Process;

enum ProcessStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Killed = 'killed';
}
