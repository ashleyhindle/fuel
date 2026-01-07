<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class RetryCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'retry
        {ids* : The task ID(s) (supports partial matching, accepts multiple IDs)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Retry stuck tasks (consumed=true with non-zero exit code) by moving them back to open status';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        $ids = $this->argument('ids');
        $tasks = [];
        $errors = [];

        foreach ($ids as $id) {
            try {
                $task = $taskService->retry($id);
                $tasks[] = $task;
            } catch (RuntimeException $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        if (empty($tasks) && ! empty($errors)) {
            // All failed
            return $this->outputError($errors[0]['error']);
        }

        if ($this->option('json')) {
            if (count($tasks) === 1) {
                // Single task - return object for backward compatibility
                $this->outputJson($tasks[0]);
            } else {
                // Multiple tasks - return array
                $this->outputJson($tasks);
            }
        } else {
            foreach ($tasks as $task) {
                $this->info("Retried task: {$task['id']}");
                $this->line("  Title: {$task['title']}");
            }
        }

        // If there were any errors, return failure even if some succeeded
        if (! empty($errors)) {
            foreach ($errors as $error) {
                $this->outputError("Task '{$error['id']}': {$error['error']}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
