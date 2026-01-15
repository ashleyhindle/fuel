<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class SelfGuidedContinueCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'selfguided:continue
        {id : The task ID (supports partial matching)}
        {--notes= : Optional notes about progress or observations}
        {--json : Output as JSON}';

    protected $description = 'Continue a selfguided task by incrementing iteration and reopening';

    public function handle(TaskService $taskService): int
    {
        $id = $this->argument('id');
        $notes = $this->option('notes');

        try {
            $task = $taskService->find($id);
            if (! $task instanceof Task) {
                return $this->outputError(sprintf("Task '%s' not found", $id));
            }

            // Validate task has agent='selfguided'
            if ($task->agent !== 'selfguided') {
                return $this->outputError(sprintf("Task '%s' is not a selfguided task (agent='%s')", $task->short_id, $task->agent ?? 'null'));
            }

            // Increment iteration
            $newIteration = ($task->selfguided_iteration ?? 0) + 1;

            // Check max iterations (50)
            if ($newIteration >= 50) {
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
                        'iteration' => $newIteration,
                    ]);
                } else {
                    $this->warn(sprintf('Max iterations (50) reached for task: %s', $task->short_id));
                    $this->line('  Created needs-human task: '.$needsHumanTask->short_id);
                    $this->line('  Title: '.$needsHumanTask->title);
                }

                return self::SUCCESS;
            }

            // Update task: increment iteration, reset stuck count
            $updates = [
                'selfguided_iteration' => $newIteration,
                'selfguided_stuck_count' => 0,
            ];

            // Append notes to description if provided
            if ($notes !== null) {
                $description = $task->description ?? '';
                $updates['description'] = $description."\n\n--- Iteration {$newIteration} notes ---\n".$notes;
            }

            $task = $taskService->update($task->short_id, $updates);

            // Reopen the task
            $task = $taskService->reopen($task->short_id);

            if ($this->option('json')) {
                $this->outputJson($task->toArray());
            } else {
                $this->info(sprintf('Task reopened for iteration %d: %s', $newIteration, $task->short_id));
                $this->line('  Title: '.$task->title);
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        }
    }
}
