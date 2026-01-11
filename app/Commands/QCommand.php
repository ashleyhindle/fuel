<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class QCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'q
        {title : The task title}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Quick capture - creates task and outputs only the ID';

    public function handle(FuelContext $context, DatabaseService $databaseService, TaskService $taskService): int
    {
        $this->configureCwd($context, $databaseService);

        $taskService->initialize();

        try {
            $task = $taskService->create([
                'title' => $this->argument('title'),
            ]);
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }

        $this->line($task->id);

        return self::SUCCESS;
    }
}
