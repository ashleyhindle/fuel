<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicReviewedCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:reviewed
        {id : The epic ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Mark an epic as reviewed';

    public function handle(): int
    {
        // Configure services with --cwd if provided
        if ($cwd = $this->option('cwd')) {
            $dbService = new DatabaseService($cwd.'/.fuel/agent.db');
            $taskService = new TaskService($dbService);
        } else {
            $dbService = $this->app->make(DatabaseService::class);
            $taskService = $this->app->make(TaskService::class);
        }

        $epicService = new EpicService($dbService, $taskService);

        try {
            $epic = $epicService->markAsReviewed($this->argument('id'));

            if ($this->option('json')) {
                $this->outputJson($epic);

                return self::SUCCESS;
            }

            $this->info(sprintf('Epic %s marked as reviewed', $epic['id']));

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        } catch (\Exception $e) {
            return $this->outputError('Failed to mark epic as reviewed: '.$e->getMessage());
        }
    }
}
