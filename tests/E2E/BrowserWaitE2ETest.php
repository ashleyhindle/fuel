<?php

declare(strict_types=1);

namespace Tests\E2E;

uses(BrowserE2ETestCase::class);
uses()->group('e2e', 'browser', 'wait');

it('can wait for page load', function (): void {
    $browser = $this->createTestBrowser();

    try {
        // Start navigation
        $this->navigateTo($browser['pageId'], 'https://example.com');

        // Wait for load event
        $this->waitFor($browser['pageId'], 'load');

        // Verify page is loaded
        $text = $this->getPageText($browser['pageId']);
        expect($text)->toContain('Example Domain');

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('can wait for element to appear', function (): void {
    $browser = $this->createTestBrowser();

    try {
        // Create test page with delayed element
        $testHtml = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Wait Test</title></head>
        <body>
            <div id="initial">Initial content</div>
            <script>
                setTimeout(() => {
                    const div = document.createElement('div');
                    div.id = 'delayed';
                    div.innerText = 'Delayed content appeared';
                    document.body.appendChild(div);
                }, 1000); // Appears after 1 second
            </script>
        </body>
        </html>
        HTML;

        $dataUrl = 'data:text/html,'.urlencode($testHtml);
        $this->navigateTo($browser['pageId'], $dataUrl);

        // Initially the delayed element should not exist
        $initialCheck = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("delayed") !== null',
        ]);
        expect($initialCheck['result'])->toBeFalse();

        // Wait for element to appear
        $this->waitFor($browser['pageId'], '#delayed', 3000);

        // Verify element now exists
        $finalCheck = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("delayed") !== null',
        ]);
        expect($finalCheck['result'])->toBeTrue();

        // Verify content
        $text = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("delayed").innerText',
        ]);
        expect($text['result'])->toBe('Delayed content appeared');

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('can wait for text to appear', function (): void {
    $browser = $this->createTestBrowser();

    try {
        // Create test page with changing text
        $testHtml = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Text Wait Test</title></head>
        <body>
            <div id="status">Loading...</div>
            <script>
                setTimeout(() => {
                    document.getElementById('status').innerText = 'Ready!';
                }, 1500); // Changes after 1.5 seconds
            </script>
        </body>
        </html>
        HTML;

        $dataUrl = 'data:text/html,'.urlencode($testHtml);
        $this->navigateTo($browser['pageId'], $dataUrl);

        // Initially should show "Loading..."
        $initialText = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("status").innerText',
        ]);
        expect($initialText['result'])->toBe('Loading...');

        // Wait for text "Ready!" to appear
        $this->waitFor($browser['pageId'], 'text=Ready!', 3000);

        // Verify text has changed
        $finalText = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("status").innerText',
        ]);
        expect($finalText['result'])->toBe('Ready!');

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('can wait for element to be visible', function (): void {
    $browser = $this->createTestBrowser();

    try {
        // Create test page with initially hidden element
        $testHtml = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Visibility Test</title></head>
        <body>
            <div id="content" style="display: none;">Hidden content</div>
            <button onclick="setTimeout(() => document.getElementById('content').style.display = 'block', 500)">
                Show Content
            </button>
        </body>
        </html>
        HTML;

        $dataUrl = 'data:text/html,'.urlencode($testHtml);
        $this->navigateTo($browser['pageId'], $dataUrl);

        // Get button ref and click it
        $snapshot = $this->getSnapshot($browser['pageId']);
        $buttonRefs = $this->findRefsInSnapshot($snapshot['text'], 'Show Content');
        $this->clickElement($browser['pageId'], $buttonRefs[0]);

        // Wait for element to become visible
        $this->waitFor($browser['pageId'], '#content:visible', 2000);

        // Verify element is now visible
        $isVisible = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => { const el = document.getElementById("content"); return el && window.getComputedStyle(el).display !== "none"; }',
        ]);
        expect($isVisible['result'])->toBeTrue();

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('handles wait timeout gracefully', function (): void {
    $browser = $this->createTestBrowser();

    try {
        // Navigate to simple page
        $this->navigateTo($browser['pageId'], 'https://example.com');

        // Wait for element that will never appear
        $result = static::runCommand([
            'browser:wait',
            $browser['pageId'],
            '#non-existent-element',
            '--timeout',
            '1000',
            '--json',
        ], false);

        expect($result['exitCode'])->not->toBe(0);
        $json = json_decode((string) $result['output'], true);
        expect($json['success'])->toBeFalse();
        expect(strtolower((string) $json['error']))->toContain('timeout');

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('can wait with custom timeout', function (): void {
    $browser = $this->createTestBrowser();

    try {
        // Create test page with very delayed element
        $testHtml = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Long Wait Test</title></head>
        <body>
            <div id="initial">Waiting...</div>
            <script>
                setTimeout(() => {
                    const div = document.createElement('div');
                    div.id = 'very-delayed';
                    div.innerText = 'Finally appeared';
                    document.body.appendChild(div);
                }, 3500); // Appears after 3.5 seconds
            </script>
        </body>
        </html>
        HTML;

        $dataUrl = 'data:text/html,'.urlencode($testHtml);
        $this->navigateTo($browser['pageId'], $dataUrl);

        // Default timeout would fail (usually 5s), but we'll use shorter timeout to test failure
        $failResult = static::runCommand([
            'browser:wait',
            $browser['pageId'],
            '#very-delayed',
            '--timeout',
            '1000', // 1 second - will fail
            '--json',
        ], false);

        expect($failResult['exitCode'])->not->toBe(0);
        $failJson = json_decode((string) $failResult['output'], true);
        expect($failJson['success'])->toBeFalse();

        // Now wait with longer timeout - should succeed
        $this->waitFor($browser['pageId'], '#very-delayed', 5000);

        // Verify element exists
        $exists = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("very-delayed") !== null',
        ]);
        expect($exists['result'])->toBeTrue();

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('can wait for multiple conditions in sequence', function (): void {
    $browser = $this->createTestBrowser();

    try {
        // Create test page with sequential changes
        $testHtml = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Sequential Wait Test</title></head>
        <body>
            <div id="status">Step 1</div>
            <script>
                setTimeout(() => {
                    document.getElementById('status').innerText = 'Step 2';
                    setTimeout(() => {
                        document.getElementById('status').innerText = 'Step 3';
                        const done = document.createElement('div');
                        done.id = 'done';
                        done.innerText = 'Complete';
                        document.body.appendChild(done);
                    }, 1000);
                }, 1000);
            </script>
        </body>
        </html>
        HTML;

        $dataUrl = 'data:text/html,'.urlencode($testHtml);
        $this->navigateTo($browser['pageId'], $dataUrl);

        // Wait for Step 2
        $this->waitFor($browser['pageId'], 'text=Step 2', 3000);

        $step2Check = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("status").innerText',
        ]);
        expect($step2Check['result'])->toBe('Step 2');

        // Wait for Step 3
        $this->waitFor($browser['pageId'], 'text=Step 3', 3000);

        $step3Check = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("status").innerText',
        ]);
        expect($step3Check['result'])->toBe('Step 3');

        // Wait for done element
        $this->waitFor($browser['pageId'], '#done', 3000);

        $doneCheck = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("done").innerText',
        ]);
        expect($doneCheck['result'])->toBe('Complete');

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('can wait for network idle', function (): void {
    $browser = $this->createTestBrowser();

    try {
        // Navigate to a page that makes network requests
        $this->navigateTo($browser['pageId'], 'https://httpbin.org/delay/1');

        // Wait for network to be idle
        $this->waitFor($browser['pageId'], 'networkidle');

        // Page should be fully loaded
        $text = $this->getPageText($browser['pageId']);
        expect($text)->toContain('args');
        expect($text)->toContain('origin');

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('can wait for function to return true', function (): void {
    $browser = $this->createTestBrowser();

    try {
        // Create test page with counter
        $testHtml = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Function Wait Test</title></head>
        <body>
            <div id="counter">0</div>
            <script>
                let count = 0;
                const interval = setInterval(() => {
                    count++;
                    document.getElementById('counter').innerText = count;
                    if (count >= 5) clearInterval(interval);
                }, 500);
                window.getCount = () => count;
            </script>
        </body>
        </html>
        HTML;

        $dataUrl = 'data:text/html,'.urlencode($testHtml);
        $this->navigateTo($browser['pageId'], $dataUrl);

        // Wait for counter to reach 3
        $this->waitFor($browser['pageId'], '() => window.getCount() >= 3', 3000);

        // Verify counter is at least 3
        $count = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => window.getCount()',
        ]);
        expect($count['result'])->toBeGreaterThanOrEqual(3);

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});
