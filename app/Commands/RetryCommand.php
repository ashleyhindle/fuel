<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\ProcessManager;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class RetryCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'retry
        {ids?* : The task ID(s) (supports partial matching). If none provided, retries all failed tasks}
        {--dryrun : Show failed tasks without retrying}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Retry failed tasks by moving them back to open status';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        $ids = $this->argument('ids') ?: [];
        $dryrun = $this->option('dryrun');

        // If --dryrun, just show failed tasks
        if ($dryrun) {
            return $this->showFailedTasks($taskService);
        }

        // If no IDs provided, retry all failed tasks
        if (empty($ids)) {
            $failedTasks = $taskService->failed();
            if ($failedTasks->isEmpty()) {
                $this->info('No failed tasks to retry.');

                return self::SUCCESS;
            }

            $ids = $failedTasks->pluck('id')->all();
        }

        $tasks = [];
        $errors = [];

        foreach ($ids as $id) {
            try {
                $task = $taskService->retry($id);
                $tasks[] = $task;
            } catch (RuntimeException $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        if ($tasks === [] && $errors !== []) {
            // All failed
            return $this->outputError($errors[0]['error']);
        }

        if ($this->option('json')) {
            if (count($tasks) === 1) {
                // Single task - return object for backward compatibility
                $this->outputJson($tasks[0]->toArray());
            } else {
                // Multiple tasks - return array
                $this->outputJson(array_map(fn (Task $task): array => $task->toArray(), $tasks));
            }
        } else {
            foreach ($tasks as $task) {
                $this->info('Retried task: '.$task->id);
                $this->line('  Title: '.$task->title);
            }
        }

        // If there were any errors, return failure even if some succeeded
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->outputError(sprintf("Task '%s': %s", $error['id'], $error['error']));
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showFailedTasks(TaskService $taskService): int
    {
        $failedTasks = $taskService->failed();

        if ($failedTasks->isEmpty()) {
            $this->info('No failed tasks.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->outputJson($failedTasks->values()->map(fn (Task $task): array => $task->toArray())->all());

            return self::SUCCESS;
        }

        $this->info('Failed tasks (use fuel retry to retry all):');
        foreach ($failedTasks as $task) {
            $reason = $this->getFailureReason($task);
            $this->line(sprintf('  %s: %s <fg=gray>(%s)</>', $task->id, $task->title, $reason));
        }

        return self::SUCCESS;
    }

    private function getFailureReason(Task $task): string
    {
        $exitCode = $task->consumed_exit_code ?? null;
        if ($exitCode !== null && $exitCode !== 0) {
            return 'exit code '.$exitCode;
        }

        $pid = $task->consume_pid ?? null;
        if (($task->status ?? '') === 'in_progress' && ! empty($task->consumed)) {
            if ($pid === null) {
                return 'spawn failed / PID lost';
            }

            if (! ProcessManager::isProcessAlive((int) $pid)) {
                return 'dead process (PID '.$pid.')';
            }
        }

        return 'unknown';
    }
}
