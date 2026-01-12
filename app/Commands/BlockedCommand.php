<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class BlockedCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'blocked
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Show open tasks with unresolved dependencies';

    public function handle(TaskService $taskService): int
    {
        $tasks = $taskService->blocked();

        if ($this->option('json')) {
            $this->outputJson($tasks->values()->map(fn (Task $task): array => $task->toArray())->toArray());
        } else {
            if ($tasks->isEmpty()) {
                $this->info('No blocked tasks.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Blocked tasks (%d):', $tasks->count()));
            $this->newLine();

            $this->table(
                ['ID', 'Title', 'Created'],
                $tasks->map(fn (Task $t): array => [
                    $t->short_id,
                    $t->title,
                    $t->created_at,
                ])->toArray()
            );
        }

        return self::SUCCESS;
    }
}
