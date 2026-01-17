<?php

declare(strict_types=1);

namespace App\TUI;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Table renderer using Unicode box-drawing characters.
 */
class Table
{
    /**
     * Render a table with headers and rows.
     *
     * @param  array<string>  $headers  Column headers
     * @param  array<array<string>>  $rows  Table rows (each row is an array of cell values)
     * @param  OutputInterface  $output  Output interface to write to
     * @param  array<int>  $columnPriorities  Column priorities (lower = more important, dropped first if space needed)
     * @param  int|null  $maxWidth  Maximum table width (defaults to terminal width)
     */
    public function render(array $headers, array $rows, OutputInterface $output, array $columnPriorities = [], ?int $maxWidth = null): void
    {
        if ($headers === []) {
            return;
        }

        $lines = $this->buildTable($headers, $rows, $columnPriorities, $maxWidth);
        foreach ($lines as $line) {
            $output->writeln($line);
        }
    }

    /**
     * Build table lines as an array.
     *
     * @param  array<string>  $headers  Column headers
     * @param  array<array<string>>  $rows  Table rows
     * @param  array<int>  $columnPriorities  Column priorities (lower = more important)
     * @param  int|null  $maxWidth  Maximum table width
     * @return array<string> Array of table lines ready to render
     */
    public function buildTable(array $headers, array $rows, array $columnPriorities = [], ?int $maxWidth = null): array
    {
        if ($headers === []) {
            return [];
        }

        $numColumns = count($headers);

        // Get terminal width if not specified
        if ($maxWidth === null) {
            $maxWidth = $this->getTerminalWidth();
        }

        // Fit columns to width
        [$headers, $rows] = $this->fitToWidth($headers, $rows, $columnPriorities, $maxWidth);

        $numColumns = count($headers);
        $columnWidths = $this->calculateColumnWidths($headers, $rows, $numColumns);

        // Apply smart truncation if needed
        [$columnWidths, $rows] = $this->applySmartTruncation($headers, $rows, $columnWidths, $maxWidth);

        $lines = [];

        // Top border
        $lines[] = $this->topBorder($columnWidths);

        // Header row
        $lines[] = $this->headerRow($headers, $columnWidths);

        // Header separator
        $lines[] = $this->headerSeparator($columnWidths);

        // Data rows
        foreach ($rows as $row) {
            // Ensure row has correct number of columns
            $paddedRow = array_pad(array_slice($row, 0, $numColumns), $numColumns, '');
            $lines[] = $this->dataRow($paddedRow, $columnWidths);
        }

        // Bottom border
        $lines[] = $this->bottomBorder($columnWidths);

        return $lines;
    }

    /**
     * Calculate column widths based on headers and content.
     *
     * @param  array<string>  $headers  Column headers
     * @param  array<array<string>>  $rows  Table rows
     * @param  int  $numColumns  Number of columns
     * @return array<int> Column widths
     */
    private function calculateColumnWidths(array $headers, array $rows, int $numColumns): array
    {
        $widths = [];

        // Start with header widths
        for ($i = 0; $i < $numColumns; $i++) {
            $widths[$i] = $this->visibleLength($headers[$i] ?? '');
        }

        // Update with row content widths
        foreach ($rows as $row) {
            for ($i = 0; $i < $numColumns; $i++) {
                $cellValue = $row[$i] ?? '';
                $cellWidth = $this->visibleLength((string) $cellValue);
                if ($cellWidth > $widths[$i]) {
                    $widths[$i] = $cellWidth;
                }
            }
        }

        // Ensure minimum width of 1
        foreach ($widths as $i => $width) {
            $widths[$i] = max(1, $width);
        }

        return $widths;
    }

    /**
     * Generate top border.
     *
     * @param  array<int>  $columnWidths  Column widths
     * @return string Top border line
     */
    private function topBorder(array $columnWidths): string
    {
        $line = '╭';
        $numColumns = count($columnWidths);

        for ($i = 0; $i < $numColumns; $i++) {
            $line .= str_repeat('─', $columnWidths[$i] + 2); // +2 for padding
            if ($i < $numColumns - 1) {
                $line .= '┬';
            }
        }

        return $line.'╮';
    }

    /**
     * Generate header row.
     *
     * @param  array<string>  $headers  Column headers
     * @param  array<int>  $columnWidths  Column widths
     * @return string Header row line
     */
    private function headerRow(array $headers, array $columnWidths): string
    {
        $line = '│';
        $numColumns = count($columnWidths);

        for ($i = 0; $i < $numColumns; $i++) {
            $header = $headers[$i] ?? '';
            $width = $columnWidths[$i];
            $padding = $width - $this->visibleLength($header);
            $line .= ' '.$header.str_repeat(' ', $padding).' │';
        }

        return $line;
    }

    /**
     * Generate header separator (between header and data).
     *
     * @param  array<int>  $columnWidths  Column widths
     * @return string Header separator line
     */
    private function headerSeparator(array $columnWidths): string
    {
        $line = '├';
        $numColumns = count($columnWidths);

        for ($i = 0; $i < $numColumns; $i++) {
            $line .= str_repeat('─', $columnWidths[$i] + 2); // +2 for padding
            if ($i < $numColumns - 1) {
                $line .= '┼';
            }
        }

        return $line.'┤';
    }

