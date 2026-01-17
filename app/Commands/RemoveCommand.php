<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class RemoveCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'remove
        {ids* : The task ID(s) (f-xxx, supports partial matching, accepts multiple IDs)}
        {--json : Output as JSON}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $aliases = ['delete'];

    protected $description = 'Delete one or more tasks';

    public function handle(TaskService $taskService): int
    {
        $ids = $this->argument('ids');
        $tasks = [];
        $errors = [];

        foreach ($ids as $id) {
            try {
                // Find the task (supports partial ID matching)
                $task = $taskService->find($id);

                if (! $task instanceof Task) {
                    $errors[] = ['id' => $id, 'error' => sprintf("Task '%s' not found", $id)];

                    continue;
                }

                $resolvedId = $task->short_id;

                // Delete the task
                $deletedTask = $taskService->delete($resolvedId);
                $tasks[] = $deletedTask;
            } catch (RuntimeException $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        if ($tasks === [] && $errors !== []) {
            // All failed
            return $this->outputError($errors[0]['error']);
        }

        if ($this->option('json')) {
            if (count($tasks) === 1) {
                // Single task - return object for backward compatibility
                $this->outputJson([
                    'short_id' => $tasks[0]->short_id,
                    'deleted' => $tasks[0]->toArray(),
                ]);
            } else {
                // Multiple tasks - return array
                $this->outputJson(array_map(fn (Task $task): array => $task->toArray(), $tasks));
            }
        } else {
            foreach ($tasks as $task) {
                $this->info('Deleted task: '.$task->short_id);
                $this->line('  Title: '.$task->title);
            }
        }

        // If there were any errors, return failure even if some succeeded
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->outputError(sprintf("Task '%s': %s", $error['id'], $error['error']));
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
