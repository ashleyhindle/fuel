<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\ProcessManager;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class StuckCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'stuck
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'List failed/stuck tasks (dead processes or non-zero exit codes)';

    public function handle(TaskService $taskService): int
    {
        $stuckTasks = $taskService->failed()->sortByDesc('consumed_at')->values();

        if ($this->option('json')) {
            $this->outputJson($stuckTasks->map(fn (Task $task): array => $task->toArray())->toArray());
        } else {
            if ($stuckTasks->isEmpty()) {
                $this->info('No stuck tasks found.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Stuck tasks (%d):', $stuckTasks->count()));
            $this->newLine();

            foreach ($stuckTasks as $task) {
                $exitCode = $task->consumed_exit_code ?? null;
                $pid = $task->consume_pid ?? null;
                $output = $task->consumed_output ?? '';

                $this->line(sprintf('<info>%s</info> - %s', $task->short_id, $task->title));

                // Show reason for being stuck
                if ($exitCode !== null && $exitCode !== 0) {
                    $this->line(sprintf('  Reason: <fg=red>Exit code %d</>', $exitCode));
                } elseif ($pid !== null && ! ProcessManager::isProcessAlive((int) $pid)) {
                    $this->line(sprintf('  Reason: <fg=red>Dead process</> (PID %d)', $pid));
                } else {
                    $this->line('  Reason: <fg=red>Lost process</> (no PID)');
                }

                if ($output !== '') {
                    // Truncate output to a reasonable length for display (e.g., 500 chars)
                    $truncated = mb_strlen((string) $output) > 500 ? mb_substr((string) $output, 0, 497).'...' : $output;
                    $this->line('  Output:');
                    // Indent each line of output
                    $outputLines = explode("\n", (string) $truncated);
                    foreach ($outputLines as $line) {
                        $this->line('    '.$line);
                    }
                }

                $this->newLine();
            }
        }

        return self::SUCCESS;
    }
}
