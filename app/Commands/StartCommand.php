<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class StartCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'start
        {id : The task ID (supports partial matching)}
        {--json : Output as JSON}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Claim a task (set status to in_progress)';

    public function handle(TaskService $taskService): int
    {
        $id = $this->argument('id');

        try {
            $task = $taskService->start($id);
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }

        if ($this->option('json')) {
            $this->outputJson($task->toArray());
        } else {
            $this->info('Started task: '.$task->short_id);
            $this->line('  Title: '.$task->title);
        }

        return self::SUCCESS;
    }
}
