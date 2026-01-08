<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\BacklogService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class RemoveCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'remove
        {id : The task or backlog ID (f-xxx or b-xxx, supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--force : Skip confirmation prompt}';

    protected $description = 'Delete a task or backlog item';

    public function handle(TaskService $taskService, BacklogService $backlogService): int
    {
        $this->configureCwd($taskService);

        // Also configure backlog service with same cwd if provided
        if ($cwd = $this->option('cwd')) {
            $backlogService->setStoragePath($cwd.'/.fuel/backlog.jsonl');
        }

        $id = $this->argument('id');

        try {
            // Determine which service to use based on ID prefix
            $hasBacklogPrefix = str_starts_with($id, 'b-');
            $hasTaskPrefix = str_starts_with($id, 'f-');

            // Initialize both services
            $taskService->initialize();
            $backlogService->initialize();

            // If ID has explicit prefix, use that service
            if ($hasBacklogPrefix) {
                $item = $backlogService->find($id);

                if ($item === null) {
                    return $this->outputError("Backlog item '{$id}' not found");
                }

                $resolvedId = $item['id'];
                $title = $item['title'] ?? '';

                // Confirm deletion unless --force is set
                if (! $this->option('force') && ! $this->option('json')) {
                    if (! $this->confirm("Are you sure you want to delete backlog item '{$resolvedId}' ({$title})?")) {
                        $this->line('Deletion cancelled.');

                        return self::SUCCESS;
                    }
                }

                // Delete from backlog
                $deletedItem = $backlogService->delete($resolvedId);

                if ($this->option('json')) {
                    $this->outputJson([
                        'id' => $resolvedId,
                        'type' => 'backlog',
                        'deleted' => $deletedItem,
                    ]);
                } else {
                    $this->info("Deleted backlog item: {$resolvedId}");
                    $this->line("  Title: {$title}");
                }

                return self::SUCCESS;
            }

            if ($hasTaskPrefix) {
                $task = $taskService->find($id);

                if ($task === null) {
                    return $this->outputError("Task '{$id}' not found");
                }

                $resolvedId = $task['id'];
                $title = $task['title'] ?? '';

                // Validate that the resolved task ID starts with 'f-' (is a task, not backlog item)
                if (! str_starts_with($resolvedId, 'f-')) {
                    return $this->outputError("ID '{$id}' is not a task (must have f- prefix)");
                }

                // Confirm deletion unless --force is set
                if (! $this->option('force') && ! $this->option('json')) {
                    if (! $this->confirm("Are you sure you want to delete task '{$resolvedId}' ({$title})?")) {
                        $this->line('Deletion cancelled.');

                        return self::SUCCESS;
                    }
                }

                // Delete from tasks
                $deletedTask = $taskService->delete($resolvedId);

                if ($this->option('json')) {
                    $this->outputJson([
                        'id' => $resolvedId,
                        'type' => 'task',
                        'deleted' => $deletedTask,
                    ]);
                } else {
                    $this->info("Deleted task: {$resolvedId}");
                    $this->line("  Title: {$title}");
                }

                return self::SUCCESS;
            }

            // No explicit prefix - try both services (partial ID matching)
            $task = $taskService->find($id);
            $backlogItem = $backlogService->find($id);

            // Check for ambiguous matches
            if ($task !== null && $backlogItem !== null) {
                return $this->outputError("ID '{$id}' is ambiguous. Matches both task '{$task['id']}' and backlog item '{$backlogItem['id']}'. Use full ID with prefix.");
            }

            if ($backlogItem !== null) {
                $resolvedId = $backlogItem['id'];
                $title = $backlogItem['title'] ?? '';

                // Confirm deletion unless --force is set
                if (! $this->option('force') && ! $this->option('json')) {
                    if (! $this->confirm("Are you sure you want to delete backlog item '{$resolvedId}' ({$title})?")) {
                        $this->line('Deletion cancelled.');

                        return self::SUCCESS;
                    }
                }

                // Delete from backlog
                $deletedItem = $backlogService->delete($resolvedId);

                if ($this->option('json')) {
                    $this->outputJson([
                        'id' => $resolvedId,
                        'type' => 'backlog',
                        'deleted' => $deletedItem,
                    ]);
                } else {
                    $this->info("Deleted backlog item: {$resolvedId}");
                    $this->line("  Title: {$title}");
                }

                return self::SUCCESS;
            }

            if ($task !== null) {
                $resolvedId = $task['id'];
                $title = $task['title'] ?? '';

                // Validate that the resolved task ID starts with 'f-' (is a task, not backlog item)
                if (! str_starts_with($resolvedId, 'f-')) {
                    return $this->outputError("ID '{$id}' is not a task (must have f- prefix)");
                }

                // Confirm deletion unless --force is set
                if (! $this->option('force') && ! $this->option('json')) {
                    if (! $this->confirm("Are you sure you want to delete task '{$resolvedId}' ({$title})?")) {
                        $this->line('Deletion cancelled.');

                        return self::SUCCESS;
                    }
                }

                // Delete from tasks
                $deletedTask = $taskService->delete($resolvedId);

                if ($this->option('json')) {
                    $this->outputJson([
                        'id' => $resolvedId,
                        'type' => 'task',
                        'deleted' => $deletedTask,
                    ]);
                } else {
                    $this->info("Deleted task: {$resolvedId}");
                    $this->line("  Title: {$title}");
                }

                return self::SUCCESS;
            }

            // Not found in either service
            return $this->outputError("Task or backlog item '{$id}' not found");

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        }
    }
}
