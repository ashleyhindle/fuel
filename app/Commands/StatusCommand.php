<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'status
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Show task statistics overview';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        $tasks = $taskService->all();

        // Group by status
        $open = $tasks->filter(fn (Task $t): bool => ($t->status ?? '') === TaskStatus::Open->value);
        $inProgress = $tasks->filter(fn (Task $t): bool => ($t->status ?? '') === TaskStatus::InProgress->value);
        $review = $tasks->filter(fn (Task $t): bool => ($t->status ?? '') === TaskStatus::Review->value);
        $closed = $tasks->filter(fn (Task $t): bool => ($t->status ?? '') === TaskStatus::Closed->value);

        // Calculate blocked count (open tasks with open blockers)
        $taskMap = $tasks->keyBy('id');
        $blockedCount = 0;
        foreach ($open as $task) {
            $blockedBy = $task->blocked_by ?? [];
            foreach ($blockedBy as $blockerId) {
                if (is_string($blockerId)) {
                    $blocker = $taskMap->get($blockerId);
                    // Task is blocked if the blocker exists and is not closed
                    if ($blocker !== null && ($blocker->status ?? '') !== TaskStatus::Closed->value) {
                        $blockedCount++;
                        break; // No need to check other blockers
                    }
                }
            }
        }

        $stats = [
            'open' => $open->count(),
            'in_progress' => $inProgress->count(),
            'review' => $review->count(),
            'closed' => $closed->count(),
            'blocked' => $blockedCount,
            'total' => $tasks->count(),
        ];

        if ($this->option('json')) {
            $this->outputJson($stats);
        } else {
            $this->info('Task Statistics:');
            $this->newLine();
            $this->table(
                ['Status', 'Count'],
                [
                    ['Open', $stats['open']],
                    ['In Progress', $stats['in_progress']],
                    ['Review', $stats['review']],
                    ['Closed', $stats['closed']],
                    ['Blocked', $stats['blocked']],
                    ['Total', $stats['total']],
                ]
            );
        }

        return self::SUCCESS;
    }
}
