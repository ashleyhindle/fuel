<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class TasksCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'tasks
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--status= : Filter by status (open|closed)}
        {--type= : Filter by type (bug|fix|feature|task|epic|chore|docs|test|refactor)}
        {--priority= : Filter by priority (0-4)}
        {--labels= : Filter by labels (comma-separated)}';

    protected $description = 'List tasks with optional filters';

    public function handle(FuelContext $context, TaskService $taskService, DatabaseService $dbService): int
    {
        $this->configureCwd($context);

        if ($this->option('cwd')) {
            $dbService->setDatabasePath($context->getDatabasePath());
        }

        $tasks = $taskService->all();

        // Apply filters
        if ($status = $this->option('status')) {
            $tasks = $tasks->filter(fn (Task $t): bool => ($t->status ?? '') === $status);
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

        // Sort by created_at
        $tasks = $tasks->sortBy('created_at')->values();

        if ($this->option('json')) {
            $this->outputJson($tasks->map(fn (Task $t): array => $t->toArray())->toArray());
        } else {
            if ($tasks->isEmpty()) {
                $this->info('No tasks found.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Tasks (%d):', $tasks->count()));
            $this->newLine();

            // Display all schema fields in a table
            $headers = ['ID', 'Title', 'Status', 'Type', 'Priority', 'Labels', 'Created'];
            $rows = $tasks->map(fn (Task $t): array => [
                $t->id,
                $t->title,
                $t->status ?? TaskStatus::Open->value,
                $t->type ?? 'task',
                $t->priority ?? 2,
                isset($t->labels) && ! empty($t->labels) && is_array($t->labels) ? implode(', ', $t->labels) : '',
                $t->created_at,
            ])->toArray();

            $this->table($headers, $rows);
        }

        return self::SUCCESS;
    }
}
