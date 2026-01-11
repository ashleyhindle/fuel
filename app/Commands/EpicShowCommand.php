<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\EpicStatus;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
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

    public function handle(FuelContext $context, DatabaseService $dbService, TaskService $taskService, EpicService $epicService): int
    {
        // Configure context with --cwd if provided
        $this->configureCwd($context, $dbService);

        try {
            $epic = $epicService->getEpic($this->argument('id'));

            if (! $epic instanceof Epic) {
                return $this->outputError(sprintf("Epic '%s' not found", $this->argument('id')));
            }

            $tasks = $epicService->getTasksForEpic($epic->id);

            // Sort tasks: unblocked first, then blocked; within each group by priority ASC, then created_at ASC
            $tasksCollection = collect($tasks);
            $allTasks = $taskService->all(); // Need all tasks to determine blocked status
            $blockedIds = $taskService->getBlockedIds($allTasks);

            // Partition tasks into unblocked and blocked groups
            [$unblockedTasks, $blockedTasks] = $tasksCollection->partition(fn (Task $task): bool => ! in_array($task->id ?? '', $blockedIds, true));

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
                $completedCount = count(array_filter($sortedTasks, fn (Task $task): bool => ($task->status ?? '') === TaskStatus::Closed->value));
                $epicArray = $epic->toArray();
                $epicArray['tasks'] = array_map(fn (Task $task): array => $task->toArray(), $sortedTasks);
                $epicArray['task_count'] = $totalCount;
                $epicArray['completed_count'] = $completedCount;
                $this->outputJson($epicArray);

                return self::SUCCESS;
            }

            // Calculate progress
            $totalCount = count($sortedTasks);
            $completedCount = count(array_filter($sortedTasks, fn (Task $task): bool => ($task->status ?? '') === TaskStatus::Closed->value));
            $progress = $totalCount > 0 ? sprintf('%d/%d complete', $completedCount, $totalCount) : '0/0 complete';

            // Display epic details
            $this->info('Epic: '.$epic->id);
            $this->line('  Title: '.($epic->title ?? ''));
            $this->line('  Status: '.($epic->status ?? EpicStatus::Planning->value));
            $this->line('  Progress: '.$progress);

            if (isset($epic->description) && $epic->description !== null) {
                $this->line('  Description: '.$epic->description);
            }

            $this->line('  Created: '.($epic->created_at ?? ''));

            // Display linked tasks in table format
            $this->newLine();
            if (empty($sortedTasks)) {
                $this->line('  <fg=yellow>No tasks linked to this epic.</>');
            } else {
                $this->line(sprintf('  Linked Tasks (%d):', count($sortedTasks)));
                $this->newLine();

                $headers = ['ID', 'Title', 'Status', 'Type', 'Priority'];
                $rows = array_map(function (Task $task) use ($blockedIds): array {
                    $status = $task->status ?? TaskStatus::Open->value;
                    $isBlocked = in_array($task->id ?? '', $blockedIds, true);

                    // Add visual indicator for blocked tasks (like tree command)
                    if ($isBlocked && $status === TaskStatus::Open->value) {
                        $status = '<fg=yellow>blocked</>';
                    }

                    return [
                        $task->id ?? '',
                        $task->title ?? '',
                        $status,
                        $task->type ?? '',
                        isset($task->priority) ? (string) $task->priority : '',
                    ];
                }, $sortedTasks);

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
