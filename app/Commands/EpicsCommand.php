<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class EpicsCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epics
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'List all epics';

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
                    $this->formatDate($epic['created_at'] ?? ''),
                ];
            }, $epics);

            $this->table($headers, $rows);

            return self::SUCCESS;
        } catch (\Exception $e) {
            return $this->outputError('Failed to fetch epics: '.$e->getMessage());
        }
    }

    /**
     * Format a date string into a human-readable format.
     */
    private function formatDate(string $dateString): string
    {
        try {
            $date = new \DateTime($dateString);
            $now = new \DateTime;
            $diff = $now->diff($date);

            // If less than 1 minute ago
            if ($diff->days === 0 && $diff->h === 0 && $diff->i === 0) {
                return 'just now';
            }

            // If less than 1 hour ago
            if ($diff->days === 0 && $diff->h === 0) {
                $minutes = $diff->i;

                return $minutes.'m ago';
            }

            // If less than 24 hours ago
            if ($diff->days === 0) {
                $hours = $diff->h;

                return $hours.'h ago';
            }

            // If less than 7 days ago
            if ($diff->days < 7) {
                $days = $diff->days;

                return $days.'d ago';
            }

            // If same year, show "Mon Day" (e.g., "Jan 7")
            if ($date->format('Y') === $now->format('Y')) {
                return $date->format('M j');
            }

            // Different year, show "Mon Day, Year" (e.g., "Jan 7, 2025")
            return $date->format('M j, Y');
        } catch (\Exception) {
            // Fallback to original if parsing fails
            return $dateString;
        }
    }
}
