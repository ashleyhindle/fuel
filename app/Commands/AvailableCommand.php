<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class AvailableCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'available
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Show count of ready tasks, exit 0 if any available, 1 if none';

    public function handle(TaskService $taskService): int
    {
        $tasks = $taskService->ready();
        $count = $tasks->count();

        if ($this->option('json')) {
            $this->outputJson([
                'count' => $count,
                'available' => $count > 0,
            ]);
        } else {
            $this->line((string) $count);
        }

        // Exit 0 if any available, 1 if none
        return $count > 0 ? self::SUCCESS : self::FAILURE;
    }
}
