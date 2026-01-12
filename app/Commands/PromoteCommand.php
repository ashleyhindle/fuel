<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class PromoteCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'promote
        {ids* : The task ID(s) (f-xxx format, supports partial matching, accepts multiple IDs)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--priority= : Task priority (0-4)}
        {--type= : Task type (bug|fix|feature|task|epic|chore|docs|test|refactor)}
        {--complexity= : Task complexity (trivial|simple|moderate|complex)}
        {--labels= : Comma-separated list of labels}
        {--blocked-by= : Comma-separated task IDs this is blocked by}';

    protected $description = 'Promote one or more backlog items (status=someday) to active tasks';

    public function handle(FuelContext $context, TaskService $taskService, DatabaseService $dbService): int
    {
        // Configure context with --cwd if provided
        $this->configureCwd($context, $dbService);

        $taskService->initialize();

        $ids = $this->argument('ids');
        $results = [];
        $errors = [];

        foreach ($ids as $id) {
            try {
                // Promote the task (changes status from someday to open)
                $task = $taskService->promote($id);

                // Prepare additional updates from options
                $updates = [];

                // Add options if provided (use !== null to allow 0)
                if (($priority = $this->option('priority')) !== null) {
                    if (! is_numeric($priority)) {
                        $errors[] = ['id' => $id, 'error' => sprintf("Invalid priority '%s'. Must be an integer between 0 and 4.", $priority)];

                        continue;
                    }

                    $updates['priority'] = (int) $priority;
                }

                if ($type = $this->option('type')) {
                    $updates['type'] = $type;
                }

                if ($complexity = $this->option('complexity')) {
                    $updates['complexity'] = $complexity;
                }

                if ($labels = $this->option('labels')) {
                    $updates['add_labels'] = array_map(trim(...), explode(',', $labels));
                }

                if ($blockedBy = $this->option('blocked-by')) {
                    $updates['blocked_by'] = array_map(trim(...), explode(',', $blockedBy));
                }

                // Apply additional updates if any options were provided
                if ($updates !== []) {
                    $task = $taskService->update($task->short_id, $updates);
                }

                $results[] = ['task' => $task];
            } catch (RuntimeException $runtimeException) {
                $errors[] = ['id' => $id, 'error' => $runtimeException->getMessage()];
            }
        }

        if ($results === [] && $errors !== []) {
            // All failed
            return $this->outputError($errors[0]['error']);
        }

        if ($this->option('json')) {
            $tasks = array_map(fn (array $result): Task => $result['task'], $results);
            if (count($tasks) === 1) {
                // Single task - return object for backward compatibility
                $this->outputJson($tasks[0]->toArray());
            } else {
                // Multiple tasks - return array
                $this->outputJson(array_map(fn (Task $task): array => $task->toArray(), $tasks));
            }
        } else {
            foreach ($results as $result) {
                $task = $result['task'];
                $this->info(sprintf('Promoted task %s from backlog to active', $task->short_id));
                $this->line('  Title: '.$task->title);

                if (! empty($task->blocked_by)) {
                    $blockerIds = is_array($task->blocked_by) ? implode(', ', $task->blocked_by) : '';
                    if ($blockerIds !== '') {
                        $this->line('  Blocked by: '.$blockerIds);
                    }
                }
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
