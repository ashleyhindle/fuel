<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class ReopenCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'reopen
        {ids* : The task ID(s) (supports partial matching, accepts multiple IDs)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Reopen one or more closed or in_progress tasks (set status back to open)';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        $ids = $this->argument('ids');
        $tasks = [];
        $errors = [];

        foreach ($ids as $id) {
            try {
                $task = $taskService->reopen($id);
                $tasks[] = $task;
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
                $this->outputJson($tasks[0]->toArray());
            } else {
                // Multiple tasks - return array
                $this->outputJson(array_map(fn ($task) => $task->toArray(), $tasks));
            }
        } else {
            foreach ($tasks as $task) {
                $this->info('Reopened task: '.$task->id);
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
