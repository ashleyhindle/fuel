<?php

declare(strict_types=1);

namespace Laravel\Boost\Console\Fuel;

use Illuminate\Console\Command;
use Laravel\Boost\Fuel\TaskService;

class ListCommand extends Command
{
    protected $signature = 'fuel:list
        {--status= : Filter by status (open|in_progress|closed)}
        {--type= : Filter by type (bug|feature|task|epic|chore)}
        {--priority= : Filter by priority (0-4)}
        {--label= : Filter by label}
        {--json : Output JSON instead of human-readable}';

    protected $description = 'List fuel tasks with optional filters';

    public function handle(TaskService $service): int
    {
        /** @var string|null $status */
        $status = $this->option('status');

        /** @var string|null $type */
        $type = $this->option('type');

        /** @var string|null $priority */
        $priority = $this->option('priority');

        /** @var string|null $label */
        $label = $this->option('label');

        $filters = array_filter([
            'status' => $status,
            'type' => $type,
            'priority' => $priority,
            'labels' => $label !== null ? [$label] : null,
        ]);

        $tasks = $service->search($filters);

        if ($this->option('json')) {
            $this->line((string) json_encode($tasks->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($tasks->isEmpty()) {
            $this->info('No tasks found.');

            return self::SUCCESS;
        }

        $this->info("Tasks ({$tasks->count()}):");
        $this->newLine();

        $headers = ['ID', 'Priority', 'Type', 'Status', 'Title'];
        $rows = $tasks->map(function (array $task): array {
            $id = (string) $task['id']; // @phpstan-ignore cast.string
            $priority = (string) $task['priority']; // @phpstan-ignore cast.string
            $type = (string) $task['type']; // @phpstan-ignore cast.string
            $status = (string) $task['status']; // @phpstan-ignore cast.string
            $title = (string) $task['title']; // @phpstan-ignore cast.string

            return [$id, 'P'.$priority, $type, $status, $this->truncate($title, 50)];
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

    private function truncate(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        return substr($value, 0, $length - 3).'...';
    }
}
