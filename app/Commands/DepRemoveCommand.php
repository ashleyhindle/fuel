<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class DepRemoveCommand extends Command
{
    protected $signature = 'dep:remove
        {from : Task ID that has the dependency (supports partial matching)}
        {to : Task ID it depends on (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Remove dependency between tasks';

    public function handle(TaskService $taskService): int
    {
        if ($cwd = $this->option('cwd')) {
            $taskService->setStoragePath($cwd.'/.fuel/tasks.jsonl');
        }

        try {
            $updatedTask = $taskService->removeDependency(
                $this->argument('from'),
                $this->argument('to')
            );

            if ($this->option('json')) {
                $this->line(json_encode($updatedTask, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $fromId = $updatedTask['id'];
                $toTask = $taskService->find($this->argument('to'));
                $toId = $toTask['id'] ?? $this->argument('to');
                $this->info("Removed dependency: {$fromId} no longer depends on {$toId}");
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
