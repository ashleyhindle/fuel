<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class CompletedCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'completed
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--limit=20 : Number of completed tasks to show}';

    protected $description = 'Show a list of recently completed tasks';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        $limit = (int) $this->option('limit');
        if ($limit < 1) {
            $limit = 20;
        }

        $tasks = $taskService->all()
            ->filter(fn (array $t): bool => ($t['status'] ?? '') === 'closed')
            ->sortByDesc('updated_at')
            ->take($limit)
            ->values();

        if ($this->option('json')) {
            $this->outputJson($tasks->toArray());
        } else {
            if ($tasks->isEmpty()) {
                $this->info('No completed tasks found.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Completed tasks (%d):', $tasks->count()));
            $this->newLine();

            $headers = ['ID', 'Title', 'Completed', 'Type', 'Priority'];
            $rows = $tasks->map(fn (array $t): array => [
                $t['id'],
                $t['title'],
                $this->formatDate($t['updated_at']),
                $t['type'] ?? 'task',
                $t['priority'] ?? 2,
            ])->toArray();

            $this->table($headers, $rows);
        }

        return self::SUCCESS;
    }

    /**
     * Format a date string into a human-readable format.
     */
    private function formatDate(string $dateString): string
    {
        try {
            $date = new \DateTime($dateString);
            $now = new \DateTime;
            $diff = $now->diff($date);

            // If less than 1 minute ago
            if ($diff->days === 0 && $diff->h === 0 && $diff->i === 0) {
                return 'just now';
            }

            // If less than 1 hour ago
            if ($diff->days === 0 && $diff->h === 0) {
                $minutes = $diff->i;

                return $minutes.'m ago';
            }

            // If less than 24 hours ago
            if ($diff->days === 0) {
                $hours = $diff->h;

                return $hours.'h ago';
            }

            // If less than 7 days ago
            if ($diff->days < 7) {
                $days = $diff->days;

                return $days.'d ago';
            }

            // If same year, show "Mon Day" (e.g., "Jan 7")
            if ($date->format('Y') === $now->format('Y')) {
                return $date->format('M j');
            }

            // Different year, show "Mon Day, Year" (e.g., "Jan 7, 2025")
            return $date->format('M j, Y');
        } catch (\Exception) {
            // Fallback to original if parsing fails
            return $dateString;
        }
    }
}
