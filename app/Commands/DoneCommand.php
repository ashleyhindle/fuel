<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class DoneCommand extends Command
{
    protected $signature = 'done
        {id : The task ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Mark a task as done';

    public function handle(TaskService $taskService): int
    {
        if ($cwd = $this->option('cwd')) {
            $taskService->setStoragePath($cwd.'/.fuel/tasks.jsonl');
        }

        try {
            $task = $taskService->done($this->argument('id'));

            if ($this->option('json')) {
                $this->line(json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->info("Completed task: {$task['id']}");
                $this->line("  Title: {$task['title']}");
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            if ($this->option('json')) {
                $this->line(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
