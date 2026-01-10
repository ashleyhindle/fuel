<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicRejectCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:reject
        {id : The epic ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--reason= : Reason for rejection}
        {--json : Output as JSON}';

    protected $description = 'Reject an epic and request changes (moves tasks back to open)';

    public function handle(FuelContext $context, DatabaseService $dbService, TaskService $taskService): int
    {
        // Configure context with --cwd if provided
        $this->configureCwd($context);

        // Reconfigure DatabaseService if context path changed
        if ($this->option('cwd')) {
            $dbService->setDatabasePath($context->getDatabasePath());
        }

        $epicService = new EpicService($dbService, $taskService);

        try {
            $reason = $this->option('reason');
            $epic = $epicService->rejectEpic($this->argument('id'), $reason);

            if ($this->option('json')) {
                $this->outputJson($epic);

                return self::SUCCESS;
            }

            $this->info(sprintf('Epic %s rejected - changes requested', $epic['id']));
            if ($reason !== null) {
                $this->line(sprintf('  Reason: %s', $reason));
            }
            $this->line('  Tasks have been reopened for changes');

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        } catch (\Exception $e) {
            return $this->outputError('Failed to reject epic: '.$e->getMessage());
        }
    }
}
