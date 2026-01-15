<?php

declare(strict_types=1);

namespace App\Enums;

enum ConsumeEventType: string
{
    case Hello = 'hello';
    case Snapshot = 'snapshot';
    case StatusLine = 'status_line';
    case TaskSpawned = 'task_spawned';
    case TaskCompleted = 'task_completed';
    case HealthChange = 'health_change';
    case OutputChunk = 'output_chunk';
    case Error = 'error';
    case ReviewCompleted = 'review_completed';
    case TaskCreateResponse = 'task_create_response';
    case BrowserResponse = 'browser_response';
    case ConfigReloaded = 'config_reloaded';

    // Lazy-loaded data responses
    case DoneTasks = 'done_tasks';
    case BlockedTasks = 'blocked_tasks';
    case CompletedTasks = 'completed_tasks';

    public static function fromString(string $value): self
    {
        return self::from($value);
    }
}
