<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class UpdateCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'update
        {id : The task ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--title= : Update task title}
        {--description= : Update task description}
        {--type= : Update task type (bug|feature|task|epic|chore|test)}
        {--priority= : Update task priority (0-4)}
        {--status= : Update task status (open|closed)}
        {--size= : Update task size (xs|s|m|l|xl)}
        {--add-labels= : Add labels (comma-separated)}
        {--remove-labels= : Remove labels (comma-separated)}';

    protected $description = 'Update task fields';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        $updateData = [];

        if ($title = $this->option('title')) {
            $updateData['title'] = $title;
        }

        // Handle description - can be empty string to clear it
        if ($this->option('description') !== null) {
            $updateData['description'] = $this->option('description') ?: null;
        }

        if ($type = $this->option('type')) {
            $updateData['type'] = $type;
        }

        if ($priority = $this->option('priority')) {
            if (! is_numeric($priority)) {
                return $this->outputError(sprintf("Invalid priority '%s'. Must be an integer between 0 and 4.", $priority));
            }

            $updateData['priority'] = (int) $priority;
        }

        if ($status = $this->option('status')) {
            $updateData['status'] = $status;
        }

        if ($size = $this->option('size')) {
            $updateData['size'] = $size;
        }

        if ($addLabels = $this->option('add-labels')) {
            $updateData['add_labels'] = array_map(trim(...), explode(',', $addLabels));
        }

        if ($removeLabels = $this->option('remove-labels')) {
            $updateData['remove_labels'] = array_map(trim(...), explode(',', $removeLabels));
        }

        if (empty($updateData)) {
            return $this->outputError('No update fields provided. Use --title, --description, --type, --priority, --status, --add-labels, or --remove-labels.');
        }

        try {
            $task = $taskService->update($this->argument('id'), $updateData);

            if ($this->option('json')) {
                $this->outputJson($task);
            } else {
                $this->info('Updated task: '.$task['id']);
                $this->line('  Title: '.$task['title']);
            }

            return self::SUCCESS;
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }
    }
}
