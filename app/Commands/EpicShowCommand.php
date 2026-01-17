<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Commands\Concerns\RendersBoardColumns;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Services\EpicService;
use App\Services\RunService;
use App\Services\TaskService;
use App\TUI\Table;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicShowCommand extends Command
{
    use HandlesJsonOutput;
    use RendersBoardColumns;

    protected $signature = 'epic:show
        {id : The epic ID (supports partial matching)}
        {--json : Output as JSON}';

    protected $description = 'Show epic details including linked tasks';

    public function handle(TaskService $taskService, EpicService $epicService, RunService $runService): int
    {
        try {
            $epic = $epicService->getEpic($this->argument('id'));

            if (! $epic instanceof Epic) {
                return $this->outputError(sprintf("Epic '%s' not found", $this->argument('id')));
            }

            $tasks = $epicService->getTasksForEpic($epic->short_id);

            // Sort tasks: unblocked first, then blocked; within each group by priority ASC, then created_at ASC
            $tasksCollection = collect($tasks);
            $allTasks = $taskService->all(); // Need all tasks to determine blocked status
            $blockedIds = $taskService->getBlockedIds($allTasks);

            // Partition tasks into unblocked and blocked groups
            [$unblockedTasks, $blockedTasks] = $tasksCollection->partition(fn (Task $task): bool => ! in_array($task->short_id ?? '', $blockedIds, true));

            // Sort each group by priority ASC, then created_at ASC
            $sortedUnblocked = $unblockedTasks
                ->sortBy([
                    ['priority', 'asc'],
                    ['created_at', 'asc'],
                ])
                ->values();

            $sortedBlocked = $blockedTasks
                ->sortBy([
                    ['priority', 'asc'],
                    ['created_at', 'asc'],
                ])
                ->values();

            // Combine: unblocked first, then blocked
            $sortedTasks = $sortedUnblocked->concat($sortedBlocked)->values()->all();

            if ($this->option('json')) {
                $totalCount = count($sortedTasks);
                $completedCount = count(array_filter($sortedTasks, fn (Task $task): bool => $task->status === TaskStatus::Done));
                $epicArray = $epic->toArray();
                $epicArray['tasks'] = array_map(function (Task $task) use ($runService): array {
                    $data = $task->toArray();
                    $latestRun = $runService->getLatestRun($task->short_id);
                    $data['type'] = $task->type ?? 'task';
                    $data['agent'] = $latestRun?->agent;
                    $data['commit_hash'] = $task->commit_hash ?? null;

                    return $data;
                }, $sortedTasks);
                $epicArray['task_count'] = $totalCount;
                $epicArray['completed_count'] = $completedCount;

                // Include cost in JSON output
                $epicCost = $runService->getEpicCost($epic->id);
                if ($epicCost !== null) {
                    $epicArray['cost_usd'] = $epicCost;
                }

                $this->outputJson($epicArray);

                return self::SUCCESS;
            }

            // Calculate progress
            $totalCount = count($sortedTasks);
            $completedCount = count(array_filter($sortedTasks, fn (Task $task): bool => $task->status === TaskStatus::Done));
            $progress = $totalCount > 0 ? sprintf('%d/%d complete', $completedCount, $totalCount) : '0/0 complete';

            // Display epic details
            $this->info('Epic: '.$epic->short_id);
            $this->line('  Title: '.($epic->title ?? ''));
            $this->line('  Status: '.$epic->status->value);
            $this->line('  Progress: '.$progress);

            if (isset($epic->description) && $epic->description !== null) {
                $this->line('  Description: '.$epic->description);
            }

            // Display cost if available
            $epicCost = $runService->getEpicCost($epic->id);
            if ($epicCost !== null) {
                $this->line(sprintf('  Cost: $%.4f', $epicCost));
            }

            $this->line('  Created: '.($epic->created_at ?? ''));

            // Display linked tasks in table format
            $this->newLine();
            if (empty($sortedTasks)) {
                $this->line('  <fg=yellow>No tasks linked to this epic.</>');
            } else {
                $this->info(sprintf('Linked Tasks (%d):', count($sortedTasks)));
                $this->newLine();

                $headers = ['ID', 'Title', 'Status', 'Type', 'Priority', 'Complexity', 'Agent', 'Created'];

                // Column priorities: lower = more important, higher gets dropped first
                $columnPriorities = [
                    1,  // ID - keep
                    1,  // Title - keep
                    2,  // Status - keep
                    5,  // Type - drop if needed
                    4,  // Priority - drop if needed
                    6,  // Complexity - drop if needed
                    3,  // Agent - drop if needed
                    7,  // Created - drop first
                ];

                $rows = array_map(function (Task $task) use ($blockedIds, $runService): array {
                    $isBlocked = in_array($task->short_id, $blockedIds, true);
                    $displayStatus = $this->getDisplayStatus($task, $isBlocked);

                    // Get latest run for agent info
                    $latestRun = $runService->getLatestRun($task->short_id);
                    $agent = $latestRun?->agent ?? '';

                    // Get first line of title, then truncate if needed
                    $title = strtok($task->title, "\r\n") ?: $task->title;
                    if (mb_strlen($title) > 60) {
                        $title = mb_substr($title, 0, 57).'...';
                    }

                    return [
                        $task->short_id,
                        $title,
                        $displayStatus,
                        $task->type ?? 'task',
                        'P'.($task->priority ?? 2),
                        $task->complexity ?? 'simple',
                        $agent,
                        $this->formatDate($task->created_at),
                    ];
                }, $sortedTasks);

                $table = new Table;
                $terminalWidth = $this->getTerminalWidth();
                $table->render($headers, $rows, $this->output, $columnPriorities, $terminalWidth);
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        } catch (\Exception $e) {
            return $this->outputError('Failed to fetch epic: '.$e->getMessage());
        }
    }

    /**
     * Format a date string into a human-readable format.
     */
    private function formatDate(\DateTimeInterface $date): string
    {
        try {
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
            return $date->format('Y-m-d H:i:s');
        }
    }

    /**
     * Get the display status for a task.
     */
    private function getDisplayStatus(Task $task, bool $isBlocked): string
    {
        // Check for needs-human label first
        if ($this->hasNeedsHumanLabel($task)) {
            return '👤 needs human';
        }

        // Check if blocked
        if ($isBlocked && $task->status === TaskStatus::Open) {
            return 'blocked';
        }

        return $task->status->value;
    }

    /**
     * Check if a task has the needs-human label.
     */
    private function hasNeedsHumanLabel(Task $task): bool
    {
        $labels = $task->labels ?? [];

        return in_array('needs-human', $labels, true);
    }

    /**
     * Get a single character representing task complexity.
     */
    private function getComplexityChar(Task $task): string
    {
        $complexity = $task->complexity ?? 'simple';

        return match ($complexity) {
            'trivial' => 't',
            'simple' => 's',
            'moderate' => 'm',
            'complex' => 'c',
            default => 's',
        };
    }
}
