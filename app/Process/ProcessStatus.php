<?php

namespace App\Process;

enum ProcessStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Killed = 'killed';
}
