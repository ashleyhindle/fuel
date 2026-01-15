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
use App\TUI\Table;
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

            $this->line('  Created: '.($epic->created_at ?? ''));

            // Display linked tasks in table format
            $this->newLine();
            if (empty($sortedTasks)) {
                $this->line('  <fg=yellow>No tasks linked to this epic.</>');
            } else {
                $this->line(sprintf('  Linked Tasks (%d):', count($sortedTasks)));
                $headers = ['ID', 'Title', 'Status', 'Type', 'Priority', 'Agent', 'Run ID', 'Exit Code', 'Commit'];
                $rows = array_map(function (Task $task) use ($blockedIds, $runService): array {
                    $isBlocked = in_array($task->short_id, $blockedIds, true);

                    // Add visual indicator for blocked tasks (like tree command)
                    $statusDisplay = $isBlocked && $task->status === TaskStatus::Open
                        ? '<fg=yellow>blocked</>'
                        : $task->status->value;

                    // Get latest run for this task
                    $latestRun = $runService->getLatestRun($task->short_id);
                    $runId = $latestRun?->short_id ?? '';
                    $exitCode = $latestRun?->exit_code !== null ? (string) $latestRun->exit_code : '';
                    $agent = $latestRun?->agent ?? '';

                    return [
                        $task->short_id,
                        $task->title ?? '',
                        $statusDisplay,
                        $task->type ?? 'task',
                        isset($task->priority) ? (string) $task->priority : '',
                        $agent,
                        $runId,
                        $exitCode,
                        $task->commit_hash ?? '',
                    ];
                }, $sortedTasks);

                $table = new Table;
                $table->render($headers, $rows, $this->output);
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        } catch (\Exception $e) {
            return $this->outputError('Failed to fetch epic: '.$e->getMessage());
        }
    }
}
