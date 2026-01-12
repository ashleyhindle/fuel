<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class DeferCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'defer
        {id : The task ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Move a task to the backlog';

    public function handle(TaskService $taskService): int
    {
        $id = $this->argument('id');

        try {
            // Defer the task (updates status to 'someday')
            $task = $taskService->defer($id);

            if ($this->option('json')) {
                $this->outputJson([
                    'task_id' => $task->short_id,
                    'title' => $task->title,
                    'status' => $task->status,
                ]);
            } else {
                $this->info('Deferred task: '.$task->short_id);
                $this->line('  Title: '.$task->title);
            }

            return self::SUCCESS;
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }
    }
}
