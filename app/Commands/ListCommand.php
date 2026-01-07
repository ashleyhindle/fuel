<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class ListCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'list
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--status= : Filter by status (open|closed)}
        {--type= : Filter by type (bug|feature|task|epic|chore|test)}
        {--priority= : Filter by priority (0-4)}
        {--labels= : Filter by labels (comma-separated)}
        {--size= : Filter by size (xs|s|m|l|xl)}';

    protected $description = 'List tasks with optional filters';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        $tasks = $taskService->all();

        // Apply filters
        if ($status = $this->option('status')) {
            $tasks = $tasks->filter(fn (array $t): bool => ($t['status'] ?? '') === $status);
        }

        if ($type = $this->option('type')) {
            $tasks = $tasks->filter(fn (array $t): bool => ($t['type'] ?? 'task') === $type);
        }

        if ($priority = $this->option('priority')) {
            $priorityInt = (int) $priority;
            $tasks = $tasks->filter(fn (array $t): bool => ($t['priority'] ?? 2) === $priorityInt);
        }

        if ($labels = $this->option('labels')) {
            $filterLabels = array_map('trim', explode(',', $labels));
            $tasks = $tasks->filter(function (array $t) use ($filterLabels): bool {
                $taskLabels = $t['labels'] ?? [];

                // Task must have at least one of the filter labels
                return ! empty(array_intersect($filterLabels, $taskLabels));
            });
        }

        if ($size = $this->option('size')) {
            $tasks = $tasks->filter(fn (array $t): bool => ($t['size'] ?? 'm') === $size);
        }

        // Sort by created_at
        $tasks = $tasks->sortBy('created_at')->values();

        if ($this->option('json')) {
            $this->outputJson($tasks->toArray());
        } else {
            if ($tasks->isEmpty()) {
                $this->info('No tasks found.');

                return self::SUCCESS;
            }

            $this->info("Tasks ({$tasks->count()}):");
            $this->newLine();

            // Display all schema fields in a table
            $headers = ['ID', 'Title', 'Status', 'Type', 'Priority', 'Size', 'Labels', 'Created'];
            $rows = $tasks->map(function (array $t) {
                return [
                    $t['id'],
                    $t['title'],
                    $t['status'] ?? 'open',
                    $t['type'] ?? 'task',
                    $t['priority'] ?? 2,
                    $t['size'] ?? 'm',
                    isset($t['labels']) && ! empty($t['labels']) ? implode(', ', $t['labels']) : '',
                    $t['created_at'],
                ];
            })->toArray();

            $this->table($headers, $rows);
        }

        return self::SUCCESS;
    }
}
