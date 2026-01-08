<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\BacklogService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class DeferCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'defer
        {id : The task ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Move a task to the backlog';

    public function handle(TaskService $taskService, BacklogService $backlogService): int
    {
        $this->configureCwd($taskService);

        // Also configure backlog service with same cwd if provided
        if ($cwd = $this->option('cwd')) {
            $backlogService->setStoragePath($cwd.'/.fuel/backlog.jsonl');
        }

        $id = $this->argument('id');

        try {
            // Find the task first to validate it exists and is a task
            $task = $taskService->find($id);

            if ($task === null) {
                return $this->outputError("Task '{$id}' not found");
            }

            // Validate that the resolved task ID starts with 'f-' (is a task, not backlog item)
            $resolvedId = $task['id'] ?? '';
            if (! str_starts_with($resolvedId, 'f-')) {
                return $this->outputError("ID '{$id}' is not a task (must have f- prefix)");
            }

            // Get task data before deletion
            $title = $task['title'] ?? '';
            $description = $task['description'] ?? null;

            // Delete from tasks
            $taskService->delete($resolvedId);

            // Add to backlog
            $backlogItem = $backlogService->add($title, $description);

            if ($this->option('json')) {
                $this->outputJson([
                    'task_id' => $resolvedId,
                    'backlog_item' => $backlogItem,
                ]);
            } else {
                $this->info("Deferred task: {$resolvedId}");
                $this->line("  Title: {$title}");
                $this->line("  Added to backlog: {$backlogItem['id']}");
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        }
    }
}
