<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class BoardCommand extends Command
{
    protected $signature = 'board
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Display tasks in a kanban board layout';

    private int $columnWidth;

    public function handle(TaskService $taskService): int
    {
        if ($cwd = $this->option('cwd')) {
            $taskService->setStoragePath($cwd.'/.fuel/tasks.jsonl');
        }

        $this->columnWidth = $this->calculateColumnWidth();

        $readyTasks = $taskService->ready();
        $readyIds = $readyTasks->pluck('id')->toArray();

        $allTasks = $taskService->all();

        $blockedTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'open' && ! in_array($t['id'], $readyIds))
            ->values();

        $doneTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'closed')
            ->sortByDesc('updated_at')
            ->take(5)
            ->values();

        if ($this->option('json')) {
            $this->line(json_encode([
                'ready' => $readyTasks->values()->toArray(),
                'blocked' => $blockedTasks->toArray(),
                'done' => $doneTasks->toArray(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $readyColumn = $this->buildColumn('Ready', $readyTasks->all());
        $blockedColumn = $this->buildColumn('Blocked', $blockedTasks->all());
        $doneColumn = $this->buildColumn('Done', $doneTasks->all());

        $maxHeight = max(count($readyColumn), count($blockedColumn), count($doneColumn));

        $readyColumn = $this->padColumn($readyColumn, $maxHeight);
        $blockedColumn = $this->padColumn($blockedColumn, $maxHeight);
        $doneColumn = $this->padColumn($doneColumn, $maxHeight);

        $rows = array_map(null, $readyColumn, $blockedColumn, $doneColumn);

        foreach ($rows as $row) {
            $this->line(implode('  ', $row));
        }

        return self::SUCCESS;
    }

    /**
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
                $truncatedTitle = $this->truncate($taskTitle, $this->columnWidth - 7);
                $lines[] = $this->padLine("<fg=cyan>[{$shortId}]</> {$truncatedTitle}");
            }
        }

        return $lines;
    }

    /**
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

    private function padLine(string $line): string
    {
        $visibleLength = $this->visibleLength($line);
        $padding = max(0, $this->columnWidth - $visibleLength);

        return $line.str_repeat(' ', $padding);
    }

    private function visibleLength(string $line): int
    {
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

        $width = (int) (($terminalWidth - 4) / 3);

        return max(25, min(60, $width));
    }
}
