<?php

declare(strict_types=1);

namespace App\TUI;

/**
 * Non-blocking toast notifications for TUI.
 * Integrates with render loop - call render() each frame to animate.
 */
class Toast
{
    private const STATE_HIDDEN = 'hidden';
    private const STATE_EXPANDING = 'expanding';
    private const STATE_VISIBLE = 'visible';
    private const STATE_COLLAPSING = 'collapsing';

    /** Border characters for toast */
    private const BORDER_LEFT = '▌';
    private const BORDER_RIGHT = '▐';

    /** Color themes by toast type */
    private const THEMES = [
        'success' => [
            'body' => '#1a1a1a',
            'background' => '#bbf7d0',
            'border' => '#19a24a',
        ],
        'warning' => [
            'body' => '#1a1a1a',
            'background' => '#fdba74',
            'border' => '#fa923c',
        ],
        'error' => [
            'body' => '#000000',
            'background' => '#f87171',
            'border' => '#b91c1d',
        ],
        'info' => [
            'body' => '#1a1a1a',
            'background' => '#cefafe',
            'border' => '#67e8f9',
        ],
    ];

    private string $state = self::STATE_HIDDEN;
    private string $message = '';
    private string $type = 'success';
    private int $animationFrame = 0;
    private int $maxWidth = 0;
    private float $visibleUntil = 0;
    private int $durationMs = 1500;
    private int $terminalWidth = 120;
    private int $previousWidth = 0; // Track previous frame width for clearing

    /**
     * Show a toast notification.
     */
    public function show(string $message, string $type = 'success', string $title = '', int $durationMs = 1500): void
    {
        $this->message = $title !== '' ? "{$title}: {$message}" : $message;
        $this->type = $type;
        $this->durationMs = $durationMs;
        $this->maxWidth = mb_strlen($this->message);
        $this->animationFrame = 0;
        $this->previousWidth = 0;
        $this->state = self::STATE_EXPANDING;
        $this->visibleUntil = 0;
    }

    /**
     * Check if toast is currently visible (in any state except hidden).
     */
    public function isVisible(): bool
    {
        return $this->state !== self::STATE_HIDDEN;
    }

    /**
     * Render the toast to output. Call this each frame.
     * Returns true if toast was rendered, false if hidden.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    public function render($output, int $terminalWidth, int $terminalHeight): bool
    {
        $this->terminalWidth = $terminalWidth;

        if ($this->state === self::STATE_HIDDEN) {
            return false;
        }

        $colors = self::THEMES[$this->type] ?? self::THEMES['success'];

        match ($this->state) {
            self::STATE_EXPANDING => $this->renderExpanding($output, $colors),
            self::STATE_VISIBLE => $this->renderVisible($output, $colors),
            self::STATE_COLLAPSING => $this->renderCollapsing($output, $colors),
            default => null,
        };

        return true;
    }

    private function renderExpanding($output, array $colors): void
    {
        $this->animationFrame++;
        $currentWidth = min($this->animationFrame * 4, $this->maxWidth); // 4 chars per frame

        $this->renderToastFrame($output, $currentWidth, $colors);

        if ($currentWidth >= $this->maxWidth) {
            $this->state = self::STATE_VISIBLE;
            $this->visibleUntil = microtime(true) + ($this->durationMs / 1000);
        }
    }

    private function renderVisible($output, array $colors): void
    {
        $this->renderToastFrame($output, $this->maxWidth, $colors);

        if (microtime(true) >= $this->visibleUntil) {
            $this->state = self::STATE_COLLAPSING;
            $this->animationFrame = (int) ceil($this->maxWidth / 4);
        }
    }

    private function renderCollapsing($output, array $colors): void
    {
        $this->animationFrame--;
        $currentWidth = max(0, $this->animationFrame * 4);

        if ($currentWidth <= 0) {
            // Clear the final frame before hiding
            $this->clearToastArea($output);
            $this->state = self::STATE_HIDDEN;
            $this->previousWidth = 0;
            return;
        }

        $this->renderToastFrame($output, $currentWidth, $colors);
    }

    private function renderToastFrame($output, int $currentWidth, array $colors): void
    {
        if ($currentWidth <= 0) {
            return;
        }

        // Clear previous frame area if it was wider
        if ($this->previousWidth > $currentWidth) {
            $this->clearToastArea($output);
        }
        $this->previousWidth = $currentWidth;

        $bgColor = $this->hexToAnsi($colors['background'], true);
        $borderColor = $this->hexToAnsi($colors['border'], false);
        $bodyColor = $this->hexToAnsi($colors['body'], false);
        $reset = "\033[0m";
        $bold = "\033[1m";

        // Calculate position - toast appears from right edge
        // Content width + 2 spaces padding + 2 border chars
        $totalWidth = $currentWidth + 4;
        $startX = $this->terminalWidth - $totalWidth + 1;
        $row = 2;

        $isFullWidth = ($currentWidth >= $this->maxWidth);
        $truncated = mb_substr($this->message, 0, $currentWidth);
        $padded = str_pad($truncated, $currentWidth, ' ', STR_PAD_RIGHT);

        // Position cursor
        $output->write(sprintf("\033[%d;%dH", $row, $startX));

        // Render line with borders
        if ($isFullWidth) {
            // Full toast with both borders
            $output->write("{$borderColor}{$bold}" . self::BORDER_LEFT . "{$reset}");
            $output->write("{$bgColor}{$bodyColor} {$padded} {$reset}");
            $output->write("{$borderColor}{$bold}" . self::BORDER_RIGHT . "{$reset}");
        } else {
            // Partial toast (expanding/collapsing) - only left border
            $output->write("{$borderColor}{$bold}" . self::BORDER_LEFT . "{$reset}");
            $output->write("{$bgColor}{$bodyColor} {$padded} {$reset}");
        }
    }

    /**
     * Clear the toast area to remove artifacts.
     */
    private function clearToastArea($output): void
    {
        if ($this->previousWidth <= 0) {
            return;
        }

        $totalWidth = $this->previousWidth + 4;
        $startX = $this->terminalWidth - $totalWidth + 1;
        $row = 2;

        // Position cursor and clear from there to end of line
        $output->write(sprintf("\033[%d;%dH\033[K", $row, $startX));
    }

    /**
     * Convert hex color to ANSI escape code.
     */
    private function hexToAnsi(string $hex, bool $isBackground): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $code = $isBackground ? 48 : 38;
        return "\033[{$code};2;{$r};{$g};{$b}m";
    }
}
