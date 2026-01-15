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
     * @param  int|null  $maxWidth  Maximum table width (defaults to terminal width)
     */
    public function render(array $headers, array $rows, OutputInterface $output, ?int $maxWidth = null): void
    {
        if ($headers === []) {
            return;
        }

        $lines = $this->buildTable($headers, $rows, $maxWidth);
        foreach ($lines as $line) {
            $output->writeln($line);
        }
    }

    /**
     * Build table lines as an array.
     *
     * @param  array<string>  $headers  Column headers
     * @param  array<array<string>>  $rows  Table rows
     * @param  int|null  $maxWidth  Maximum table width (defaults to terminal width)
     * @return array<string> Array of table lines ready to render
     */
    public function buildTable(array $headers, array $rows, ?int $maxWidth = null): array
    {
        if ($headers === []) {
            return [];
        }

        $numColumns = count($headers);
        $columnWidths = $this->calculateColumnWidths($headers, $rows, $numColumns);

        // Apply terminal width constraints if needed
        if ($maxWidth !== null) {
            $columnWidths = $this->fitToWidth($headers, $rows, $columnWidths, $maxWidth);
        }

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
     * Fit column widths to terminal width by truncating as needed.
     * Truncates Epic column first, then Title column.
     *
     * @param  array<string>  $headers  Column headers
     * @param  array<array<string>>  $rows  Table rows
     * @param  array<int>  $columnWidths  Initial column widths
     * @param  int  $maxWidth  Maximum table width
     * @return array<int> Adjusted column widths
     */
    private function fitToWidth(array $headers, array $rows, array $columnWidths, int $maxWidth): array
    {
        $numColumns = count($columnWidths);

        // Calculate total table width including borders and padding
        // Each column: width + 2 (padding) + 1 (border after)
        // Plus 1 for the left border
        $tableWidth = 1; // Left border
        foreach ($columnWidths as $width) {
            $tableWidth += $width + 2 + 1; // content + padding + right border
        }

        // If we fit, return as-is
        if ($tableWidth <= $maxWidth) {
            return $columnWidths;
        }

        // Find Epic and Title column indices
        $epicIndex = array_search('Epic', $headers, true);
        $titleIndex = array_search('Title', $headers, true);

        $overflow = $tableWidth - $maxWidth;
        $minColumnWidth = 10; // Minimum width for truncated columns

        // Try truncating Epic column first
        if ($epicIndex !== false && $columnWidths[$epicIndex] > $minColumnWidth) {
            $canReduce = $columnWidths[$epicIndex] - $minColumnWidth;
            $reduction = min($canReduce, $overflow);
            $columnWidths[$epicIndex] -= $reduction;
            $overflow -= $reduction;
        }

        // If still overflowing, truncate Title column
        if ($overflow > 0 && $titleIndex !== false && $columnWidths[$titleIndex] > $minColumnWidth) {
            $canReduce = $columnWidths[$titleIndex] - $minColumnWidth;
            $reduction = min($canReduce, $overflow);
            $columnWidths[$titleIndex] -= $reduction;
            $overflow -= $reduction;
        }

        // If still overflowing, reduce both proportionally
        if ($overflow > 0) {
            if ($epicIndex !== false && $titleIndex !== false) {
                $epicReduction = (int) ceil($overflow / 2);
                $titleReduction = $overflow - $epicReduction;

                $columnWidths[$epicIndex] = max(5, $columnWidths[$epicIndex] - $epicReduction);
                $columnWidths[$titleIndex] = max(5, $columnWidths[$titleIndex] - $titleReduction);
            }
        }

        return $columnWidths;
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

            // Truncate if needed
            $cellValue = $this->truncate($cellValue, $width);

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
     * Truncate text to fit within a specified width, adding ellipsis if needed.
     *
     * @param  string  $text  Text to truncate
     * @param  int  $maxWidth  Maximum width
     * @return string Truncated text
     */
    private function truncate(string $text, int $maxWidth): string
    {
        $currentWidth = $this->visibleLength($text);

        if ($currentWidth <= $maxWidth) {
            return $text;
        }

        // We need to truncate - account for ellipsis (…)
        $ellipsis = '…';
        $ellipsisWidth = 1;
        $targetWidth = $maxWidth - $ellipsisWidth;

        if ($targetWidth <= 0) {
            return $ellipsis;
        }

        // Strip ANSI for accurate measurement
        $plainText = $this->stripAnsi($text);

        // Truncate character by character until we fit
        $truncated = '';
        $width = 0;

        for ($i = 0; $i < mb_strlen($plainText); $i++) {
            $char = mb_substr($plainText, $i, 1);
            $charWidth = mb_strwidth($char);

            if ($width + $charWidth > $targetWidth) {
                break;
            }

            $truncated .= $char;
            $width += $charWidth;
        }

        return $truncated.$ellipsis;
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
}
