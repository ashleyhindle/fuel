<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\TaskService;
use App\TUI\Table;
use LaravelZero\Framework\Commands\Command;

class BlockedCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'blocked
        {--json : Output as JSON}';

    protected $description = 'Show open tasks with unresolved dependencies';

    public function handle(TaskService $taskService): int
    {
        $tasks = $taskService->blocked();

        if ($this->option('json')) {
            $this->outputJson($tasks->values()->map(fn (Task $task): array => $task->toArray())->toArray());
        } else {
            if ($tasks->isEmpty()) {
                $this->info('No blocked tasks.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Blocked tasks (%d):', $tasks->count()));
            $this->newLine();

            $table = new Table;

            // Column priorities: lower = more important, higher gets dropped first
            $columnPriorities = [
                1,  // ID - keep
                1,  // Title - keep
                2,  // Blocked By - keep
                3,  // Created - drop first
            ];

            $table->render(
                ['ID', 'Title', 'Blocked By', 'Created'],
                $tasks->map(function (Task $t): array {
                    // Get first line of title, then truncate if needed
                    $title = strtok($t->title, "\r\n") ?: $t->title;
                    if (mb_strlen($title) > 50) {
                        $title = mb_substr($title, 0, 47).'...';
                    }

                    // Get blocked_by IDs
                    $blockedBy = $t->blocked_by ?? [];
                    $blockedByStr = is_array($blockedBy) ? implode(', ', $blockedBy) : '-';

                    return [
                        $t->short_id,
                        $title,
                        $blockedByStr,
                        $this->formatDate((string) $t->created_at),
                    ];
                })->toArray(),
                $this->output,
                $columnPriorities
            );
        }

        return self::SUCCESS;
    }

    private function formatDate(string $dateString): string
    {
        try {
            $date = new \DateTime($dateString);
            $now = new \DateTime;
            $diff = $now->diff($date);

            if ($diff->days === 0 && $diff->h === 0 && $diff->i === 0) {
                return 'just now';
            }

            if ($diff->days === 0 && $diff->h === 0) {
                return $diff->i.'m ago';
            }

            if ($diff->days === 0) {
                return $diff->h.'h ago';
            }

            if ($diff->days < 7) {
                return $diff->days.'d ago';
            }

            if ($date->format('Y') === $now->format('Y')) {
                return $date->format('M j');
            }

            return $date->format('M j, Y');
        } catch (\Exception) {
            return $dateString;
        }
    }
}
