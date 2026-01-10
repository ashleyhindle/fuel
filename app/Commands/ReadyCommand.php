<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class ReadyCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'ready
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--size= : Filter by size (xs|s|m|l|xl)}';

    protected $description = 'Show all open (non-done) tasks';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        $tasks = $taskService->ready();

        // Apply size filter if provided
        if ($size = $this->option('size')) {
            $tasks = $tasks->filter(fn (array $t): bool => ($t['size'] ?? 'm') === $size);
        }

        if ($this->option('json')) {
            $this->outputJson($tasks->values()->toArray());
        } else {
            if ($tasks->isEmpty()) {
                $this->info('No open tasks.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Open tasks (%d):', $tasks->count()));
            $this->newLine();

            $this->table(
                ['ID', 'Title', 'Created'],
                $tasks->map(fn (array $t): array => [
                    $t['id'],
                    $t['title'],
                    $t['created_at'],
                ])->toArray()
            );
        }

        return self::SUCCESS;
    }
}
