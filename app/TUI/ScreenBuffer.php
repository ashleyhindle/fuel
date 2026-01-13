<?php

declare(strict_types=1);

namespace App\TUI;

/**
 * Manages screen content for differential rendering and text selection.
 *
 * Stores both the formatted (ANSI) content for rendering and the plain text
 * content for selection/comparison. Each row is stored as a string with the
 * full terminal width.
 */
class ScreenBuffer
{
    /** @var array<int, string> Formatted lines with ANSI codes (1-indexed by row) */
    private array $lines = [];

    /** @var array<int, string> Plain text lines without ANSI codes (1-indexed by row) */
    private array $plainLines = [];

    /**
     * Clickable regions mapped by identifier.
     *
     * @var array<string, array{startRow: int, endRow: int, startCol: int, endCol: int, type: string, data: mixed}>
     */
    private array $regions = [];

    public function __construct(/** Terminal width */
    private int $width, /** Terminal height */
    private int $height)
    {
        $this->clear();
    }

    /**
     * Clear the buffer and fill with empty lines.
     */
    public function clear(): void
    {
        $emptyLine = str_repeat(' ', $this->width);
        $this->lines = [];
        $this->plainLines = [];
        $this->regions = [];

        for ($row = 1; $row <= $this->height; $row++) {
            $this->lines[$row] = $emptyLine;
            $this->plainLines[$row] = $emptyLine;
        }
    }

    /**
     * Register a clickable region.
     *
     * @param  string  $id  Unique identifier (e.g., task ID like "f-abc123")
     * @param  int  $startRow  1-indexed start row
     * @param  int  $endRow  1-indexed end row
     * @param  int  $startCol  1-indexed start column
     * @param  int  $endCol  1-indexed end column
     * @param  string  $type  Region type (e.g., "task", "modal", "button")
     * @param  mixed  $data  Optional associated data
     */
    public function registerRegion(
        string $id,
        int $startRow,
        int $endRow,
        int $startCol,
        int $endCol,
        string $type = 'task',
        mixed $data = null
    ): void {
        $this->regions[$id] = [
            'startRow' => $startRow,
            'endRow' => $endRow,
            'startCol' => $startCol,
            'endCol' => $endCol,
            'type' => $type,
            'data' => $data,
        ];
    }

    /**
     * Find which region (if any) contains the given position.
     *
     * @param  int  $row  1-indexed row
     * @param  int  $col  1-indexed column
     * @return array{id: string, startRow: int, endRow: int, startCol: int, endCol: int, type: string, data: mixed}|null
     */
    public function getRegionAt(int $row, int $col): ?array
    {
        foreach ($this->regions as $id => $region) {
            if ($row >= $region['startRow'] && $row <= $region['endRow'] &&
                $col >= $region['startCol'] && $col <= $region['endCol']) {
                return array_merge(['id' => $id], $region);
            }
        }

        return null;
    }

    /**
     * Get all registered regions.
     *
     * @return array<string, array{startRow: int, endRow: int, startCol: int, endCol: int, type: string, data: mixed}>
     */
    public function getRegions(): array
    {
        return $this->regions;
    }

    /**
     * Get a specific region by ID.
     *
     * @return array{startRow: int, endRow: int, startCol: int, endCol: int, type: string, data: mixed}|null
     */
    public function getRegion(string $id): ?array
    {
        return $this->regions[$id] ?? null;
    }

    /**
     * Set a line at a specific row (1-indexed).
     *
     * @param  int  $row  1-indexed row number
     * @param  string  $content  Content with optional ANSI codes
     */
    public function setLine(int $row, string $content): void
    {
        if ($row < 1 || $row > $this->height) {
            return;
        }

        // Pad the line to full width
        $visibleLength = $this->visibleLength($content);
        $padding = max(0, $this->width - $visibleLength);
        $paddedContent = $content.str_repeat(' ', $padding);

        $this->lines[$row] = $paddedContent;
        $this->plainLines[$row] = $this->stripAnsi($paddedContent);
    }

