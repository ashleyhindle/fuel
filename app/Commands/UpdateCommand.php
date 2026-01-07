<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class UpdateCommand extends Command
{
    protected $signature = 'update
        {id : The task ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--title= : Update task title}
        {--description= : Update task description}
        {--type= : Update task type (bug|feature|task|epic|chore)}
        {--priority= : Update task priority (0-4)}
        {--status= : Update task status (open|closed)}
        {--size= : Update task size (xs|s|m|l|xl)}
        {--add-labels= : Add labels (comma-separated)}
        {--remove-labels= : Remove labels (comma-separated)}';

    protected $description = 'Update task fields';

    public function handle(TaskService $taskService): int
    {
        if ($cwd = $this->option('cwd')) {
            $taskService->setStoragePath($cwd.'/.fuel/tasks.jsonl');
        }

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
                if ($this->option('json')) {
                    $this->line(json_encode(['error' => "Invalid priority '{$priority}'. Must be an integer between 0 and 4."], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    $this->error("Invalid priority '{$priority}'. Must be an integer between 0 and 4.");
                }

                return self::FAILURE;
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
            $updateData['add_labels'] = array_map('trim', explode(',', $addLabels));
        }

        if ($removeLabels = $this->option('remove-labels')) {
            $updateData['remove_labels'] = array_map('trim', explode(',', $removeLabels));
        }

        if (empty($updateData)) {
            if ($this->option('json')) {
                $this->line(json_encode(['error' => 'No update fields provided. Use --title, --description, --type, --priority, --status, --add-labels, or --remove-labels.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('No update fields provided. Use --title, --description, --type, --priority, --status, --add-labels, or --remove-labels.');
            }

            return self::FAILURE;
        }

        try {
            $task = $taskService->update($this->argument('id'), $updateData);

            if ($this->option('json')) {
                $this->line(json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->info("Updated task: {$task['id']}");
                $this->line("  Title: {$task['title']}");
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            if ($this->option('json')) {
                $this->line(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
