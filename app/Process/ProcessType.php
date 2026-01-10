<?php

declare(strict_types=1);

namespace App\Process;

/**
 * Represents the type of a spawned process.
 */
enum ProcessType: string
{
    case Task = 'task';
    case Review = 'review';
}
