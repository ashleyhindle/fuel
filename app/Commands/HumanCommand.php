<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class HumanCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'human
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'List all open tasks with needs-human label';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        $tasks = $taskService->all();

        // Filter for open tasks with 'needs-human' label
        $humanTasks = $tasks
            ->filter(fn (array $t): bool => ($t['status'] ?? '') === 'open')
            ->filter(function (array $t): bool {
                $labels = $t['labels'] ?? [];
                if (! is_array($labels)) {
                    return false;
                }

                return in_array('needs-human', $labels, true);
            })
            ->sortBy('created_at')
            ->values();

        if ($this->option('json')) {
            $this->outputJson($humanTasks->toArray());
        } else {
            if ($humanTasks->isEmpty()) {
                $this->info('No tasks need human attention.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Tasks needing human attention (%d):', $humanTasks->count()));
            $this->newLine();

            foreach ($humanTasks as $task) {
                $this->line(sprintf('<info>%s</info> - %s', $task['id'], $task['title']));
                if (! empty($task['description'] ?? null)) {
                    $this->line('  ' . $task['description']);
                }

                $this->newLine();
            }
        }

        return self::SUCCESS;
    }
}
