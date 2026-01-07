<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class DepAddCommand extends Command
{
    protected $signature = 'dep:add
        {from : Task ID that depends on something (supports partial matching)}
        {to : Task ID it depends on (supports partial matching)}
        {--type=blocks : Dependency type (default: blocks)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Add a dependency between tasks';

    public function handle(TaskService $taskService): int
    {
        if ($cwd = $this->option('cwd')) {
            $taskService->setStoragePath($cwd.'/.fuel/tasks.jsonl');
        }

        try {
            $task = $taskService->addDependency(
                $this->argument('from'),
                $this->argument('to'),
                $this->option('type')
            );

            if ($this->option('json')) {
                $this->line(json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $fromId = $task['id'];
                $toId = $this->resolveToTaskId($taskService, $this->argument('to'));
                $type = $this->option('type');

                $this->info("Added dependency: {$fromId} depends on {$toId} ({$type})");
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

    /**
     * Resolve the 'to' task ID for display purposes.
     */
    private function resolveToTaskId(TaskService $taskService, string $toId): string
    {
        $task = $taskService->find($toId);

        return $task !== null ? $task['id'] : $toId;
    }
}
