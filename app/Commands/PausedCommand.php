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

        // Don't drop columns - rely on smart truncation instead
        $columnPriorities = [];

        $terminalWidth = $this->getTerminalWidth();

        $rows = $tasks->map(function (Task $t): array {
            // Get first line of title (remove newlines but don't truncate yet)
            $title = strtok($t->title, "\r\n") ?: $t->title;

            return [
                $t->short_id,
                $title,
                "fuel unpause {$t->short_id}",
            ];
        })->toArray();

        $table = new Table;
        $table->render($headers, $rows, $this->output, $columnPriorities, $terminalWidth);
    }

    /**
     * Render paused epics in a TUI table.
     *
     * @param  \Illuminate\Support\Collection<int, Epic>  $epics
     */
    private function renderEpicsTable($epics): void
    {
        $headers = ['ID', 'Title', 'Unpause Command'];

        // Don't drop columns - rely on smart truncation instead
        $columnPriorities = [];

        $terminalWidth = $this->getTerminalWidth();

        $rows = $epics->map(function (Epic $e): array {
            // Get first line of title (remove newlines but don't truncate yet)
            $title = strtok($e->title, "\r\n") ?: $e->title;

            return [
                $e->short_id,
                $title,
                "fuel unpause {$e->short_id}",
            ];
        })->toArray();

        $table = new Table;
        $table->render($headers, $rows, $this->output, $columnPriorities, $terminalWidth);
    }

    /**
     * Get terminal width.
     *
     * @return int Terminal width in characters
     */
    private function getTerminalWidth(): int
    {
        // Check environment variables first (allows test override)
        $envWidth = getenv('COLUMNS');
        if ($envWidth !== false && (int) $envWidth > 0) {
            return (int) $envWidth;
        }

        if (isset($_SERVER['COLUMNS']) && (int) $_SERVER['COLUMNS'] > 0) {
            return (int) $_SERVER['COLUMNS'];
        }

        // Try to get from tput
        $width = @shell_exec('tput cols');
        if ($width !== null && $width !== false) {
            $width = (int) trim($width);
            if ($width > 0) {
                return $width;
            }
        }

        // Default fallback
        return 120;
    }
}