    /**
     * Generate data row.
     *
     * @param  array<string>  $row  Row data
     * @param  array<int>  $columnWidths  Column widths
     * @return string Data row line
     */
    private function dataRow(array $row, array $columnWidths): string
    {
        $line = '│';
        $numColumns = count($columnWidths);

        for ($i = 0; $i < $numColumns; $i++) {
            $cellValue = (string) ($row[$i] ?? '');
            $width = $columnWidths[$i];
            $padding = $width - $this->visibleLength($cellValue);
            $line .= ' '.$cellValue.str_repeat(' ', $padding).' │';
        }

        return $line;
    }

    /**
     * Generate bottom border.
     *
     * @param  array<int>  $columnWidths  Column widths
     * @return string Bottom border line
     */
    private function bottomBorder(array $columnWidths): string
    {
        $line = '╰';
        $numColumns = count($columnWidths);

        for ($i = 0; $i < $numColumns; $i++) {
            $line .= str_repeat('─', $columnWidths[$i] + 2); // +2 for padding
            if ($i < $numColumns - 1) {
                $line .= '┴';
            }
        }

        return $line.'╯';
    }

    /**
     * Calculate visible length accounting for emoji width and ANSI codes.
     *
     * @param  string  $text  Text to measure
     * @return int Visible width
     */
    private function visibleLength(string $text): int
    {
        // Strip ANSI codes and Symfony color tags first
        $plainText = $this->stripAnsi($text);

        // mb_strwidth correctly handles East Asian width and emoji display width
        return mb_strwidth($plainText);
    }

    /**
     * Strip ANSI escape codes and Symfony color tags from text.
     *
     * @param  string  $text  Text to strip
     * @return string Plain text without ANSI codes
     */
    private function stripAnsi(string $text): string
    {
        // Strip Symfony/Laravel color tags like <fg=cyan>, </>, <bg=red>, etc.
        $text = preg_replace('/<(?:\/|(?:fg|bg|options)(?:=[^>]+)?)>/i', '', $text) ?? $text;

        // Strip ANSI escape codes
        $text = preg_replace('/\e\[[0-9;]*m/', '', $text) ?? $text;

        return $text;
    }

    /**
     * Fit table to specified width by dropping low-priority columns.
     *
     * @param  array<string>  $headers  Column headers
     * @param  array<array<string>>  $rows  Table rows
     * @param  array<int>  $columnPriorities  Column priorities (lower = more important)
     * @param  int  $maxWidth  Maximum width
     * @return array{array<string>, array<array<string>>} [headers, rows] with columns removed if needed
     */
    private function fitToWidth(array $headers, array $rows, array $columnPriorities, int $maxWidth): array
    {
        if ($columnPriorities === [] || count($headers) === 0) {
            return [$headers, $rows];
        }

        $numColumns = count($headers);

        // Calculate total table width
        $tableWidth = $this->calculateTableWidth($headers, $rows, $numColumns);

        // If it fits, return as-is
        if ($tableWidth <= $maxWidth) {
            return [$headers, $rows];
        }

        // Never drop below one column
        if ($numColumns === 1) {
            return [$headers, $rows];
        }

        // Drop columns by priority (highest priority first = lowest importance)
        // Build index => priority map
        $columnMap = [];
        for ($i = 0; $i < $numColumns; $i++) {
            $columnMap[$i] = $columnPriorities[$i] ?? 999; // Default to low priority
        }

        // Sort by priority DESC (drop highest priority numbers first)
        arsort($columnMap);

        $columnsToKeep = range(0, $numColumns - 1);

        foreach (array_keys($columnMap) as $colIndex) {
            // Never drop all columns
            if (count($columnsToKeep) <= 1) {
                break;
            }

            // Try removing this column
            $testKeep = array_values(array_diff($columnsToKeep, [$colIndex]));

            // Never drop all columns
            if (count($testKeep) === 0) {
                continue;
            }

            $testHeaders = array_values(array_intersect_key($headers, array_flip($testKeep)));
            $testRows = array_map(fn (array $row): array => array_values(array_intersect_key($row, array_flip($testKeep))), $rows);

            $testWidth = $this->calculateTableWidth($testHeaders, $testRows, count($testHeaders));

            if ($testWidth <= $maxWidth) {
                // Found a fit
                return [$testHeaders, $testRows];
            }

            // Remove this column and continue
            $columnsToKeep = $testKeep;
        }

        // Return whatever we have left (at least one column)
        $keepHeaders = array_values(array_intersect_key($headers, array_flip($columnsToKeep)));
        $keepRows = array_map(fn (array $row): array => array_values(array_intersect_key($row, array_flip($columnsToKeep))), $rows);

        return [$keepHeaders, $keepRows];
    }

