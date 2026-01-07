<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

trait RendersBoardColumns
{
    /**
     * @param  array<int, string>  $column
     * @return array<int, string>
     */
    private function padColumn(array $column, int $height, int $width): array
    {
        $emptyLine = str_repeat(' ', $width);

        while (count($column) < $height) {
            $column[] = $emptyLine;
        }

        return $column;
    }

    private function padLine(string $line, int $width): string
    {
        $visibleLength = $this->visibleLength($line);
        $padding = max(0, $width - $visibleLength);

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

    private function getTerminalWidth(): int
    {
        // Try to get terminal size from stty (most accurate, updates on resize)
        if (function_exists('shell_exec') && stream_isatty(STDOUT)) {
            $sttyOutput = @shell_exec('stty size 2>/dev/null');
            if ($sttyOutput !== null) {
                $parts = explode(' ', trim($sttyOutput));
                if (count($parts) === 2 && is_numeric($parts[1])) {
                    return (int) $parts[1];
                }
            }
        }

        // Fall back to COLUMNS environment variable
        $columns = getenv('COLUMNS');
        if ($columns !== false && is_numeric($columns)) {
            return (int) $columns;
        }

        // Default fallback
        return 120;
    }

    /**
     * Generate a hash of board content for change detection.
     *
     * @param  array<string, array<string>>  $columnIds  Map of column name to array of task IDs
     */
    private function hashBoardContent(array $columnIds): string
    {
        return hash('xxh128', json_encode($columnIds, JSON_UNESCAPED_SLASHES));
    }
}
