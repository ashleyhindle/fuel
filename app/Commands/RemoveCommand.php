<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class RemoveCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'remove
        {id : The task ID (f-xxx, supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Delete a task';

    public function handle(TaskService $taskService): int
    {
        $id = $this->argument('id');

        try {
            // Find the task (supports partial ID matching)
            $task = $taskService->find($id);

            if (! $task instanceof Task) {
                return $this->outputError(sprintf("Task '%s' not found", $id));
            }

            $resolvedId = $task->short_id;
            $title = $task->title ?? '';

            // Delete the task
            $deletedTask = $taskService->delete($resolvedId);

            if ($this->option('json')) {
                $this->outputJson([
                    'short_id' => $resolvedId,
                    'deleted' => $deletedTask->toArray(),
                ]);
            } else {
                $this->info('Deleted task: '.$resolvedId);
                $this->line('  Title: '.$title);
            }

            return self::SUCCESS;
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }
    }
}
