<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class ReadyCommand extends Command
{
    protected $signature = 'ready
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Show all open (non-done) tasks';

    public function handle(TaskService $taskService): int
    {
        if ($cwd = $this->option('cwd')) {
            $taskService->setStoragePath($cwd.'/.fuel/tasks.jsonl');
        }

        $tasks = $taskService->ready();

        if ($this->option('json')) {
            $this->line(json_encode($tasks->values()->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            if ($tasks->isEmpty()) {
                $this->info('No open tasks.');

                return self::SUCCESS;
            }

            $this->info("Open tasks ({$tasks->count()}):");
            $this->newLine();

            $this->table(
                ['ID', 'Title', 'Created'],
                $tasks->map(fn (array $t) => [
                    $t['id'],
                    $t['title'],
                    $t['created_at'],
                ])->toArray()
            );
        }

        return self::SUCCESS;
    }
}
