<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class ReadyCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'ready
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Show all open (non-done) tasks';

    public function handle(FuelContext $context, DatabaseService $databaseService, TaskService $taskService): int
    {
        $this->configureCwd($context, $databaseService);

        $tasks = $taskService->ready();

        if ($this->option('json')) {
            $this->outputJson($tasks->values()->map(fn (Task $t): array => $t->toArray())->toArray());
        } else {
            if ($tasks->isEmpty()) {
                $this->info('No open tasks.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Open tasks (%d):', $tasks->count()));
            $this->newLine();

            $this->table(
                ['ID', 'Title', 'Created'],
                $tasks->map(fn (Task $t): array => [
                    $t->short_id,
                    $t->title,
                    (string) $t->created_at,
                ])->toArray()
            );
        }

        return self::SUCCESS;
    }
}
