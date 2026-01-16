<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\TaskService;
use App\TUI\Table;
use LaravelZero\Framework\Commands\Command;

class BlockedCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'blocked
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Show open tasks with unresolved dependencies';

    public function handle(TaskService $taskService): int
    {
        $tasks = $taskService->blocked();

        if ($this->option('json')) {
            $this->outputJson($tasks->values()->map(fn (Task $task): array => $task->toArray())->toArray());
        } else {
            if ($tasks->isEmpty()) {
                $this->info('No blocked tasks.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Blocked tasks (%d):', $tasks->count()));
            $this->newLine();

            $table = new Table;
            $table->render(
                ['ID', 'Title', 'Created'],
                $tasks->map(fn (Task $t): array => [
                    $t->short_id,
                    $t->title,
                    $this->formatDate((string) $t->created_at),
                ])->toArray(),
                $this->output,
                $this->getTerminalWidth()
            );
        }

        return self::SUCCESS;
    }

    private function formatDate(string $dateString): string
    {
        try {
            $date = new \DateTime($dateString);
            $now = new \DateTime;
            $diff = $now->diff($date);

            if ($diff->days === 0 && $diff->h === 0 && $diff->i === 0) {
                return 'just now';
            }

            if ($diff->days === 0 && $diff->h === 0) {
                return $diff->i.'m ago';
            }

            if ($diff->days === 0) {
                return $diff->h.'h ago';
            }

            if ($diff->days < 7) {
                return $diff->days.'d ago';
            }

            if ($date->format('Y') === $now->format('Y')) {
                return $date->format('M j');
            }

            return $date->format('M j, Y');
        } catch (\Exception) {
            return $dateString;
        }
    }

    private function getTerminalWidth(): int
    {
        // Try to get terminal width using tput
        $width = (int) shell_exec('tput cols 2>/dev/null');

        // Fallback to environment variable or default
        if ($width <= 0) {
            $width = (int) ($_ENV['COLUMNS'] ?? 80);
        }

        return max(40, $width); // Ensure minimum width
    }
}
