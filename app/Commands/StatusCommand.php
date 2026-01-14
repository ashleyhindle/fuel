<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\TaskService;
use App\TUI\Table;
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
        $tasks = $taskService->all();

        // Group by status
        $open = $tasks->filter(fn (Task $t): bool => $t->status === TaskStatus::Open);
        $inProgress = $tasks->filter(fn (Task $t): bool => $t->status === TaskStatus::InProgress);
        $review = $tasks->filter(fn (Task $t): bool => $t->status === TaskStatus::Review);
        $done = $tasks->filter(fn (Task $t): bool => $t->status === TaskStatus::Done);

        // Calculate blocked count (open tasks with open blockers)
        $taskMap = $tasks->keyBy('short_id');
        $blockedCount = 0;
        foreach ($open as $task) {
            $blockedBy = $task->blocked_by ?? [];
            foreach ($blockedBy as $blockerId) {
                if (is_string($blockerId)) {
                    $blocker = $taskMap->get($blockerId);
                    // Task is blocked if the blocker exists and is not done
                    if ($blocker !== null && $blocker->status !== TaskStatus::Done) {
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
            'done' => $done->count(),
            'blocked' => $blockedCount,
            'total' => $tasks->count(),
        ];

        if ($this->option('json')) {
            $this->outputJson($stats);
        } else {
            $this->line('<fg=white;options=bold>Task Statistics</>');
            $this->newLine();

            $table = new Table;
            $table->render(
                ['Status', 'Count'],
                [
                    ['Open', (string) $stats['open']],
                    ['In Progress', (string) $stats['in_progress']],
                    ['Review', (string) $stats['review']],
                    ['Done', (string) $stats['done']],
                    ['Blocked', (string) $stats['blocked']],
                    ['Total', (string) $stats['total']],
                ],
                $this->output
            );
        }

        return self::SUCCESS;
    }
}
