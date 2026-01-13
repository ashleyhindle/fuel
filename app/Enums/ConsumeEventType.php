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

    public static function fromString(string $value): self
    {
        return self::from($value);
    }
}
