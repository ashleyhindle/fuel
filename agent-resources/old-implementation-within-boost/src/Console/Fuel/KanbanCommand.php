<?php

declare(strict_types=1);

namespace Laravel\Boost\Console\Fuel;

use Illuminate\Console\Command;
use Laravel\Boost\Fuel\TaskService;

class KanbanCommand extends Command
{
    protected $signature = 'fuel:board';

    protected $description = 'Display tasks in a kanban board layout';

    private int $columnWidth;

    public function handle(TaskService $service): int
    {
        $this->columnWidth = $this->calculateColumnWidth();
        $readyTasks = $service->ready();
        $pendingTasks = $service->blocked();
        $closedTasks = $service->search(['status' => 'closed'])
            ->sortByDesc('updated_at')
            ->take(5)
            ->values();

        $readyColumn = $this->buildColumn('Ready', $readyTasks->all());
        $pendingColumn = $this->buildColumn('Pending', $pendingTasks->all());
        $doneColumn = $this->buildColumn('Done', $closedTasks->all());

        $maxHeight = max(count($readyColumn), count($pendingColumn), count($doneColumn));

        $readyColumn = $this->padColumn($readyColumn, $maxHeight);
        $pendingColumn = $this->padColumn($pendingColumn, $maxHeight);
        $doneColumn = $this->padColumn($doneColumn, $maxHeight);

        $rows = array_map(null, $readyColumn, $pendingColumn, $doneColumn);

        foreach ($rows as $row) {
            $this->line(implode('  ', $row));
        }

        return self::SUCCESS;
    }

    /**
     * Build a column of lines for display.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<int, string>
     */
    private function buildColumn(string $title, array $tasks): array
    {
        $lines = [];

        $lines[] = $this->padLine("<fg=white;options=bold>{$title}</> (".count($tasks).')');
        $lines[] = str_repeat('─', $this->columnWidth);

        if (empty($tasks)) {
            $lines[] = $this->padLine('<fg=gray>No tasks</>');
        } else {
            foreach ($tasks as $task) {
                $id = (string) $task['id'];
                $taskTitle = (string) $task['title'];
                $shortId = substr($id, 5, 4); // Skip 'fuel-' prefix
                $truncatedTitle = $this->truncate($taskTitle, $this->columnWidth - 7); // 7 = [xxxx] + space
                $lines[] = $this->padLine("<fg=cyan>[{$shortId}]</> {$truncatedTitle}");
            }
        }

        return $lines;
    }

    /**
     * Pad a column to the specified height.
     *
     * @param  array<int, string>  $column
     * @return array<int, string>
     */
    private function padColumn(array $column, int $height): array
    {
        $emptyLine = str_repeat(' ', $this->columnWidth);

        while (count($column) < $height) {
            $column[] = $emptyLine;
        }

        return $column;
    }

    /**
     * Pad a line to the column width.
     */
    private function padLine(string $line): string
    {
        $visibleLength = $this->visibleLength($line);
        $padding = max(0, $this->columnWidth - $visibleLength);

        return $line.str_repeat(' ', $padding);
    }

    /**
     * Calculate the visible length of a string (excluding ANSI codes).
     */
    private function visibleLength(string $line): int
    {
        // Remove Symfony console formatting tags
        $stripped = preg_replace('/<[^>]+>/', '', $line);

        return mb_strlen($stripped ?? $line);
    }

    private function truncate(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - 3).'...';
    }

    private function calculateColumnWidth(): int
    {
        $columns = getenv('COLUMNS');
        $terminalWidth = $columns !== false ? (int) $columns : 120;

        // 3 columns with 2-space gaps between them (4 chars total for gaps)
        $width = (int) (($terminalWidth - 4) / 3);

        // Minimum width of 25, maximum of 60
        return max(25, min(60, $width));
    }
}
