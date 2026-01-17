<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use App\TUI\Table;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Terminal;

class BacklogCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'backlog
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

            // Calculate max title width to fit terminal
            $terminal = new Terminal;
            $terminalWidth = $terminal->getWidth();
            $idWidth = 10; // 'f-xxxxxx' + padding
            $createdWidth = 9; // '3d ago' or 'Jan 15' + padding
            $borders = 12; // Table borders and column separators
            $maxTitleWidth = max(20, $terminalWidth - $idWidth - $createdWidth - $borders);

            $table = new Table;
            $table->render(
                ['ID', 'Title', 'Created'],
                $items->map(fn ($item): array => [
                    $item->short_id,
                    $this->truncateTitle($item->title, $maxTitleWidth),
                    $this->formatDate((string) $item->created_at),
                ])->toArray(),
                $this->output
            );
        }

        return self::SUCCESS;
    }

    private function truncateTitle(string $title, int $maxWidth): string
    {
        // Get first line only
        $title = strtok($title, "\r\n") ?: $title;

        // Use mb_strwidth for accurate width calculation (handles emoji, multibyte chars)
        if (mb_strwidth($title) <= $maxWidth) {
            return $title;
        }

        // Truncate using mb_strimwidth to preserve multibyte chars
        // -3 for '...' suffix
        return mb_strimwidth($title, 0, $maxWidth - 3, '...');
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
