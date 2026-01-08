<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\BacklogService;
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
        {--type= : Task type (bug|feature|task|epic|chore|test)}
        {--priority= : Task priority (0-4)}
        {--labels= : Comma-separated list of labels}
        {--size= : Task size (xs|s|m|l|xl)}
        {--complexity= : Task complexity (trivial|simple|moderate|complex)}
        {--blocked-by= : Comma-separated task IDs this is blocked by}
        {--someday : Add to backlog instead of tasks}';

    protected $description = 'Add a new task';

    public function handle(TaskService $taskService, BacklogService $backlogService): int
    {
        // Handle --someday flag: add to backlog instead of tasks
        if ($this->option('someday')) {
            // Configure cwd for BacklogService
            if ($cwd = $this->option('cwd')) {
                $backlogService->setStoragePath($cwd.'/.fuel/backlog.jsonl');
            }

            $backlogService->initialize();

            $title = $this->argument('title');
            $description = $this->option('description');

            try {
                $item = $backlogService->add($title, $description);
            } catch (RuntimeException $e) {
                return $this->outputError($e->getMessage());
            }

            if ($this->option('json')) {
                $this->outputJson($item);
            } else {
                $this->info("Added to backlog: {$item['id']}");
                $this->line("  Title: {$item['title']}");
            }

            return self::SUCCESS;
        }

        // Existing task creation logic
        $this->configureCwd($taskService);

        $taskService->initialize();

        $data = [
            'title' => $this->argument('title'),
        ];

        // Add description (support both --description and -d)
        if ($description = $this->option('description')) {
            $data['description'] = $description;
        }

        // Add type
        if ($type = $this->option('type')) {
            $data['type'] = $type;
        }

        // Add priority
        if ($priority = $this->option('priority')) {
            // Validate priority is numeric before casting
            if (! is_numeric($priority)) {
                return $this->outputError("Invalid priority '{$priority}'. Must be an integer between 0 and 4.");
            }
            $data['priority'] = (int) $priority;
        }

        // Add labels (comma-separated)
        if ($labels = $this->option('labels')) {
            $data['labels'] = array_map('trim', explode(',', $labels));
        }

        // Add size
        if ($size = $this->option('size')) {
            $data['size'] = $size;
        }

        // Add complexity
        if ($complexity = $this->option('complexity')) {
            $data['complexity'] = $complexity;
        }

        // Add blocked-by dependencies (comma-separated task IDs)
        if ($blockedBy = $this->option('blocked-by')) {
            $data['blocked_by'] = array_map('trim', explode(',', $blockedBy));
        }

        try {
            $task = $taskService->create($data);
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        }

        if ($this->option('json')) {
            $this->outputJson($task);
        } else {
            $this->info("Created task: {$task['id']}");
            $this->line("  Title: {$task['title']}");

            if (! empty($task['blocked_by'])) {
                $blockerIds = is_array($task['blocked_by']) ? implode(', ', $task['blocked_by']) : '';
                if ($blockerIds !== '') {
                    $this->line("  Blocked by: {$blockerIds}");
                }
            }
        }

        return self::SUCCESS;
    }
}
