<?php

declare(strict_types=1);

use App\TUI\ScreenBuffer;

describe('ScreenBuffer', function () {
    test('initializes with correct dimensions', function () {
        $buffer = new ScreenBuffer(80, 24);

        expect($buffer->getWidth())->toBe(80);
        expect($buffer->getHeight())->toBe(24);
    });

    test('sets and gets lines correctly', function () {
        $buffer = new ScreenBuffer(80, 24);

        $buffer->setLine(1, 'Hello World');
        $buffer->setLine(5, 'Line 5 content');

        expect($buffer->getLine(1))->toStartWith('Hello World');
        expect($buffer->getLine(5))->toStartWith('Line 5 content');
    });

    test('pads lines to full width', function () {
        $buffer = new ScreenBuffer(20, 10);

        $buffer->setLine(1, 'Short');

        expect(strlen($buffer->getPlainLine(1)))->toBe(20);
    });

    test('ignores out of range rows', function () {
        $buffer = new ScreenBuffer(80, 24);

        $buffer->setLine(0, 'Should not set');
        $buffer->setLine(25, 'Should not set');

        expect($buffer->getLine(0))->toBe(str_repeat(' ', 80));
        expect($buffer->getLine(25))->toBe(str_repeat(' ', 80));
    });

    test('strips ANSI codes for plain text', function () {
        $buffer = new ScreenBuffer(80, 24);

        $buffer->setLine(1, '<fg=cyan>Colored</> text');

        $plainLine = $buffer->getPlainLine(1);
        expect($plainLine)->toStartWith('Colored text');
        expect($plainLine)->not->toContain('<fg=');
    });

    test('extracts single line selection', function () {
        $buffer = new ScreenBuffer(80, 24);

        $buffer->setLine(1, 'Hello World from ScreenBuffer');

        $selection = $buffer->extractSelection(1, 7, 1, 11);
        expect($selection)->toBe('World');
    });

    test('extracts multi-line selection', function () {
        $buffer = new ScreenBuffer(80, 24);

        $buffer->setLine(1, 'First line');
        $buffer->setLine(2, 'Second line');
        $buffer->setLine(3, 'Third line');

        $selection = $buffer->extractSelection(1, 7, 3, 5);
        expect($selection)->toContain('line');
        expect($selection)->toContain('Second line');
    });

    test('gets character at position', function () {
        $buffer = new ScreenBuffer(80, 24);

        $buffer->setLine(1, 'Hello');

        expect($buffer->charAt(1, 1))->toBe('H');
        expect($buffer->charAt(1, 5))->toBe('o');
    });

    test('resizes and clears content', function () {
        $buffer = new ScreenBuffer(80, 24);

        $buffer->setLine(1, 'Content');
        $buffer->resize(100, 30);

        expect($buffer->getWidth())->toBe(100);
        expect($buffer->getHeight())->toBe(30);
        expect($buffer->getPlainLine(1))->toBe(str_repeat(' ', 100));
    });

    test('clears buffer', function () {
        $buffer = new ScreenBuffer(80, 24);

        $buffer->setLine(1, 'Content');
        $buffer->clear();

        expect($buffer->getPlainLine(1))->toBe(str_repeat(' ', 80));
    });

    test('diffs rows between buffers', function () {
        $buffer1 = new ScreenBuffer(80, 24);
        $buffer2 = new ScreenBuffer(80, 24);

        $buffer1->setLine(1, 'Same');
        $buffer1->setLine(2, 'Different A');
        $buffer1->setLine(3, 'Same');

        $buffer2->setLine(1, 'Same');
        $buffer2->setLine(2, 'Different B');
        $buffer2->setLine(3, 'Same');

        $changed = $buffer1->diffRows($buffer2);

        expect($changed)->toContain(2);
        expect($changed)->not->toContain(1);
        expect($changed)->not->toContain(3);
    });
});
