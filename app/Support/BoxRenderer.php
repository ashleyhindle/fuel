<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Console\OutputStyle;

/**
 * Helper class for rendering styled boxes with Unicode box-drawing characters.
 */
class BoxRenderer
{
    private const BOX_WIDTH = 50;

    public function __construct(
        private OutputStyle $output
    ) {}

    /**
     * Render a styled box with title and content.
     *
     * @param  string  $title  The title to display in the header
     * @param  array<string>  $lines  Content lines to display in the box
     * @param  string|null  $emoji  Optional emoji to prefix the title
     * @param  int|null  $width  Optional width override (default 50)
     */
    public function box(
        string $title,
        array $lines,
        ?string $emoji = null,
        ?int $width = null
    ): void {
        $boxLines = $this->getBoxLines($title, $lines, $emoji, $width);
        foreach ($boxLines as $line) {
            $this->output->writeln($line);
        }
    }

    /**
     * Get box lines as an array without rendering.
     *
     * @param  string  $title  The title to display in the header
     * @param  array<string>  $lines  Content lines to display in the box
     * @param  string|null  $emoji  Optional emoji to prefix the title
     * @param  int|null  $width  Optional width override (default 50)
     * @return array<string> Array of box lines ready to render
     */
    public function getBoxLines(
        string $title,
        array $lines,
        ?string $emoji = null,
        ?int $width = null
    ): array {
        $width = $width ?? self::BOX_WIDTH;
        $contentWidth = $width - 4; // Account for borders and padding

        $header = $emoji ? "{$emoji} {$title}" : $title;

        $boxLines = [];

        // Top border
        $boxLines[] = $this->topBorder($width);

        // Header line
        $boxLines[] = $this->contentLine($header, $width, true);

        // Separator
        $boxLines[] = $this->separator($width);

        // Content lines
        foreach ($lines as $line) {
            // Handle empty lines
            if ($line === '') {
                $boxLines[] = $this->emptyLine($width);

                continue;
            }

            $boxLines[] = $this->contentLine($line, $width);
        }

        // Bottom border
        $boxLines[] = $this->bottomBorder($width);

        return $boxLines;
    }

    /**
     * Render a horizontal rule.
     */
    public function horizontalRule(?int $width = null): string
    {
        $width = $width ?? self::BOX_WIDTH;

        return '├'.str_repeat('─', $width - 2).'┤';
    }

    /**
     * Colorize text with true color ANSI codes.
     *
     * @param  string  $text  The text to colorize
     * @param  string  $hex  Hex color code (with or without #)
     * @return string Text wrapped in ANSI true color codes
     */
    public function colorize(string $text, string $hex): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return "\e[38;2;{$r};{$g};{$b}m{$text}\e[0m";
    }

    /**
     * Generate top border.
     */
    private function topBorder(int $width): string
    {
        return '┌'.str_repeat('─', $width - 2).'┐';
    }

    /**
     * Generate bottom border.
     */
    private function bottomBorder(int $width): string
    {
        return '└'.str_repeat('─', $width - 2).'┘';
    }

    /**
     * Generate separator line.
     */
    private function separator(int $width): string
    {
        return '├'.str_repeat('─', $width - 2).'┤';
    }

    /**
     * Generate empty content line.
     */
    private function emptyLine(int $width): string
    {
        return '│'.str_repeat(' ', $width - 2).'│';
    }

    /**
     * Generate content line with text.
     *
     * @param  string  $text  The text content
     * @param  int  $width  Total box width
     * @param  bool  $isHeader  Whether this is a header line (affects padding)
     */
    private function contentLine(string $text, int $width, bool $isHeader = false): string
    {
        // Strip ANSI codes to calculate visible length
        $visibleText = $this->stripAnsi($text);
        $visibleLength = $this->visibleLength($visibleText);

        // Calculate padding needed
        $contentWidth = $width - 4; // 2 for borders + 2 for side padding
        $padding = max(0, $contentWidth - $visibleLength);

        return '│ '.$text.str_repeat(' ', $padding).' │';
    }

    /**
     * Calculate visible length accounting for emoji width (emojis display as 2 columns).
     */
    private function visibleLength(string $text): int
    {
        // mb_strwidth correctly handles East Asian width and emoji display width
        // Returns the width of string where halfwidth characters count as 1, and fullwidth characters count as 2
        return mb_strwidth($text);
    }

    /**
     * Strip ANSI escape codes and Symfony color tags from text to get visible length.
     */
    private function stripAnsi(string $text): string
    {
        // Strip Symfony/Laravel color tags like <fg=cyan>, </>, <bg=red>, etc.
        // Pattern matches: <fg=...>, <bg=...>, <options=...>, </>
        $text = preg_replace('/<(?:\/|(?:fg|bg|options)(?:=[^>]+)?)>/i', '', $text) ?? $text;
        // Strip ANSI escape codes
        $text = preg_replace('/\e\[[0-9;]*m/', '', $text) ?? $text;

        return $text;
    }
}
