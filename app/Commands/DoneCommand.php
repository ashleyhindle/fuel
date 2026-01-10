<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\EpicService;
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

    public function handle(TaskService $taskService, EpicService $epicService): int
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

        if ($tasks === [] && $errors !== []) {
            // All failed
            return $this->outputError($errors[0]['error']);
        }

        $epicCompletions = [];
        foreach ($tasks as $task) {
            $epicId = $task['epic_id'] ?? null;
            if ($epicId !== null && is_string($epicId) && ! isset($epicCompletions[$epicId])) {
                $result = $epicService->checkEpicCompletion($epicId);
                if ($result['completed']) {
                    $epicCompletions[$epicId] = $result;
                }
            }
        }

        if ($this->option('json')) {
            if (count($tasks) === 1) {
                // Single task - return object for backward compatibility
                $output = $tasks[0];
                if (! empty($epicCompletions)) {
                    $output['epic_completions'] = array_values($epicCompletions);
                }
                $this->outputJson($output);
            } else {
                // Multiple tasks - return array with epic completions
                $output = ['tasks' => $tasks];
                if (! empty($epicCompletions)) {
                    $output['epic_completions'] = array_values($epicCompletions);
                }
                $this->outputJson($output);
            }
        } else {
            foreach ($tasks as $task) {
                $this->info('Completed task: '.$task['id']);
                $this->line('  Title: '.$task['title']);
                if (isset($task['reason'])) {
                    $this->line('  Reason: '.$task['reason']);
                }

                if (isset($task['commit_hash'])) {
                    $this->line('  Commit: '.$task['commit_hash']);
                }
            }

            foreach ($epicCompletions as $epicId => $completion) {
                $this->newLine();
                $this->info("Epic {$epicId} completed! Review task created: {$completion['review_task_id']}");
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
