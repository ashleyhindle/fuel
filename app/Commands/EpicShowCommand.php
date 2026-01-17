<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Services\EpicService;
use App\Services\RunService;
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

            // Display linked tasks in compact format (like tree command)
            $this->newLine();
            if (empty($sortedTasks)) {
                $this->line('  <fg=yellow>No tasks linked to this epic.</>');
            } else {
                $this->line(sprintf('  Linked Tasks (%d):', count($sortedTasks)));
                $this->newLine();
                foreach ($sortedTasks as $task) {
                    $isBlocked = in_array($task->short_id, $blockedIds, true);
                    $priority = $task->priority ?? 2;
                    $complexity = $this->getComplexityChar($task);
                    $displayStatus = $this->getDisplayStatus($task, $isBlocked);
                    $statusColor = $this->hasNeedsHumanLabel($task) ? 'magenta' : 'gray';

                    $this->line(sprintf(
                        '  <fg=cyan>[P%d·%s]</> %s %s <fg=%s>(%s)</>',
                        $priority,
                        $complexity,
                        $task->short_id,
                        $task->title,
                        $statusColor,
                        $displayStatus
                    ));
                }
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        } catch (\Exception $e) {
            return $this->outputError('Failed to fetch epic: '.$e->getMessage());
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
