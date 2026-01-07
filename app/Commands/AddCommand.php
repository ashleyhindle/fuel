<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class AddCommand extends Command
{
    protected $signature = 'add
        {title : The task title}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--d|description= : Task description}
        {--type= : Task type (bug|feature|task|epic|chore)}
        {--priority= : Task priority (0-4)}
        {--labels= : Comma-separated list of labels}
        {--blocked-by= : Comma-separated task IDs this is blocked by}';

    protected $description = 'Add a new task';

    public function handle(TaskService $taskService): int
    {
        if ($cwd = $this->option('cwd')) {
            $taskService->setStoragePath($cwd.'/.fuel/tasks.jsonl');
        }

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
                $this->error("Invalid priority '{$priority}'. Must be an integer between 0 and 4.");

                return self::FAILURE;
            }
            $data['priority'] = (int) $priority;
        }

        // Add labels (comma-separated)
        if ($labels = $this->option('labels')) {
            $data['labels'] = array_map('trim', explode(',', $labels));
        }

        // Add blocked-by dependencies (comma-separated task IDs)
        if ($blockedBy = $this->option('blocked-by')) {
            $blockedByIds = array_map('trim', explode(',', $blockedBy));
            $data['dependencies'] = array_map(
                fn (string $id): array => ['depends_on' => $id, 'type' => 'blocks'],
                $blockedByIds
            );
        }

        try {
            $task = $taskService->create($data);
        } catch (RuntimeException $e) {
            if ($this->option('json')) {
                $this->line(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Created task: {$task['id']}");
            $this->line("  Title: {$task['title']}");

            if (! empty($task['dependencies'])) {
                $depIds = collect($task['dependencies'])->pluck('depends_on')->implode(', ');
                $this->line("  Blocked by: {$depIds}");
            }
        }

        return self::SUCCESS;
    }
}
