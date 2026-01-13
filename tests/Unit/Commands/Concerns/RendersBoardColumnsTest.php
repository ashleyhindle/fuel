<?php

declare(strict_types=1);

use App\Commands\Concerns\RendersBoardColumns;

// Test helper class to expose private trait methods
class RendersBoardColumnsTestable
{
    use RendersBoardColumns;

    public function testVisibleLength(string $line): int
    {
        return $this->visibleLength($line);
    }

    public function testTruncate(string $value, int $length): string
    {
        return $this->truncate($value, $length);
    }

    public function testPadLine(string $line, int $width): string
    {
        return $this->padLine($line, $width);
    }
}

beforeEach(function (): void {
    $this->renderer = new RendersBoardColumnsTestable;
});

describe('visibleLength', function (): void {
    it('calculates length of plain text correctly', function (): void {
        expect($this->renderer->testVisibleLength('plain text'))->toBe(10);
        expect($this->renderer->testVisibleLength('hello'))->toBe(5);
        expect($this->renderer->testVisibleLength(''))->toBe(0);
    });

    it('strips ANSI color codes from length calculation', function (): void {
        // Text with Laravel/Symfony console color tags
        expect($this->renderer->testVisibleLength('<fg=red>red</>'))->toBe(3);
        expect($this->renderer->testVisibleLength('<fg=green>green text</>'))->toBe(10);
        expect($this->renderer->testVisibleLength('<fg=cyan;options=bold>bold cyan</>'))->toBe(9);
    });

    it('counts basic emojis as 2 columns wide', function (): void {
        // ⚡ (U+26A1) - Miscellaneous Symbols block
        expect($this->renderer->testVisibleLength('⚡'))->toBe(2);
        expect($this->renderer->testVisibleLength('test ⚡'))->toBe(7); // 5 + 2

        // ⚠ (U+26A0) - Warning sign
        expect($this->renderer->testVisibleLength('⚠ warning'))->toBe(10); // 8 + 2
    });

    it('counts low battery emoji as 2 columns wide', function (): void {
        // 🪫 (U+1FAAB) - Symbols and Pictographs Extended-A block
        expect($this->renderer->testVisibleLength('🪫'))->toBe(2);
        expect($this->renderer->testVisibleLength('low 🪫'))->toBe(6); // 4 + 2
    });

    it('counts miscellaneous technical emojis as 2 columns wide', function (): void {
        // ⏳ (U+23F3) - Hourglass
        expect($this->renderer->testVisibleLength('⏳'))->toBe(2);
        expect($this->renderer->testVisibleLength('⏳ waiting'))->toBe(10); // 8 + 2

        // ⏸ (U+23F8) - Pause button
        expect($this->renderer->testVisibleLength('⏸ paused'))->toBe(9); // 7 + 2
    });

    it('counts geometric shape emojis as 2 columns wide', function (): void {
        // ▶ (U+25B6) - Play button
        expect($this->renderer->testVisibleLength('▶'))->toBe(2);
        expect($this->renderer->testVisibleLength('▶ play'))->toBe(7); // 5 + 2
    });

    it('counts dingbat characters as 2 columns wide', function (): void {
        // ✓ (U+2713) - Check mark
        expect($this->renderer->testVisibleLength('✓'))->toBe(2);
        expect($this->renderer->testVisibleLength('done ✓'))->toBe(7); // 5 + 2

        // ✗ (U+2717) - Ballot X
        expect($this->renderer->testVisibleLength('✗ failed'))->toBe(9); // 7 + 2
    });

    it('counts common UI emojis as 2 columns wide', function (): void {
        // 🚀 (U+1F680) - Rocket
        expect($this->renderer->testVisibleLength('🚀'))->toBe(2);

        // 🔍 (U+1F50D) - Magnifying glass
        expect($this->renderer->testVisibleLength('🔍 search'))->toBe(9); // 7 + 2

        // 💀 (U+1F480) - Skull
        expect($this->renderer->testVisibleLength('💀 dead'))->toBe(7); // 5 + 2

        // 🔄 (U+1F504) - Counterclockwise arrows
        expect($this->renderer->testVisibleLength('🔄 retry'))->toBe(8); // 6 + 2
    });

    it('handles multiple emojis correctly', function (): void {
        // ⚡ + 🪫 = 2 + 2 = 4 extra columns
        expect($this->renderer->testVisibleLength('say hi ⚡ 🪫'))->toBe(12); // 8 chars + 4 emoji width

        // Three emojis
        expect($this->renderer->testVisibleLength('⚡🔄💀'))->toBe(6); // 3 chars + 3 extra
    });

    it('handles emojis with color codes', function (): void {
        expect($this->renderer->testVisibleLength('<fg=yellow>⚡ charging</>'))->toBe(11); // 9 + 2
        expect($this->renderer->testVisibleLength('<fg=red>💀 dead</>'))->toBe(7); // 5 + 2
    });

    it('handles task card content lines correctly', function (): void {
        // Simulating actual task card content: "│ title ⚡ 🪫 │"
        // The border chars are regular width
        expect($this->renderer->testVisibleLength('│ say hi ⚡ 🪫'))->toBe(14); // 10 + 4
    });
});

describe('truncate', function (): void {
    it('does not truncate short strings', function (): void {
        expect($this->renderer->testTruncate('short', 10))->toBe('short');
        expect($this->renderer->testTruncate('exact len!', 10))->toBe('exact len!');
    });

    it('truncates long strings with ellipsis', function (): void {
        expect($this->renderer->testTruncate('this is a long string', 10))->toBe('this is...');
        expect($this->renderer->testTruncate('hello world', 8))->toBe('hello...');
    });

    it('handles edge case lengths', function (): void {
        expect($this->renderer->testTruncate('abcdefghij', 5))->toBe('ab...');
        expect($this->renderer->testTruncate('abc', 3))->toBe('abc');
    });
});

describe('padLine', function (): void {
    it('pads short lines to target width', function (): void {
        $padded = $this->renderer->testPadLine('test', 10);
        expect($padded)->toBe('test      ');
        expect(strlen($padded))->toBe(10);
    });

    it('does not pad lines already at target width', function (): void {
        $padded = $this->renderer->testPadLine('exactly 10', 10);
        expect($padded)->toBe('exactly 10');
    });

    it('accounts for emojis when padding', function (): void {
        // "test ⚡" has visible length 7 (5 chars + 2 for emoji)
        // Padding to width 10 should add 3 spaces
        $padded = $this->renderer->testPadLine('test ⚡', 10);
        expect($padded)->toBe('test ⚡   ');
    });

    it('accounts for color codes when padding', function (): void {
        // "<fg=red>red</>" has visible length 3
        // Padding to width 10 should add 7 spaces
        $padded = $this->renderer->testPadLine('<fg=red>red</>', 10);
        expect($padded)->toBe('<fg=red>red</>       ');
    });
});
