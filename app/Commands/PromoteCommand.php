<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\BacklogService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class PromoteCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'promote
        {ids* : The backlog ID(s) (b-xxx format, supports partial matching, accepts multiple IDs)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--priority= : Task priority (0-4)}
        {--type= : Task type (bug|fix|feature|task|epic|chore|docs|test|refactor)}
        {--complexity= : Task complexity (trivial|simple|moderate|complex)}
        {--labels= : Comma-separated list of labels}
        {--blocked-by= : Comma-separated task IDs this is blocked by}';

    protected $description = 'Promote one or more backlog items to tasks';

    public function handle(FuelContext $context, TaskService $taskService, BacklogService $backlogService, DatabaseService $dbService): int
    {
        // Configure context with --cwd if provided
        $this->configureCwd($context);

        // Reconfigure DatabaseService if context path changed
        if ($this->option('cwd')) {
            $dbService->setDatabasePath($context->getDatabasePath());
        }

        $ids = $this->argument('ids');
        $results = [];
        $errors = [];

        foreach ($ids as $id) {
            try {
                // Validate that ID is a backlog ID (b- prefix)
                $resolvedId = $id;
                if (! str_starts_with((string) $id, 'b-') && ! str_starts_with((string) $id, 'b')) {
                    // Try to find if it's a partial match that would resolve to b-xxx
                    $backlogItem = $backlogService->find($id);
                    if ($backlogItem === null || ! str_starts_with($backlogItem['id'] ?? '', 'b-')) {
                        $errors[] = ['id' => $id, 'error' => sprintf("ID '%s' is not a backlog item. Backlog items must have 'b-' prefix.", $id)];

                        continue;
                    }

                    // Use the resolved ID
                    $resolvedId = $backlogItem['id'];
                }

                // Find the backlog item
                $backlogItem = $backlogService->find($resolvedId);
                if ($backlogItem === null) {
                    $errors[] = ['id' => $id, 'error' => sprintf("Backlog item '%s' not found", $resolvedId)];

                    continue;
                }

                // Ensure we have the full ID with b- prefix
                $finalId = $backlogItem['id'];
                if (! str_starts_with((string) $finalId, 'b-')) {
                    $errors[] = ['id' => $id, 'error' => sprintf("Backlog item '%s' does not have 'b-' prefix", $finalId)];

                    continue;
                }

                // Delete from backlog
                $deletedItem = $backlogService->delete($finalId);

                // Prepare task data from backlog item
                $taskData = [
                    'title' => $deletedItem['title'] ?? throw new RuntimeException('Backlog item missing title'),
                    'description' => $deletedItem['description'] ?? null,
                ];

                // Add options if provided (use !== null to allow 0)
                if (($priority = $this->option('priority')) !== null) {
                    if (! is_numeric($priority)) {
                        $errors[] = ['id' => $id, 'error' => sprintf("Invalid priority '%s'. Must be an integer between 0 and 4.", $priority)];

                        continue;
                    }

                    $taskData['priority'] = (int) $priority;
                }

                if ($type = $this->option('type')) {
                    $taskData['type'] = $type;
                }

                if ($complexity = $this->option('complexity')) {
                    $taskData['complexity'] = $complexity;
                }

                if ($labels = $this->option('labels')) {
                    $taskData['labels'] = array_map(trim(...), explode(',', $labels));
                }

                if ($blockedBy = $this->option('blocked-by')) {
                    $taskData['blocked_by'] = array_map(trim(...), explode(',', $blockedBy));
                }

                // Create the task
                $taskService->initialize();
                $task = $taskService->create($taskData);
                $results[] = ['backlog_id' => $finalId, 'task' => $task];
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
                $backlogId = $result['backlog_id'];
                $task = $result['task'];
                $this->info(sprintf('Promoted backlog item %s to task: %s', $backlogId, $task->id));
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
                $this->outputError(sprintf("Backlog item '%s': %s", $error['id'], $error['error']));
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
