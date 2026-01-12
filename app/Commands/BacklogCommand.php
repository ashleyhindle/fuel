<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class BacklogCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'backlog
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'List all backlog items';

    public function handle(TaskService $taskService): int
    {
        $items = $taskService->backlog();

        if ($this->option('json')) {
            $this->outputJson($items->map(fn ($item): array => $item->toArray())->values()->toArray());
        } else {
            if ($items->isEmpty()) {
                $this->info('No backlog items.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Backlog items (%d):', $items->count()));
            $this->newLine();

            $this->table(
                ['ID', 'Title', 'Created'],
                $items->map(fn ($item): array => [
                    $item->short_id,
                    $item->title,
                    $this->formatDate((string) $item->created_at),
                ])->toArray()
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
