<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class DoneCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'done
        {ids* : The task ID(s) (supports partial matching, accepts multiple IDs)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--reason= : Reason for completion}
        {--commit= : Git commit hash to associate with this completion}';

    protected $description = 'Mark one or more tasks as done';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        $ids = $this->argument('ids');
        $reason = $this->option('reason');
        $commit = $this->option('commit');
        $tasks = [];
        $errors = [];

        foreach ($ids as $id) {
            try {
                $task = $taskService->done($id, $reason, $commit);
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
                $this->info("Completed task: {$task['id']}");
                $this->line("  Title: {$task['title']}");
                if (isset($task['reason'])) {
                    $this->line("  Reason: {$task['reason']}");
                }
                if (isset($task['commit_hash'])) {
                    $this->line("  Commit: {$task['commit_hash']}");
                }
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
