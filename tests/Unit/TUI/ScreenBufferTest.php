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

    test('registers and retrieves regions', function () {
        $buffer = new ScreenBuffer(80, 24);

        $buffer->registerRegion('f-abc123', 3, 5, 1, 40, 'task');
        $buffer->registerRegion('f-def456', 7, 9, 42, 80, 'task');

        $region = $buffer->getRegion('f-abc123');
        expect($region)->not->toBeNull();
        expect($region['startRow'])->toBe(3);
        expect($region['endRow'])->toBe(5);
        expect($region['startCol'])->toBe(1);
        expect($region['endCol'])->toBe(40);
        expect($region['type'])->toBe('task');
    });

    test('finds region at position', function () {
        $buffer = new ScreenBuffer(80, 24);

        $buffer->registerRegion('f-abc123', 3, 5, 1, 40, 'task');
        $buffer->registerRegion('f-def456', 7, 9, 42, 80, 'task');

        // Should find first region
        $region = $buffer->getRegionAt(4, 20);
        expect($region)->not->toBeNull();
        expect($region['id'])->toBe('f-abc123');

        // Should find second region
        $region = $buffer->getRegionAt(8, 60);
        expect($region)->not->toBeNull();
        expect($region['id'])->toBe('f-def456');

        // Should not find anything outside regions
        $region = $buffer->getRegionAt(6, 20);
        expect($region)->toBeNull();
    });

    test('clears regions on clear', function () {
        $buffer = new ScreenBuffer(80, 24);

        $buffer->registerRegion('f-abc123', 3, 5, 1, 40, 'task');
        $buffer->clear();

        expect($buffer->getRegion('f-abc123'))->toBeNull();
        expect($buffer->getRegions())->toBeEmpty();
    });

    test('gets all regions', function () {
        $buffer = new ScreenBuffer(80, 24);

        $buffer->registerRegion('f-abc123', 3, 5, 1, 40, 'task');
        $buffer->registerRegion('f-def456', 7, 9, 42, 80, 'task');

        $regions = $buffer->getRegions();
        expect($regions)->toHaveCount(2);
        expect($regions)->toHaveKey('f-abc123');
        expect($regions)->toHaveKey('f-def456');
    });

    test('region can store associated data', function () {
        $buffer = new ScreenBuffer(80, 24);

        $buffer->registerRegion('f-abc123', 3, 5, 1, 40, 'task', ['status' => 'in_progress']);

        $region = $buffer->getRegion('f-abc123');
        expect($region['data'])->toBe(['status' => 'in_progress']);
    });
});
