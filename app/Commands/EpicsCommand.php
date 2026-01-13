<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Services\EpicService;
use App\TUI\Table;
use LaravelZero\Framework\Commands\Command;

class EpicsCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epics
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'List all epics';

    public function handle(EpicService $epicService): int
    {
        try {
            $epics = $epicService->getAllEpics();

            if ($this->option('json')) {
                // For JSON output, include task count and completed count for each epic
                $epicsWithProgress = array_map(function (Epic $epic) use ($epicService): array {
                    $tasks = $epicService->getTasksForEpic($epic->short_id);
                    $totalCount = count($tasks);
                    $completedCount = count(array_filter($tasks, fn (Task $task): bool => $task->status === TaskStatus::Done));
                    $epicArray = $epic->toArray();
                    $epicArray['task_count'] = $totalCount;
                    $epicArray['completed_count'] = $completedCount;

                    return $epicArray;
                }, $epics);

                $this->outputJson($epicsWithProgress);

                return self::SUCCESS;
            }

            if ($epics === []) {
                $this->info('No epics found.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Epics (%d):', count($epics)));
            $this->newLine();

            // Build table rows with progress tracking
            $headers = ['ID', 'Title', 'Status', 'Progress', 'Created'];
            $rows = array_map(function (Epic $epic) use ($epicService): array {
                $tasks = $epicService->getTasksForEpic($epic->short_id);
                $totalCount = count($tasks);
                $completedCount = count(array_filter($tasks, fn (Task $task): bool => $task->status === TaskStatus::Done));
                $progress = $totalCount > 0 ? sprintf('%d/%d complete', $completedCount, $totalCount) : '0/0 complete';

                return [
                    $epic->short_id,
                    $epic->title ?? '',
                    $epic->status->value,
                    $progress,
                    $this->formatDate($epic->created_at ?? new \DateTime),
                ];
            }, $epics);

            $table = new Table;
            $table->render($headers, $rows, $this->output);

            return self::SUCCESS;
        } catch (\Exception $exception) {
            return $this->outputError('Failed to fetch epics: '.$exception->getMessage());
        }
    }

    /**
     * Format a date into a human-readable format.
     */
    private function formatDate(\DateTimeInterface $dateInput): string
    {
        try {
            $date = $dateInput;
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
            return $dateInput->format('Y-m-d H:i:s');
        }
    }
}
