<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class DepAddCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'dep:add
        {from : Task ID that is blocked (supports partial matching)}
        {to : Task ID it is blocked by (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Add a dependency between tasks';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        try {
            $task = $taskService->addDependency(
                $this->argument('from'),
                $this->argument('to')
            );

            if ($this->option('json')) {
                $this->outputJson($task);
            } else {
                $fromId = $task['id'];
                $toId = $this->resolveToTaskId($taskService, $this->argument('to'));

                $this->info("Added dependency: {$fromId} blocked by {$toId}");
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
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
