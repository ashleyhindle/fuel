<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicShowCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:show
        {id : The epic ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Show epic details including linked tasks';

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
            $epic = $epicService->getEpic($this->argument('id'));

            if ($epic === null) {
                return $this->outputError(sprintf("Epic '%s' not found", $this->argument('id')));
            }

            $tasks = $epicService->getTasksForEpic($epic['id']);

            if ($this->option('json')) {
                $totalCount = count($tasks);
                $completedCount = count(array_filter($tasks, fn (array $task): bool => ($task['status'] ?? '') === 'closed'));
                $epic['tasks'] = $tasks;
                $epic['task_count'] = $totalCount;
                $epic['completed_count'] = $completedCount;
                $this->outputJson($epic);

                return self::SUCCESS;
            }

            // Calculate progress
            $totalCount = count($tasks);
            $completedCount = count(array_filter($tasks, fn (array $task): bool => ($task['status'] ?? '') === 'closed'));
            $progress = $totalCount > 0 ? sprintf('%d/%d complete', $completedCount, $totalCount) : '0/0 complete';

            // Display epic details
            $this->info('Epic: '.$epic['id']);
            $this->line('  Title: '.($epic['title'] ?? ''));
            $this->line('  Status: '.($epic['status'] ?? 'planning'));
            $this->line('  Progress: '.$progress);

            if (isset($epic['description']) && $epic['description'] !== null) {
                $this->line('  Description: '.$epic['description']);
            }

            $this->line('  Created: '.($epic['created_at'] ?? ''));

            // Display linked tasks in table format
            $this->newLine();
            if (empty($tasks)) {
                $this->line('  <fg=yellow>No tasks linked to this epic.</>');
            } else {
                $this->line(sprintf('  Linked Tasks (%d):', count($tasks)));
                $this->newLine();

                $headers = ['ID', 'Title', 'Status', 'Type', 'Priority'];
                $rows = array_map(function (array $task): array {
                    return [
                        $task['id'] ?? '',
                        $task['title'] ?? '',
                        $task['status'] ?? 'open',
                        $task['type'] ?? '',
                        isset($task['priority']) ? (string) $task['priority'] : '',
                    ];
                }, $tasks);

                $this->table($headers, $rows);
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        } catch (\Exception $e) {
            return $this->outputError('Failed to fetch epic: '.$e->getMessage());
        }
    }
}
