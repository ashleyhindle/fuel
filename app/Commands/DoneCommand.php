<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class DoneCommand extends Command
{
    protected $signature = 'done
        {ids* : The task ID(s) (supports partial matching, accepts multiple IDs)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--reason= : Reason for completion}';

    protected $description = 'Mark one or more tasks as done';

    public function handle(TaskService $taskService): int
    {
        if ($cwd = $this->option('cwd')) {
            $taskService->setStoragePath($cwd.'/.fuel/tasks.jsonl');
        }

        $ids = $this->argument('ids');
        $reason = $this->option('reason');
        $tasks = [];
        $errors = [];

        foreach ($ids as $id) {
            try {
                $task = $taskService->done($id, $reason);
                $tasks[] = $task;
            } catch (RuntimeException $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        if (empty($tasks) && ! empty($errors)) {
            // All failed
            if ($this->option('json')) {
                $this->line(json_encode(['error' => $errors[0]['error']], JSON_PRETTY_PRINT));
            } else {
                $this->error($errors[0]['error']);
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            if (count($tasks) === 1) {
                // Single task - return object for backward compatibility
                $this->line(json_encode($tasks[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                // Multiple tasks - return array
                $this->line(json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        } else {
            foreach ($tasks as $task) {
                $this->info("Completed task: {$task['id']}");
                $this->line("  Title: {$task['title']}");
                if (isset($task['reason'])) {
                    $this->line("  Reason: {$task['reason']}");
                }
            }
        }

        // If there were any errors, return failure even if some succeeded
        if (! empty($errors)) {
            foreach ($errors as $error) {
                if ($this->option('json')) {
                    $this->line(json_encode(['error' => "Task '{$error['id']}': {$error['error']}"], JSON_PRETTY_PRINT));
                } else {
                    $this->error("Task '{$error['id']}': {$error['error']}");
                }
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
