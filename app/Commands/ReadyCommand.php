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
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

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

            $table = new Table;
            $table->render(
                ['ID', 'Title', 'Complexity', 'Epic', 'Created'],
                $tasks->map(fn (Task $t): array => [
                    $t->short_id,
                    $t->title,
                    $t->complexity ?? '-',
                    $t->epic?->title ?? '-',
                    $this->formatDate((string) $t->created_at),
                ])->toArray(),
                $this->output
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
}
