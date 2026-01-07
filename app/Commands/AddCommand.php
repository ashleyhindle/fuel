<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class AddCommand extends Command
{
    protected $signature = 'add
        {title : The task title}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Add a new task';

    public function handle(TaskService $taskService): int
    {
        if ($cwd = $this->option('cwd')) {
            $taskService->setStoragePath($cwd.'/.fuel/tasks.jsonl');
        }

        $taskService->initialize();

        $task = $taskService->create([
            'title' => $this->argument('title'),
        ]);

        if ($this->option('json')) {
            $this->line(json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Created task: {$task['id']}");
            $this->line("  Title: {$task['title']}");
        }

        return self::SUCCESS;
    }
}
