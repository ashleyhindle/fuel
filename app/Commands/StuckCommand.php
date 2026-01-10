<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class StuckCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'stuck
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'List consumed tasks with non-zero exit codes';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        $tasks = $taskService->all();

        // Filter for tasks where consumed=true AND consumed_exit_code != 0
        $stuckTasks = $tasks
            ->filter(function (array $t): bool {
                $consumed = $t['consumed'] ?? false;
                $exitCode = $t['consumed_exit_code'] ?? null;

                return $consumed === true && $exitCode !== null && $exitCode !== 0;
            })
            ->sortByDesc('consumed_at')
            ->values();

        if ($this->option('json')) {
            $this->outputJson($stuckTasks->toArray());
        } else {
            if ($stuckTasks->isEmpty()) {
                $this->info('No stuck tasks found.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Stuck tasks (%d):', $stuckTasks->count()));
            $this->newLine();

            foreach ($stuckTasks as $task) {
                $exitCode = $task['consumed_exit_code'] ?? 0;
                $exitColor = 'red';
                $output = $task['consumed_output'] ?? '';

                $this->line(sprintf('<info>%s</info> - %s', $task['id'], $task['title']));
                $this->line(sprintf('  Exit code: <fg=%s>%s</>', $exitColor, $exitCode));

                if ($output !== '') {
                    // Truncate output to a reasonable length for display (e.g., 500 chars)
                    $truncated = mb_strlen((string) $output) > 500 ? mb_substr((string) $output, 0, 497).'...' : $output;
                    $this->line('  Output:');
                    // Indent each line of output
                    $outputLines = explode("\n", (string) $truncated);
                    foreach ($outputLines as $line) {
                        $this->line('    ' . $line);
                    }
                }

                $this->newLine();
            }
        }

        return self::SUCCESS;
    }
}
