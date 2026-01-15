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
    case RequestSnapshot = 'request_snapshot';
    case SetTaskReviewEnabled = 'set_task_review_enabled';

    // Task mutation commands
    case TaskStart = 'task_start';
    case TaskReopen = 'task_reopen';
    case TaskDone = 'task_done';
    case TaskCreate = 'task_create';
    case DependencyAdd = 'dependency_add';

    // Lazy-loaded data requests
    case RequestDoneTasks = 'request_done_tasks';
    case RequestBlockedTasks = 'request_blocked_tasks';
    case RequestCompletedTasks = 'request_completed_tasks';

    // Browser automation commands
    case BrowserCreate = 'browser_create';
    case BrowserPage = 'browser_page';
    case BrowserGoto = 'browser_goto';
    case BrowserRun = 'browser_run';
    case BrowserScreenshot = 'browser_screenshot';
    case BrowserClose = 'browser_close';
    case BrowserStatus = 'browser_status';

    public static function fromString(string $value): self
    {
        return self::from($value);
    }
}
