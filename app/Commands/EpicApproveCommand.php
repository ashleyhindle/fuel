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

class EpicApproveCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:approve
        {id : The epic ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--by= : Who approved it (defaults to "human")}
        {--json : Output as JSON}';

    protected $description = 'Approve an epic (mark as approved)';

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
            $approvedBy = $this->option('by');
            $epic = $epicService->approveEpic($this->argument('id'), $approvedBy);

            if ($this->option('json')) {
                $this->outputJson($epic->toArray());

                return self::SUCCESS;
            }

            $this->info(sprintf('Epic %s approved', $epic->id));
            if (isset($epic->approved_by)) {
                $this->line(sprintf('  Approved by: %s', $epic->approved_by));
            }
            if (isset($epic->approved_at)) {
                $this->line(sprintf('  Approved at: %s', $epic->approved_at));
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        } catch (\Exception $e) {
            return $this->outputError('Failed to approve epic: '.$e->getMessage());
        }
    }
}