    /**
     * Get the formatted line at a row (1-indexed).
     */
    public function getLine(int $row): string
    {
        return $this->lines[$row] ?? str_repeat(' ', $this->width);
    }

    /**
     * Get the plain text at a row (1-indexed).
     */
    public function getPlainLine(int $row): string
    {
        return $this->plainLines[$row] ?? str_repeat(' ', $this->width);
    }

    /**
     * Get all formatted lines as an array (1-indexed).
     *
     * @return array<int, string>
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * Get all plain text lines as an array (1-indexed).
     *
     * @return array<int, string>
     */
    public function getPlainLines(): array
    {
        return $this->plainLines;
    }

    /**
     * Extract text from a selection range.
     *
     * @param  int  $startRow  1-indexed start row
     * @param  int  $startCol  1-indexed start column
     * @param  int  $endRow  1-indexed end row
     * @param  int  $endCol  1-indexed end column
     * @return string The selected text
     */
    public function extractSelection(int $startRow, int $startCol, int $endRow, int $endCol): string
    {
        // Normalize so start is before end
        if ($startRow > $endRow || ($startRow === $endRow && $startCol > $endCol)) {
            [$startRow, $startCol, $endRow, $endCol] = [$endRow, $endCol, $startRow, $startCol];
        }

        $text = '';

        if ($startRow === $endRow) {
            // Single line selection
            $line = $this->getPlainLine($startRow);
            $text = mb_substr($line, $startCol - 1, $endCol - $startCol + 1);
        } else {
            // Multi-line selection
            for ($row = $startRow; $row <= $endRow; $row++) {
                $line = $this->getPlainLine($row);

                if ($row === $startRow) {
                    // First line: from startCol to end
                    $text .= rtrim(mb_substr($line, $startCol - 1))."\n";
                } elseif ($row === $endRow) {
                    // Last line: from start to endCol
                    $text .= mb_substr($line, 0, $endCol);
                } else {
                    // Middle lines: full line
                    $text .= rtrim($line)."\n";
                }
            }
        }

        return rtrim($text);
    }

    /**
     * Get the character at a specific position.
     *
     * @param  int  $row  1-indexed row
     * @param  int  $col  1-indexed column
     */
    public function charAt(int $row, int $col): string
    {
        $line = $this->getPlainLine($row);

        return mb_substr($line, $col - 1, 1);
    }

    /**
     * Compare with another buffer and return changed row numbers.
     *
     * @return array<int> 1-indexed row numbers that differ
     */
    public function diffRows(ScreenBuffer $other): array
    {
        $changed = [];

        for ($row = 1; $row <= $this->height; $row++) {
            // Compare plain text versions (ignores ANSI code differences)
            if ($this->getPlainLine($row) !== $other->getPlainLine($row)) {
                $changed[] = $row;
            }
        }

        return $changed;
    }

    /**
     * Resize the buffer (clears content).
     */
    public function resize(int $width, int $height): void
    {
        $this->width = $width;
        $this->height = $height;
        $this->clear();
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Strip ANSI codes from a string.
     */
    private function stripAnsi(string $text): string
    {
        // Remove both Laravel's <fg=...>...</> tags and raw ANSI escape sequences
        $stripped = preg_replace('/<[^>]+>/', '', $text);
        $stripped = preg_replace('/\033\[[0-9;]*m/', '', $stripped ?? $text);

        return $stripped ?? $text;
    }

    /**
     * Calculate visible length of a string (excluding ANSI codes).
     */
    private function visibleLength(string $line): int
    {
        $stripped = $this->stripAnsi($line);

        // Count emoji characters that display as 2 columns wide
        $emojiCount = preg_match_all('/[\x{1F300}-\x{1F9FF}]|[\x{1FA00}-\x{1FAFF}]|[\x{2300}-\x{23FF}]|[\x{25A0}-\x{25FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]/u', $stripped);

        return mb_strlen($stripped) + $emojiCount;
    }
}
