<?php

declare(strict_types=1);

namespace Laravel\Boost\Console\Fuel;

use Illuminate\Console\Command;
use Laravel\Boost\Fuel\TaskService;

class ReadyCommand extends Command
{
    protected $signature = 'fuel:ready
        {--json : Output JSON instead of human-readable}';

    protected $description = 'Show fuel tasks that are ready to work on (no open blockers)';

    public function handle(TaskService $service): int
    {
        $tasks = $service->ready();

        if ($this->option('json')) {
            $this->line((string) json_encode($tasks->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($tasks->isEmpty()) {
            $this->info('No tasks ready. All tasks are either blocked or completed.');

            return self::SUCCESS;
        }

        $this->info("Ready work ({$tasks->count()} tasks):");
        $this->newLine();

        $headers = ['ID', 'Priority', 'Type', 'Title'];
        $rows = $tasks->map(function (array $task): array {
            $id = (string) $task['id']; // @phpstan-ignore cast.string
            $priority = (int) $task['priority']; // @phpstan-ignore cast.int
            $type = (string) $task['type']; // @phpstan-ignore cast.string
            $title = (string) $task['title']; // @phpstan-ignore cast.string

            return [$id, $this->formatPriority($priority), $type, $this->truncate($title, 50)];
        })->all();

        $this->table($headers, $rows);

        $this->showPruneWarningIfNeeded($service);

        return self::SUCCESS;
    }

    private function showPruneWarningIfNeeded(TaskService $service): void
    {
        $stats = $service->getPruneStats();

        if ($stats['should_prune']) {
            $this->newLine();
            $this->warn("Task file has {$stats['total']} tasks (max: {$stats['max']}). Run `php artisan fuel:prune` to remove old closed tasks.");
        }
    }

    private function formatPriority(int $priority): string
    {
        $labels = [
            0 => 'P0 (Critical)',
            1 => 'P1 (High)',
            2 => 'P2 (Medium)',
            3 => 'P3 (Low)',
            4 => 'P4 (Backlog)',
        ];

        return $labels[$priority] ?? "P{$priority}";
    }

    private function truncate(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        return substr($value, 0, $length - 3).'...';
    }
}
