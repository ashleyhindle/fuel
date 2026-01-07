<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class ShowCommand extends Command
{
    protected $signature = 'show
        {id : The task ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Show task details including all fields';

    public function handle(TaskService $taskService): int
    {
        if ($cwd = $this->option('cwd')) {
            $taskService->setStoragePath($cwd.'/.fuel/tasks.jsonl');
        }

        try {
            $task = $taskService->find($this->argument('id'));

            if ($task === null) {
                if ($this->option('json')) {
                    $this->line(json_encode(['error' => "Task '{$this->argument('id')}' not found"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    $this->error("Task '{$this->argument('id')}' not found");
                }

                return self::FAILURE;
            }

            if ($this->option('json')) {
                $this->line(json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->info("Task: {$task['id']}");
                $this->line("  Title: {$task['title']}");
                $this->line("  Status: {$task['status']}");

                if (isset($task['description']) && $task['description'] !== null) {
                    $this->line("  Description: {$task['description']}");
                }

                if (isset($task['type'])) {
                    $this->line("  Type: {$task['type']}");
                }

                if (isset($task['priority'])) {
                    $this->line("  Priority: {$task['priority']}");
                }

                if (isset($task['size'])) {
                    $this->line("  Size: {$task['size']}");
                }

                if (isset($task['labels']) && ! empty($task['labels'])) {
                    $labels = implode(', ', $task['labels']);
                    $this->line("  Labels: {$labels}");
                }

                if (isset($task['dependencies']) && ! empty($task['dependencies'])) {
                    $depIds = collect($task['dependencies'])->pluck('depends_on')->implode(', ');
                    $this->line("  Dependencies: {$depIds}");
                }

                if (isset($task['reason'])) {
                    $this->line("  Reason: {$task['reason']}");
                }

                $this->line("  Created: {$task['created_at']}");
                $this->line("  Updated: {$task['updated_at']}");
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
