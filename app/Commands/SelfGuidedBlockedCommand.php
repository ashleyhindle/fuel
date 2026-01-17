<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class SelfGuidedBlockedCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'selfguided:blocked
        {id : The self-guided task ID}
        {--reason= : Reason for blocking}
        {--json : Output as JSON}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Block a self-guided task by creating a needs-human task';

    public function handle(TaskService $taskService): int
    {
        $taskId = $this->argument('id');
        $reason = $this->option('reason');

        // Find task by id
        $task = $taskService->find($taskId);
        if (! $task instanceof Task) {
            return $this->outputError(sprintf("Task '%s' not found", $taskId));
        }

        // Validate it has agent='selfguided'
        if ($task->agent !== 'selfguided') {
            return $this->outputError(sprintf("Task '%s' is not a self-guided task (agent=%s)", $task->short_id, $task->agent ?? 'null'));
        }

        // Determine needs-human task title
        $needsHumanTitle = $reason !== null
            ? sprintf('Blocked: %s', $reason)
            : sprintf('Blocked: %s', $task->title);

        // Create needs-human task
        $needsHumanTask = $taskService->create([
            'title' => $needsHumanTitle,
            'labels' => ['needs-human'],
            'description' => $reason ?? sprintf('Self-guided task %s is blocked and needs human intervention.', $task->short_id),
        ]);

        // Add dependency: selfguided task depends on needs-human task
        $taskService->addDependency($task->short_id, $needsHumanTask->short_id);

        if ($this->option('json')) {
            $this->outputJson([
                'needs_human_task' => $needsHumanTask->short_id,
                'selfguided_task' => $task->short_id,
                'status' => 'blocked',
            ]);
        } else {
            $this->info(sprintf('Created needs-human task %s. Self-guided task paused.', $needsHumanTask->short_id));
        }

        return self::SUCCESS;
    }
}
