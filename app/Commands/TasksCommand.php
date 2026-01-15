<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\TaskService;
use App\TUI\Table;
use LaravelZero\Framework\Commands\Command;

class TasksCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'tasks
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--status= : Filter by status (open|done)}
        {--type= : Filter by type (bug|fix|feature|task|epic|chore|docs|test|refactor)}
        {--priority= : Filter by priority (0-4)}
        {--labels= : Filter by labels (comma-separated)}
        {--selfguided : Filter tasks that went through selfguided loop}
        {--limit= : Limit number of results}';

    protected $description = 'List tasks with optional filters';

    public function handle(TaskService $taskService): int
    {
        $tasks = $taskService->all();

        // Apply filters
        if ($status = $this->option('status')) {
            $tasks = $tasks->filter(fn (Task $t): bool => $t->status->value === $status);
        }

        if ($type = $this->option('type')) {
            $tasks = $tasks->filter(fn (Task $t): bool => ($t->type ?? 'task') === $type);
        }

        if ($priority = $this->option('priority')) {
            $priorityInt = (int) $priority;
            $tasks = $tasks->filter(fn (Task $t): bool => ($t->priority ?? 2) === $priorityInt);
        }

        if ($labels = $this->option('labels')) {
            $filterLabels = array_map(trim(...), explode(',', $labels));
            $tasks = $tasks->filter(function (Task $t) use ($filterLabels): bool {
                $taskLabels = $t->labels ?? [];
                if (! is_array($taskLabels)) {
                    $taskLabels = [];
                }

                // Task must have at least one of the filter labels
                return array_intersect($filterLabels, $taskLabels) !== [];
            });
        }

        if ($this->option('selfguided')) {
            $tasks = $tasks->filter(fn (Task $t): bool => ($t->selfguided_iteration ?? 0) > 0);
        }

        // Sort by created_at
        $tasks = $tasks->sortBy('created_at')->values();

        // Apply limit
        if ($limit = $this->option('limit')) {
            $tasks = $tasks->take((int) $limit);
        }

        if ($this->option('json')) {
            $this->outputJson($tasks->map(fn (Task $t): array => $t->toArray())->toArray());
        } else {
            if ($tasks->isEmpty()) {
                $this->info('No tasks found.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Tasks (%d):', $tasks->count()));
            $this->newLine();

            $table = new Table;
            $table->render(
                ['ID', 'Title', 'Status', 'Type', 'Priority', 'Labels', 'Created'],
                $tasks->map(fn (Task $t): array => [
                    $t->short_id,
                    $t->title,
                    $t->status->value,
                    $t->type ?? 'task',
                    $t->priority ?? 2,
                    isset($t->labels) && ! empty($t->labels) && is_array($t->labels) ? implode(', ', $t->labels) : '-',
                    $this->formatDate((string) $t->created_at),
                ])->toArray(),
                $this->output
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
