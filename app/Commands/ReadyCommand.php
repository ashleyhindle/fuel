<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\TaskService;
use App\TUI\Table;
use LaravelZero\Framework\Commands\Command;

class ReadyCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'ready
        {--json : Output as JSON}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Show all open (non-done) tasks';

    public function handle(TaskService $taskService): int
    {
        $tasks = $taskService->ready();

        if ($this->option('json')) {
            $this->outputJson($tasks->values()->map(fn (Task $t): array => $t->toArray())->toArray());
        } else {
            if ($tasks->isEmpty()) {
                $this->info('No open tasks.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Open tasks (%d):', $tasks->count()));
            $this->newLine();

            // Detect terminal width
            $terminalWidth = $this->getTerminalWidth();

            $table = new Table;

            // Column priorities: lower = more important, higher gets dropped first
            $columnPriorities = [
                1,  // ID - keep
                1,  // Title - keep
                3,  // Complexity - drop if needed
                2,  // Epic - keep if possible
                4,  // Created - drop first
            ];

            $table->render(
                ['ID', 'Title', 'Complexity', 'Epic', 'Created'],
                $tasks->map(function (Task $t): array {
                    // Get first line of title, then truncate if needed
                    $title = strtok($t->title, "\r\n") ?: $t->title;
                    if (mb_strlen($title) > 60) {
                        $title = mb_substr($title, 0, 57).'...';
                    }

                    return [
                        $t->short_id,
                        ($t->type === 'selfguided' ? '∞ ' : '').$title,
                        $t->complexity ?? '-',
                        $t->epic?->title ?? '-',
                        $this->formatDate((string) $t->created_at),
                    ];
                })->toArray(),
                $this->output,
                $columnPriorities,
                $terminalWidth
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
