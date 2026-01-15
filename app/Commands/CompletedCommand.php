<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\RunService;
use App\Services\TaskService;
use App\TUI\Table;
use LaravelZero\Framework\Commands\Command;

class CompletedCommand extends Command
{
    use Concerns\RendersBoardColumns;
    use HandlesJsonOutput;

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
            $this->outputJson($tasks->map(fn (Task $task): array => $task->toArray())->toArray());
        } else {
            if ($tasks->isEmpty()) {
                $this->info('No completed tasks found.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Completed tasks (%d):', $tasks->count()));

            $headers = ['ID', 'Title', 'Completed', 'Type', 'Priority', 'Agent', 'Commit'];

            // Calculate max title width based on terminal width
            // Terminal width - ID (10) - Completed (11) - Type (8) - Priority (10) - Agent (16) - Commit (10) - borders/padding (20)
            $terminalWidth = $this->getTerminalWidth();
            $fixedColumnsWidth = 10 + 11 + 8 + 10 + 16 + 10 + 20; // ID, Completed, Type, Priority, Agent, Commit columns + borders
            $maxTitleWidth = max(20, $terminalWidth - $fixedColumnsWidth); // Minimum 20 chars for title

            $rows = $tasks->map(function (Task $t) use ($runService, $maxTitleWidth): array {
                $latestRun = $runService->getLatestRun($t->short_id);
                $agent = $latestRun?->agent ?? '';

                return [
                    $t->short_id,
                    $this->truncate($t->title, $maxTitleWidth),
                    $this->formatDate($t->updated_at),
                    $t->type ?? 'task',
                    $t->priority ?? 2,
                    $agent,
                    $t->commit_hash ?? '',
                ];
            })->toArray();

            $table = new Table;
            $table->render($headers, $rows, $this->output);
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
