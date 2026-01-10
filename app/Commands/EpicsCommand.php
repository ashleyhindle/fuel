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
        $dbService = new DatabaseService;
        $taskService = new TaskService;

        if ($cwd = $this->option('cwd')) {
            $dbService->setDatabasePath($cwd.'/.fuel/agent.db');
            $taskService->setStoragePath($cwd.'/.fuel/tasks.jsonl');
        }

        $epicService = new EpicService($dbService, $taskService);

        try {
            $epics = $epicService->getAllEpics();

            if ($this->option('json')) {
                // For JSON output, include task count for each epic
                $epicsWithTaskCount = array_map(function (array $epic) use ($epicService): array {
                    $tasks = $epicService->getTasksForEpic($epic['id']);
                    $epic['task_count'] = count($tasks);

                    return $epic;
                }, $epics);

                $this->outputJson($epicsWithTaskCount);

                return self::SUCCESS;
            }

            if (empty($epics)) {
                $this->info('No epics found.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Epics (%d):', count($epics)));
            $this->newLine();

            // Build table rows with task counts
            $headers = ['ID', 'Title', 'Status', 'Task Count', 'Created'];
            $rows = array_map(function (array $epic) use ($epicService): array {
                $tasks = $epicService->getTasksForEpic($epic['id']);
                $taskCount = count($tasks);

                return [
                    $epic['id'],
                    $epic['title'] ?? '',
                    $epic['status'] ?? 'planning',
                    (string) $taskCount,
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
