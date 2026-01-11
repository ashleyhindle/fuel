<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class AddCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'add
        {title : The task title}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--d|description= : Task description}
        {--type= : Task type (bug|fix|feature|task|epic|chore|docs|test|refactor)}
        {--priority= : Task priority (0-4)}
        {--labels= : Comma-separated list of labels}
        {--complexity= : Task complexity (trivial|simple|moderate|complex)}
        {--blocked-by= : Comma-separated task IDs this is blocked by}
        {--e|epic= : Epic ID to associate this task with}
        {--someday : Add to backlog instead of tasks}
        {--backlog : Add to backlog (alias for --someday)}';

    protected $description = 'Add a new task';

    public function handle(FuelContext $context, TaskService $taskService, DatabaseService $dbService, EpicService $epicService): int
    {
        // Configure context with --cwd if provided
        $this->configureCwd($context, $dbService);

        // Initialize task service
        $taskService->initialize();

        $data = [
            'title' => $this->argument('title'),
        ];

        // Set status to someday if --someday or --backlog flag is present
        if ($this->option('backlog') || $this->option('someday')) {
            $data['status'] = TaskStatus::Someday->value;
        }

        // Add description (support both --description and -d)
        if ($description = $this->option('description')) {
            $data['description'] = $description;
        }

        // Add type
        if ($type = $this->option('type')) {
            $data['type'] = $type;
        }

        // Add priority (use !== null to allow 0)
        if (($priority = $this->option('priority')) !== null) {
            // Validate priority is numeric before casting
            if (! is_numeric($priority)) {
                return $this->outputError(sprintf("Invalid priority '%s'. Must be an integer between 0 and 4.", $priority));
            }

            $data['priority'] = (int) $priority;
        }

        // Add labels (comma-separated)
        if ($labels = $this->option('labels')) {
            $data['labels'] = array_map(trim(...), explode(',', $labels));
        }

        // Add complexity
        if ($complexity = $this->option('complexity')) {
            $data['complexity'] = $complexity;
        }

        // Add blocked-by dependencies (comma-separated task IDs)
        if ($blockedBy = $this->option('blocked-by')) {
            $data['blocked_by'] = array_map(trim(...), explode(',', $blockedBy));
        }

        // Add epic_id if provided
        if ($epic = $this->option('epic')) {
            // Validate epic exists
            $epicRecord = $epicService->getEpic($epic);
            if (! $epicRecord instanceof Epic) {
                return $this->outputError(sprintf("Epic '%s' not found", $epic));
            }

            $data['epic_id'] = $epicRecord->id;
        }

        try {
            $task = $taskService->create($data);
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }

        if ($this->option('json')) {
            $this->outputJson($task->toArray());
        } else {
            $this->info('Created task: '.$task->id);
            $this->line('  Title: '.$task->title);
            $this->line('  Status: '.$task->status);

            if (! empty($task->blocked_by)) {
                $blockerIds = is_array($task->blocked_by) ? implode(', ', $task->blocked_by) : '';
                if ($blockerIds !== '') {
                    $this->line('  Blocked by: '.$blockerIds);
                }
            }
        }

        return self::SUCCESS;
    }
}
