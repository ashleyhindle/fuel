<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\RunService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class SelfGuidedContinueCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'selfguided:continue
        {id : The task ID (supports partial matching)}
        {--notes= : Optional notes about progress or observations}
        {--commit= : Git commit hash from this iteration}
        {--json : Output as JSON}';

    protected $description = 'Continue a selfguided task by incrementing iteration and reopening';

    public function handle(TaskService $taskService): int
    {
        $id = $this->argument('id');
        $notes = $this->option('notes');
        $commit = $this->option('commit');

        try {
            $task = $taskService->find($id);
            if (! $task instanceof Task) {
                return $this->outputError(sprintf("Task '%s' not found", $id));
            }

            // Validate task has agent='selfguided'
            if ($task->agent !== 'selfguided') {
                return $this->outputError(sprintf("Task '%s' is not a selfguided task (agent='%s')", $task->short_id, $task->agent ?? 'null'));
            }

            // Calculate the iteration we just completed (for notes and max check)
            // Note: We don't update the iteration here - onSuccess() handles that
            $completedIteration = ($task->selfguided_iteration ?? 0) + 1;

            // Check max iterations (50)
            if ($completedIteration >= 50) {
                // Create needs-human task
                $needsHumanTask = $taskService->create([
                    'title' => sprintf('Max iterations reached for %s', $task->title),
                    'description' => sprintf("Task '%s' has reached the maximum of 50 iterations. Manual intervention required.\n\nOriginal task: %s\nDescription: %s", $task->short_id, $task->title, $task->description ?? '(none)'),
                    'type' => 'task',
                    'priority' => 0,
                    'labels' => ['needs-human'],
                    'complexity' => 'simple',
                ]);

                // Add dependency from selfguided task to needs-human
                $taskService->addDependency($task->short_id, $needsHumanTask->short_id);

                if ($this->option('json')) {
                    $this->outputJson([
                        'status' => 'max_iterations_reached',
                        'task' => $task->toArray(),
                        'needs_human_task' => $needsHumanTask->toArray(),
                        'iteration' => $completedIteration,
                    ]);
                } else {
                    $this->warn(sprintf('Max iterations (50) reached for task: %s', $task->short_id));
                    $this->line('  Created needs-human task: '.$needsHumanTask->short_id);
                    $this->line('  Title: '.$needsHumanTask->title);
                }

                return self::SUCCESS;
            }

            // Append notes to description if provided
            // Note: We don't update iteration or stuck_count here - onSuccess() handles that
            // Note: We don't reopen() here - that caused a race condition with the daemon
            // The task stays in_progress; onSuccess() will reopen after the run completes
            if ($notes !== null) {
                $description = $task->description ?? '';
                $task = $taskService->update($task->short_id, [
                    'description' => $description."\n\n--- Iteration {$completedIteration} notes ---\n".$notes,
                ]);
            }

            // Update latest run with commit hash if provided
            if ($commit !== null) {
                $runService = app(RunService::class);
                try {
                    $runService->updateLatestRun($task->short_id, ['commit_hash' => $commit]);
                } catch (RuntimeException) {
                    // No run exists - task may have been created without daemon
                }
            }
            if ($this->option('json')) {
                $this->outputJson($task->toArray());
            } else {
                $this->info(sprintf('Continuing selfguided task after iteration %d: %s', $completedIteration, $task->short_id));
                $this->line('  Title: '.$task->title);
            }

            return self::SUCCESS;
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }
    }
}
