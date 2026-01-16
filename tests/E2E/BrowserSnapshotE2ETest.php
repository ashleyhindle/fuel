<?php

declare(strict_types=1);

namespace Tests\E2E;

uses(BrowserE2ETestCase::class);
uses()->group('e2e', 'browser', 'snapshot');

it('can get accessibility snapshot of a page', function () {
    $browser = $this->createTestBrowser();

    try {
        // Navigate to example.com
        $this->navigateTo($browser['pageId'], 'https://example.com');

        // Get snapshot
        $snapshot = $this->getSnapshot($browser['pageId']);

        expect($snapshot)->toHaveKey('text');
        expect($snapshot)->toHaveKey('refCount');
        expect($snapshot['refCount'])->toBeGreaterThan(0);

        // Verify snapshot contains expected structure
        $snapshotText = $snapshot['text'];
        expect($snapshotText)->toContain('[ref=@e');
        expect($snapshotText)->toContain('document');

        // Parse refs
        $refs = $this->parseSnapshotRefs($snapshotText);
        expect(count($refs))->toBe($snapshot['refCount']);

        // Refs should be sequential
        for ($i = 0; $i < count($refs); $i++) {
            expect($refs[$i])->toBe('@e'.($i + 1));
        }

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('can filter snapshot to interactive elements only', function () {
    $browser = $this->createTestBrowser();

    try {
        // Navigate to example.com
        $this->navigateTo($browser['pageId'], 'https://example.com');

        // Get full snapshot
        $fullSnapshot = $this->getSnapshot($browser['pageId'], false);

        // Get interactive-only snapshot
        $interactiveSnapshot = $this->getSnapshot($browser['pageId'], true);

        // Interactive snapshot should have fewer or equal elements
        expect($interactiveSnapshot['refCount'])->toBeLessThanOrEqual($fullSnapshot['refCount']);

        // Verify interactive snapshot doesn't contain non-interactive elements
        $interactiveText = $interactiveSnapshot['text'];

        // Example.com has a link - that should be in interactive snapshot
        $linkRefs = $this->findRefsInSnapshot($interactiveText, 'link');
        expect(count($linkRefs))->toBeGreaterThan(0);

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('assigns unique refs to duplicate elements', function () {
    $browser = $this->createTestBrowser();

    try {
        // Create a test page with duplicate elements
        $testHtml = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Test Page</title></head>
        <body>
            <button>Click Me</button>
            <button>Click Me</button>
            <input type="text" placeholder="Enter text">
            <input type="text" placeholder="Enter text">
            <a href="#1">Link</a>
            <a href="#2">Link</a>
        </body>
        </html>
        HTML;

        // Navigate to data URL
        $dataUrl = 'data:text/html,'.urlencode($testHtml);
        $this->navigateTo($browser['pageId'], $dataUrl);

        // Get snapshot
        $snapshot = $this->getSnapshot($browser['pageId']);
        $snapshotText = $snapshot['text'];

        // Find all button refs
        $buttonLines = array_filter(
            explode("\n", $snapshotText),
            fn ($line) => stripos($line, 'button "Click Me"') !== false
        );

        // Should have 2 buttons with different refs
        expect(count($buttonLines))->toBe(2);

        $buttonRefs = [];
        foreach ($buttonLines as $line) {
            if (preg_match('/\[ref=(@e\d+)\]/', $line, $match)) {
                $buttonRefs[] = $match[1];
            }
        }

        expect(count($buttonRefs))->toBe(2);
        expect($buttonRefs[0])->not->toBe($buttonRefs[1]);

        // Find all link refs
        $linkLines = array_filter(
            explode("\n", $snapshotText),
            fn ($line) => stripos($line, 'link "Link"') !== false
        );

        expect(count($linkLines))->toBe(2);

        $linkRefs = [];
        foreach ($linkLines as $line) {
            if (preg_match('/\[ref=(@e\d+)\]/', $line, $match)) {
                $linkRefs[] = $match[1];
            }
        }

        expect(count($linkRefs))->toBe(2);
        expect($linkRefs[0])->not->toBe($linkRefs[1]);

        // All refs should be unique
        $allRefs = $this->parseSnapshotRefs($snapshotText);
        $uniqueRefs = array_unique($allRefs);
        expect(count($uniqueRefs))->toBe(count($allRefs));

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('handles empty pages gracefully', function () {
    $browser = $this->createTestBrowser();

    try {
        // Navigate to empty page
        $this->navigateTo($browser['pageId'], 'about:blank');

        // Get snapshot
        $snapshot = $this->getSnapshot($browser['pageId']);

        // Should still return a valid snapshot structure
        expect($snapshot)->toHaveKey('text');
        expect($snapshot)->toHaveKey('refCount');

        // Minimal page should still have document
        if (! empty($snapshot['text'])) {
            expect($snapshot['text'])->toContain('document');
        }

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('snapshot refs remain stable across multiple calls', function () {
    $browser = $this->createTestBrowser();

    try {
        // Create a test page
        $testHtml = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Stable Refs Test</title></head>
        <body>
            <button id="btn1">Button 1</button>
            <button id="btn2">Button 2</button>
            <input id="input1" type="text" placeholder="Input 1">
        </body>
        </html>
        HTML;

        $dataUrl = 'data:text/html,'.urlencode($testHtml);
        $this->navigateTo($browser['pageId'], $dataUrl);

        // Get first snapshot
        $snapshot1 = $this->getSnapshot($browser['pageId']);
        $refs1 = $this->parseSnapshotRefs($snapshot1['text']);

        // Get second snapshot
        $snapshot2 = $this->getSnapshot($browser['pageId']);
        $refs2 = $this->parseSnapshotRefs($snapshot2['text']);

        // Refs should be the same
        expect($refs1)->toBe($refs2);

        // Text structure should be identical
        expect($snapshot1['text'])->toBe($snapshot2['text']);

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('can get snapshot in JSON format', function () {
    $browser = $this->createTestBrowser();

    try {
        // Navigate to example.com
        $this->navigateTo($browser['pageId'], 'https://example.com');

        // Get snapshot with --json flag (already handled by runJsonCommand)
        $result = $this->runJsonCommand(['browser:snapshot', $browser['pageId']]);

        expect($result)->toHaveKey('success');
        expect($result['success'])->toBeTrue();
        expect($result)->toHaveKey('snapshot');
        expect($result['snapshot'])->toHaveKey('text');
        expect($result['snapshot'])->toHaveKey('refCount');

        // Verify it's valid JSON structure
        expect(is_array($result))->toBeTrue();
        expect($result['snapshot']['refCount'])->toBeGreaterThan(0);

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('provides meaningful error for non-existent page', function () {
    $browser = $this->createTestBrowser();

    try {
        // Try to get snapshot of non-existent page
        $result = static::runCommand([
            'browser:snapshot',
            'page-does-not-exist',
            '--json',
        ], false);

        expect($result['exitCode'])->not->toBe(0);

        $json = json_decode($result['output'], true);
        expect($json['success'])->toBeFalse();
        expect($json)->toHaveKey('error');
        expect(strtolower($json['error']))->toContain('not found');

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('snapshot works with complex real-world pages', function () {
    $browser = $this->createTestBrowser();

    try {
        // Navigate to httpbin.org forms page (has various input types)
        $this->navigateTo($browser['pageId'], 'https://httpbin.org/forms/post');

        // Wait for page to load
        $this->waitFor($browser['pageId'], 'load');

        // Get snapshot
        $snapshot = $this->getSnapshot($browser['pageId']);

        expect($snapshot['refCount'])->toBeGreaterThan(5); // Should have multiple form elements

        // Check for expected form elements
        $snapshotText = $snapshot['text'];

        // Should have text inputs
        $textboxRefs = $this->findRefsInSnapshot($snapshotText, 'textbox');
        expect(count($textboxRefs))->toBeGreaterThan(0);

        // Should have button(s)
        $buttonRefs = $this->findRefsInSnapshot($snapshotText, 'button');
        expect(count($buttonRefs))->toBeGreaterThan(0);

        // Interactive-only snapshot should have fewer elements
        $interactiveSnapshot = $this->getSnapshot($browser['pageId'], true);
        expect($interactiveSnapshot['refCount'])->toBeLessThan($snapshot['refCount']);

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});
