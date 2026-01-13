<?php

declare(strict_types=1);

namespace App\Enums;

enum ConsumeCommandType: string
{
    case Attach = 'attach';
    case Detach = 'detach';
    case Pause = 'pause';
    case Resume = 'resume';
    case Stop = 'stop';
    case ReloadConfig = 'reload_config';
    case SetInterval = 'set_interval';
    case RequestSnapshot = 'request_snapshot';
    case SetTaskReviewEnabled = 'set_task_review_enabled';

    // Task mutation commands
    case TaskStart = 'task_start';
    case TaskReopen = 'task_reopen';
    case TaskDone = 'task_done';
    case TaskCreate = 'task_create';
    case DependencyAdd = 'dependency_add';

    public static function fromString(string $value): self
    {
        return self::from($value);
    }
}
