<?php

declare(strict_types=1);

namespace App\TUI;

/**
 * Renders text with an animated gradient effect.
 *
 * Creates a smooth color gradient that cycles through the text,
 * giving a shimmering/wave effect.
 */
class GradientText
{
    /** @var array<int, array{r: int, g: int, b: int}> Color values for each character position */
    private array $colors = [];

    /** @var array<int, string> Characters of the text */
    private array $chars = [];

    /** Frame counter for timing */
    private int $frameCount = 0;

    /** Last frame when gradient shifted */
    private float $lastGradientFrame = 0;

    /** Frames per gradient shift (controls speed) */
    private float $framesPerGradientShift;

    /**
     * Create a new gradient text animator.
     *
     * @param  string  $text  The text to animate
     * @param  int  $baseR  Starting red value (0-255)
     * @param  int  $baseG  Starting green value (0-255)
     * @param  int  $baseB  Starting blue value (0-255)
     * @param  int  $peakR  Peak red value (0-255)
     * @param  int  $peakG  Peak green value (0-255)
     * @param  int  $peakB  Peak blue value (0-255)
     * @param  float  $cycleDuration  Seconds for one complete gradient cycle
     * @param  int  $fps  Target frames per second
     */
    public function __construct(
        string $text,
        int $baseR = 100,
        int $baseG = 100,
        int $baseB = 120,
        int $peakR = 180,
        int $peakG = 180,
        int $peakB = 220,
        float $cycleDuration = 1.5,
        int $fps = 30
    ) {
        $this->chars = mb_str_split($text);
        $charCount = count($this->chars);

        if ($charCount === 0) {
            return;
        }

        // Calculate step sizes for the gradient (goes up then down)
        $halfCount = max(1, (int) floor($charCount / 2));
        $rStep = (int) ceil(($peakR - $baseR) / $halfCount);
        $gStep = (int) ceil(($peakG - $baseG) / $halfCount);
        $bStep = (int) ceil(($peakB - $baseB) / $halfCount);

        // Build color array - gradient up to middle, then back down
        $up = true;
        $multiplier = 0;
        foreach ($this->chars as $i => $char) {
            $this->colors[] = [
                'r' => (int) round($up ? $baseR + $multiplier * $rStep : ($peakR - $multiplier * $rStep)),
                'g' => (int) round($up ? $baseG + $multiplier * $gStep : ($peakG - $multiplier * $gStep)),
                'b' => (int) round($up ? $baseB + $multiplier * $bStep : ($peakB - $multiplier * $bStep)),
            ];
            $multiplier++;
            if ($i >= $halfCount - 1) {
                $up = false;
                $multiplier = 0;
            }
        }

        // Calculate timing
        $this->framesPerGradientShift = ($fps * $cycleDuration) / $charCount;
    }

    /**
     * Create a gradient with cyan/blue tones (good for "Connecting" style text).
     */
    public static function cyan(string $text, float $cycleDuration = 1.2): self
    {
        return new self(
            text: $text,
            baseR: 80,
            baseG: 140,
            baseB: 160,
            peakR: 140,
            peakG: 220,
            peakB: 255,
            cycleDuration: $cycleDuration
        );
    }

    /**
     * Create a gradient with yellow/orange tones (good for "Fuel" branding).
     */
    public static function fuel(string $text, float $cycleDuration = 1.5): self
    {
        return new self(
            text: $text,
            baseR: 180,
            baseG: 140,
            baseB: 60,
            peakR: 255,
            peakG: 200,
            peakB: 100,
            cycleDuration: $cycleDuration
        );
    }

    /**
     * Create a gradient with purple tones.
     */
    public static function purple(string $text, float $cycleDuration = 1.2): self
    {
        return new self(
            text: $text,
            baseR: 120,
            baseG: 100,
            baseB: 160,
            peakR: 200,
            peakG: 160,
            peakB: 255,
            cycleDuration: $cycleDuration
        );
    }

    /**
     * Advance the animation by one frame and return the rendered string.
     *
     * Call this in your animation loop to get the next frame's output.
     */
    public function render(): string
    {
        if ($this->chars === []) {
            return '';
        }

        // Advance gradient colors based on timing
        if ($this->frameCount - $this->lastGradientFrame >= $this->framesPerGradientShift) {
            // Rotate colors array (creates the wave effect)
            $last = array_pop($this->colors);
            array_unshift($this->colors, $last);
            $this->lastGradientFrame = $this->frameCount;
        }

        // Build the colored string
        $output = '';
        foreach ($this->chars as $i => $char) {
            $color = $this->colors[$i] ?? ['r' => 128, 'g' => 128, 'b' => 128];
            $output .= sprintf("\e[38;2;%d;%d;%dm%s", $color['r'], $color['g'], $color['b'], $char);
        }
        $output .= "\e[0m"; // Reset color

        $this->frameCount++;

        return $output;
    }

    /**
     * Get the visible length of the text (without ANSI codes).
     */
    public function length(): int
    {
        return count($this->chars);
    }
}
