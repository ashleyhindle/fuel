<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class DepRemoveCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'dep:remove
        {from : Task ID that is blocked (supports partial matching)}
        {to : Task ID it is blocked by (supports partial matching)}
        {--json : Output as JSON}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Remove dependency between tasks';

    public function handle(TaskService $taskService): int
    {
        try {
            $updatedTask = $taskService->removeDependency(
                $this->argument('from'),
                $this->argument('to')
            );

            if ($this->option('json')) {
                $this->outputJson($updatedTask->toArray());
            } else {
                $fromId = $updatedTask->short_id;
                $toTask = $taskService->find($this->argument('to'));
                $toId = $toTask?->short_id ?? $this->argument('to');
                $this->info(sprintf('Removed dependency: %s no longer blocked by %s', $fromId, $toId));
            }

            return self::SUCCESS;
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }
    }
}
