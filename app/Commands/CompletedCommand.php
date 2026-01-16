<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Commands\Concerns\RendersBoardColumns;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\RunService;
use App\Services\TaskService;
use App\TUI\Table;
use LaravelZero\Framework\Commands\Command;

class CompletedCommand extends Command
{
    use HandlesJsonOutput;
    use RendersBoardColumns;

    protected $signature = 'completed
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--selfguided : Filter tasks that went through selfguided loop}
        {--limit=15 : Number of completed tasks to show}';

    protected $description = 'Show a list of recently completed tasks';

    public function handle(TaskService $taskService, RunService $runService): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1) {
            $limit = 15;
        }

        $tasks = $taskService->all()
            ->filter(fn (Task $t): bool => $t->status === TaskStatus::Done);

        if ($this->option('selfguided')) {
            $tasks = $tasks->filter(fn (Task $t): bool => ($t->selfguided_iteration ?? 0) > 0);
        }

        $tasks = $tasks->sortByDesc('updated_at')
            ->take($limit)
            ->values();

        if ($this->option('json')) {
            $this->outputJson($tasks->map(function (Task $task): array {
                $data = $task->toArray();
                $data['type'] = $task->type ?? 'task';

                return $data;
            })->toArray());
        } else {
            if ($tasks->isEmpty()) {
                $this->info('No completed tasks found.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Completed tasks (%d):', $tasks->count()));

            $headers = ['ID', 'Title', 'Completed', 'Type', 'Priority', 'Agent', 'Commit'];

            // Column priorities: all columns are important
            $columnPriorities = [];

            $rows = $tasks->map(function (Task $t) use ($runService): array {
                $latestRun = $runService->getLatestRun($t->short_id);
                $agent = $latestRun?->agent ?? '';

                // Truncate commit hash to 7 chars
                $commitHash = $t->commit_hash ?? '';
                if (strlen($commitHash) > 7) {
                    $commitHash = substr($commitHash, 0, 7);
                }

                return [
                    $t->short_id,
                    $t->title,
                    $this->formatDate($t->updated_at),
                    $t->type ?? 'task',
                    $t->priority ?? 2,
                    $agent,
                    $commitHash,
                ];
            })->toArray();

            $table = new Table;
            $table->render($headers, $rows, $this->output, $columnPriorities);
        }

        return self::SUCCESS;
    }

    /**
     * Format a date string into a human-readable format.
     */
    private function formatDate(\DateTimeInterface $date): string
    {
        try {
            $now = new \DateTime;
            $diff = $now->diff($date);

            // If less than 1 minute ago
            if ($diff->days === 0 && $diff->h === 0 && $diff->i === 0) {
                return 'just now';
            }

            // If less than 1 hour ago
            if ($diff->days === 0 && $diff->h === 0) {
                $minutes = $diff->i;

                return $minutes.'m ago';
            }

            // If less than 24 hours ago
            if ($diff->days === 0) {
                $hours = $diff->h;

                return $hours.'h ago';
            }

            // If less than 7 days ago
            if ($diff->days < 7) {
                $days = $diff->days;

                return $days.'d ago';
            }

            // If same year, show "Mon Day" (e.g., "Jan 7")
            if ($date->format('Y') === $now->format('Y')) {
                return $date->format('M j');
            }

            // Different year, show "Mon Day, Year" (e.g., "Jan 7, 2025")
            return $date->format('M j, Y');
        } catch (\Exception) {
            // Fallback to original if parsing fails
            return $date->format('Y-m-d H:i:s');
        }
    }
}