    /**
     * Calculate total table width.
     *
     * @param  array<string>  $headers  Column headers
     * @param  array<array<string>>  $rows  Table rows
     * @param  int  $numColumns  Number of columns
     * @return int Total width
     */
    private function calculateTableWidth(array $headers, array $rows, int $numColumns): int
    {
        $widths = $this->calculateColumnWidths($headers, $rows, $numColumns);

        // Sum column widths + padding (2 per column) + borders (1 per column + 1 for start)
        return array_sum($widths) + ($numColumns * 2) + ($numColumns + 1);
    }

    /**
     * Get terminal width.
     *
     * @return int Terminal width in characters
     */
    private function getTerminalWidth(): int
    {
        // Check environment variables first (allows test override)
        $envWidth = getenv('COLUMNS');
        if ($envWidth !== false && (int) $envWidth > 0) {
            return (int) $envWidth;
        }

        if (isset($_SERVER['COLUMNS']) && (int) $_SERVER['COLUMNS'] > 0) {
            return (int) $_SERVER['COLUMNS'];
        }

        // Try to get from tput
        $width = @shell_exec('tput cols');
        if ($width !== null && $width !== false) {
            $width = (int) trim($width);
            if ($width > 0) {
                return $width;
            }
        }

        // Default fallback
        return 120;
    }

    /**
     * Apply smart truncation to columns if table is too wide.
     *
     * @param  array<string>  $headers  Column headers
     * @param  array<array<string>>  $rows  Table rows
     * @param  array<int>  $columnWidths  Calculated column widths
     * @param  int  $maxWidth  Maximum table width
     * @return array{array<int>, array<array<string>>} [adjusted column widths, truncated rows]
     */
    private function applySmartTruncation(array $headers, array $rows, array $columnWidths, int $maxWidth): array
    {
        $numColumns = count($headers);
        $tableWidth = array_sum($columnWidths) + ($numColumns * 2) + ($numColumns + 1);

        // If table fits, no truncation needed
        if ($tableWidth <= $maxWidth) {
            return [$columnWidths, $rows];
        }

        // Calculate available space for columns (subtract borders and padding)
        $availableWidth = $maxWidth - ($numColumns * 2) - ($numColumns + 1);
        if ($availableWidth < $numColumns * 4) {
            // Too narrow, just use minimum widths
            return [$columnWidths, $rows];
        }

        // Find columns that can be truncated (prioritize wider columns)
        $adjustedWidths = $columnWidths;
        $totalNeeded = array_sum($columnWidths);
        $reduction = $totalNeeded - $availableWidth;

        // Sort columns by width (widest first) to truncate proportionally
        $columnsByWidth = [];
        foreach ($columnWidths as $idx => $width) {
            $columnsByWidth[$idx] = $width;
        }
        arsort($columnsByWidth);

        // Distribute reduction across widest columns
        foreach (array_keys($columnsByWidth) as $idx) {
            if ($reduction <= 0) {
                break;
            }

            $currentWidth = $adjustedWidths[$idx];
            // Keep minimum width of 10 for readability, or header width + 3
            $minWidth = max(10, $this->visibleLength($headers[$idx]) + 3);

            if ($currentWidth > $minWidth) {
                $canReduce = min($currentWidth - $minWidth, $reduction);
                $adjustedWidths[$idx] = $currentWidth - $canReduce;
                $reduction -= $canReduce;
            }
        }

        // Truncate row content to fit adjusted widths
        $truncatedRows = [];
        foreach ($rows as $row) {
            $truncatedRow = [];
            foreach ($row as $idx => $cell) {
                $maxCellWidth = $adjustedWidths[$idx];
                $cellLength = $this->visibleLength($cell);

                if ($cellLength > $maxCellWidth) {
                    // Truncate with ellipsis
                    $truncatedRow[] = $this->truncateString($cell, $maxCellWidth);
                } else {
                    $truncatedRow[] = $cell;
                }
            }
            $truncatedRows[] = $truncatedRow;
        }

        return [$adjustedWidths, $truncatedRows];
    }

    /**
     * Truncate a string to fit within a given width.
     *
     * @param  string  $string  String to truncate
     * @param  int  $maxWidth  Maximum width
     * @return string Truncated string with ellipsis
     */
    private function truncateString(string $string, int $maxWidth): string
    {
        if ($maxWidth < 4) {
            return str_repeat('.', min(3, $maxWidth));
        }

        // Strip ANSI codes for accurate measurement
        $plain = $this->stripAnsi($string);

        if (mb_strwidth($plain) <= $maxWidth) {
            return $string;
        }

        // Reserve space for ellipsis
        $targetWidth = $maxWidth - 3;

        // Binary search to find the right truncation point
        $left = 0;
        $right = mb_strlen($plain);
        $result = '';

        while ($left <= $right) {
            $mid = intval(($left + $right) / 2);
            $substr = mb_substr($plain, 0, $mid);

            if (mb_strwidth($substr) <= $targetWidth) {
                $result = $substr;
                $left = $mid + 1;
            } else {
                $right = $mid - 1;
            }
        }

        return $result.'...';
    }
}
