<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\EpicService;
use App\Services\RunService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class StatsCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'stats
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Display project statistics and metrics';

    public function handle(
        TaskService $taskService,
        RunService $runService,
        EpicService $epicService
    ): int {
        $this->configureCwd($taskService);

        $this->line('Stats coming soon...');

        return self::SUCCESS;
    }
}
