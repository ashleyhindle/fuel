<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\EpicStatus;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Services\EpicService;
use App\Services\TaskService;
use App\TUI\Table;
use LaravelZero\Framework\Commands\Command;

class PausedCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'paused
        {--json : Output as JSON}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Show paused tasks and epics with unpause commands';

    public function handle(TaskService $taskService, EpicService $epicService): int
    {
        // Get all paused tasks
        $pausedTasks = $taskService->all()
            ->filter(fn (Task $t): bool => $t->status === TaskStatus::Paused);

        // Get all paused epics
        $allEpics = collect($epicService->getAllEpics());
        $pausedEpics = $allEpics->filter(fn (Epic $e): bool => $e->status === EpicStatus::Paused);

        if ($this->option('json')) {
            $this->outputJson([
                'tasks' => $pausedTasks->map(fn (Task $t): array => $t->toArray())->toArray(),
                'epics' => $pausedEpics->map(fn (Epic $e): array => $e->toArray())->toArray(),
            ]);
        } else {
            if ($pausedTasks->isEmpty() && $pausedEpics->isEmpty()) {
                $this->info('No paused tasks or epics found.');

                return self::SUCCESS;
            }

            // Show paused tasks
            if ($pausedTasks->isNotEmpty()) {
                $this->info(sprintf('Paused tasks (%d):', $pausedTasks->count()));
                $this->renderTasksTable($pausedTasks);
                $this->line('');
            }

            // Show paused epics
            if ($pausedEpics->isNotEmpty()) {
                $this->info(sprintf('Paused epics (%d):', $pausedEpics->count()));
                $this->renderEpicsTable($pausedEpics);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Render paused tasks in a TUI table.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     */
    private function renderTasksTable($tasks): void
    {
        $headers = ['ID', 'Title', 'Unpause Command'];

        // Column priorities: lower = more important, higher gets dropped first
        $columnPriorities = [
            1,  // ID - keep
            1,  // Title - keep
            1,  // Unpause Command - keep
        ];

        $rows = $tasks->map(function (Task $t): array {
            // Get first line of title, then truncate if needed
            $title = strtok($t->title, "\r\n") ?: $t->title;
            if (mb_strlen($title) > 40) {
                $title = mb_substr($title, 0, 37).'...';
            }

            return [
                $t->short_id,
                $title,
                "fuel unpause {$t->short_id}",
            ];
        })->toArray();

        $table = new Table;
        $table->render($headers, $rows, $this->output, $columnPriorities);
    }

    /**
     * Render paused epics in a TUI table.
     *
     * @param  \Illuminate\Support\Collection<int, Epic>  $epics
     */
    private function renderEpicsTable($epics): void
    {
        $headers = ['ID', 'Title', 'Unpause Command'];

        // Column priorities: lower = more important, higher gets dropped first
        $columnPriorities = [
            1,  // ID - keep
            1,  // Title - keep
            1,  // Unpause Command - keep
        ];

        $rows = $epics->map(function (Epic $e): array {
            // Get first line of title, then truncate if needed
            $title = strtok($e->title, "\r\n") ?: $e->title;
            if (mb_strlen($title) > 40) {
                $title = mb_substr($title, 0, 37).'...';
            }

            return [
                $e->short_id,
                $title,
                "fuel unpause {$e->short_id}",
            ];
        })->toArray();

        $table = new Table;
        $table->render($headers, $rows, $this->output, $columnPriorities);
    }
}
