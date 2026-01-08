<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\BacklogService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class PromoteCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'promote
        {id : The backlog ID (b-xxx format)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--priority= : Task priority (0-4)}
        {--type= : Task type (bug|feature|task|epic|chore|test)}
        {--complexity= : Task complexity (trivial|simple|moderate|complex)}
        {--labels= : Comma-separated list of labels}
        {--size= : Task size (xs|s|m|l|xl)}
        {--blocked-by= : Comma-separated task IDs this is blocked by}';

    protected $description = 'Promote a backlog item to a task';

    public function handle(TaskService $taskService, BacklogService $backlogService): int
    {
        $this->configureCwd($taskService);
        $this->configureBacklogCwd($backlogService);

        $id = $this->argument('id');

        // Validate that ID is a backlog ID (b- prefix)
        if (! str_starts_with($id, 'b-') && ! str_starts_with($id, 'b')) {
            // Try to find if it's a partial match that would resolve to b-xxx
            $backlogItem = $backlogService->find($id);
            if ($backlogItem === null || ! str_starts_with($backlogItem['id'] ?? '', 'b-')) {
                return $this->outputError("ID '{$id}' is not a backlog item. Backlog items must have 'b-' prefix.");
            }
            // Use the resolved ID
            $id = $backlogItem['id'];
        }

        try {
            // Find the backlog item
            $backlogItem = $backlogService->find($id);
            if ($backlogItem === null) {
                return $this->outputError("Backlog item '{$id}' not found");
            }

            // Ensure we have the full ID with b- prefix
            $resolvedId = $backlogItem['id'];
            if (! str_starts_with($resolvedId, 'b-')) {
                return $this->outputError("Backlog item '{$resolvedId}' does not have 'b-' prefix");
            }

            // Delete from backlog
            $deletedItem = $backlogService->delete($resolvedId);

            // Prepare task data from backlog item
            $taskData = [
                'title' => $deletedItem['title'] ?? throw new RuntimeException('Backlog item missing title'),
                'description' => $deletedItem['description'] ?? null,
            ];

            // Add options if provided
            if ($priority = $this->option('priority')) {
                if (! is_numeric($priority)) {
                    return $this->outputError("Invalid priority '{$priority}'. Must be an integer between 0 and 4.");
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
                $taskData['labels'] = array_map('trim', explode(',', $labels));
            }

            if ($size = $this->option('size')) {
                $taskData['size'] = $size;
            }

            if ($blockedBy = $this->option('blocked-by')) {
                $taskData['blocked_by'] = array_map('trim', explode(',', $blockedBy));
            }

            // Create the task
            $taskService->initialize();
            $task = $taskService->create($taskData);

            if ($this->option('json')) {
                $this->outputJson($task);
            } else {
                $this->info("Promoted backlog item {$resolvedId} to task: {$task['id']}");
                $this->line("  Title: {$task['title']}");

                if (! empty($task['blocked_by'])) {
                    $blockerIds = is_array($task['blocked_by']) ? implode(', ', $task['blocked_by']) : '';
                    if ($blockerIds !== '') {
                        $this->line("  Blocked by: {$blockerIds}");
                    }
                }
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        }
    }

    /**
     * Configure the BacklogService with --cwd option if provided.
     */
    protected function configureBacklogCwd(BacklogService $backlogService): void
    {
        if ($cwd = $this->option('cwd')) {
            $backlogService->setStoragePath($cwd.'/.fuel/backlog.jsonl');
        }
    }
}
