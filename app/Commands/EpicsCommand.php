<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class EpicsCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epics
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'List all epics';

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
            $epics = $epicService->getAllEpics();

            if ($this->option('json')) {
                // For JSON output, include task count and completed count for each epic
                $epicsWithProgress = array_map(function (array $epic) use ($epicService): array {
                    $tasks = $epicService->getTasksForEpic($epic['id']);
                    $totalCount = count($tasks);
                    $completedCount = count(array_filter($tasks, fn (array $task): bool => ($task['status'] ?? '') === 'closed'));
                    $epic['task_count'] = $totalCount;
                    $epic['completed_count'] = $completedCount;

                    return $epic;
                }, $epics);

                $this->outputJson($epicsWithProgress);

                return self::SUCCESS;
            }

            if (empty($epics)) {
                $this->info('No epics found.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Epics (%d):', count($epics)));
            $this->newLine();

            // Build table rows with progress tracking
            $headers = ['ID', 'Title', 'Status', 'Progress', 'Created'];
            $rows = array_map(function (array $epic) use ($epicService): array {
                $tasks = $epicService->getTasksForEpic($epic['id']);
                $totalCount = count($tasks);
                $completedCount = count(array_filter($tasks, fn (array $task): bool => ($task['status'] ?? '') === 'closed'));
                $progress = $totalCount > 0 ? sprintf('%d/%d complete', $completedCount, $totalCount) : '0/0 complete';

                return [
                    $epic['id'],
                    $epic['title'] ?? '',
                    $epic['status'] ?? 'planning',
                    $progress,
                    $epic['created_at'] ?? '',
                ];
            }, $epics);

            $this->table($headers, $rows);

            return self::SUCCESS;
        } catch (\Exception $e) {
            return $this->outputError('Failed to fetch epics: '.$e->getMessage());
        }
    }
}
