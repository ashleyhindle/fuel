<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class ReopenCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'reopen
        {id : The task ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Reopen a closed task (set status back to open)';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        $id = $this->argument('id');

        try {
            $task = $taskService->reopen($id);
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        }

        if ($this->option('json')) {
            $this->outputJson($task);
        } else {
            $this->info("Reopened task: {$task['id']}");
            $this->line("  Title: {$task['title']}");
        }

        return self::SUCCESS;
    }
}
