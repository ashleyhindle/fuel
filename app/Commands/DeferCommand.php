<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\BacklogService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
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

    public function handle(FuelContext $context, TaskService $taskService, BacklogService $backlogService, DatabaseService $dbService): int
    {
        // Configure context with --cwd if provided
        $this->configureCwd($context);

        // Reconfigure DatabaseService if context path changed
        if ($this->option('cwd')) {
            $dbService->setDatabasePath($context->getDatabasePath());
        }

        $id = $this->argument('id');

        try {
            // Find the task first to validate it exists and is a task
            $task = $taskService->find($id);

            if ($task === null) {
                return $this->outputError(sprintf("Task '%s' not found", $id));
            }

            // Validate that the resolved task ID starts with 'f-' (is a task, not backlog item)
            $resolvedId = $task->id ?? '';
            if (! str_starts_with($resolvedId, 'f-')) {
                return $this->outputError(sprintf("ID '%s' is not a task (must have f- prefix)", $id));
            }

            // Get task data before deletion
            $title = $task->title ?? '';
            $description = $task->description ?? null;

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
                $this->info('Deferred task: '.$resolvedId);
                $this->line('  Title: '.$title);
                $this->line('  Added to backlog: '.$backlogItem['id']);
            }

            return self::SUCCESS;
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }
    }
}
