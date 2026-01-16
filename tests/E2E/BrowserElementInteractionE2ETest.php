<?php

declare(strict_types=1);

namespace Tests\E2E;

uses(BrowserE2ETestCase::class);
uses()->group('e2e', 'browser', 'interaction');

it('can fill text fields using element refs', function () {
    $browser = $this->createTestBrowser();

    try {
        // Create test page with form
        $testHtml = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Form Test</title></head>
        <body>
            <form id="testForm">
                <input id="name" type="text" placeholder="Enter name" value="">
                <input id="email" type="email" placeholder="Enter email" value="">
                <textarea id="message" placeholder="Enter message"></textarea>
                <button type="submit">Submit</button>
            </form>
        </body>
        </html>
        HTML;

        $dataUrl = 'data:text/html,'.urlencode($testHtml);
        $this->navigateTo($browser['pageId'], $dataUrl);

        // Get snapshot to find input refs
        $snapshot = $this->getSnapshot($browser['pageId']);
        $snapshotText = $snapshot['text'];

        // Find refs for each input
        $nameRefs = $this->findRefsInSnapshot($snapshotText, 'Enter name');
        expect(count($nameRefs))->toBe(1);
        $nameRef = $nameRefs[0];

        $emailRefs = $this->findRefsInSnapshot($snapshotText, 'Enter email');
        expect(count($emailRefs))->toBe(1);
        $emailRef = $emailRefs[0];

        $messageRefs = $this->findRefsInSnapshot($snapshotText, 'Enter message');
        expect(count($messageRefs))->toBe(1);
        $messageRef = $messageRefs[0];

        // Fill each field
        $this->fillField($browser['pageId'], $nameRef, 'John Doe');
        $this->fillField($browser['pageId'], $emailRef, 'john@example.com');
        $this->fillField($browser['pageId'], $messageRef, 'This is a test message');

        // Verify values were filled by running JavaScript
        $values = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => ({ name: document.getElementById("name").value, email: document.getElementById("email").value, message: document.getElementById("message").value })',
        ]);

        expect($values['result']['name'])->toBe('John Doe');
        expect($values['result']['email'])->toBe('john@example.com');
        expect($values['result']['message'])->toBe('This is a test message');

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('can click buttons and links using element refs', function () {
    $browser = $this->createTestBrowser();

    try {
        // Create test page with buttons and links
        $testHtml = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Click Test</title></head>
        <body>
            <div id="output">No clicks yet</div>
            <button id="btn1" onclick="document.getElementById('output').innerText = 'Button 1 clicked'">Click Button 1</button>
            <button id="btn2" onclick="document.getElementById('output').innerText = 'Button 2 clicked'">Click Button 2</button>
            <a href="#section1" onclick="document.getElementById('output').innerText = 'Link 1 clicked'; return false;">Go to Section 1</a>
            <a href="#section2" onclick="document.getElementById('output').innerText = 'Link 2 clicked'; return false;">Go to Section 2</a>
        </body>
        </html>
        HTML;

        $dataUrl = 'data:text/html,'.urlencode($testHtml);
        $this->navigateTo($browser['pageId'], $dataUrl);

        // Get snapshot to find button refs
        $snapshot = $this->getSnapshot($browser['pageId']);
        $snapshotText = $snapshot['text'];

        // Find and click first button
        $btn1Refs = $this->findRefsInSnapshot($snapshotText, 'Click Button 1');
        expect(count($btn1Refs))->toBe(1);
        $this->clickElement($browser['pageId'], $btn1Refs[0]);

        // Verify button 1 was clicked
        $output1 = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("output").innerText',
        ]);
        expect($output1['result'])->toBe('Button 1 clicked');

        // Find and click second button
        $btn2Refs = $this->findRefsInSnapshot($snapshotText, 'Click Button 2');
        expect(count($btn2Refs))->toBe(1);
        $this->clickElement($browser['pageId'], $btn2Refs[0]);

        // Verify button 2 was clicked
        $output2 = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("output").innerText',
        ]);
        expect($output2['result'])->toBe('Button 2 clicked');

        // Click a link
        $link1Refs = $this->findRefsInSnapshot($snapshotText, 'Go to Section 1');
        expect(count($link1Refs))->toBe(1);
        $this->clickElement($browser['pageId'], $link1Refs[0]);

        // Verify link was clicked
        $output3 = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("output").innerText',
        ]);
        expect($output3['result'])->toBe('Link 1 clicked');

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('can type text at current focus position', function () {
    $browser = $this->createTestBrowser();

    try {
        // Create test page with input
        $testHtml = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Type Test</title></head>
        <body>
            <input id="input1" type="text" placeholder="First input" value="">
            <input id="input2" type="text" placeholder="Second input" value="">
            <script>
                // Auto-focus first input
                window.addEventListener('load', () => {
                    document.getElementById('input1').focus();
                });
            </script>
        </body>
        </html>
        HTML;

        $dataUrl = 'data:text/html,'.urlencode($testHtml);
        $this->navigateTo($browser['pageId'], $dataUrl);

        // Type in the focused field (input1)
        $this->typeText($browser['pageId'], 'Hello World');

        // Verify text was typed in input1
        $value1 = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("input1").value',
        ]);
        expect($value1['result'])->toBe('Hello World');

        // Now click on input2 to change focus
        $snapshot = $this->getSnapshot($browser['pageId']);
        $input2Refs = $this->findRefsInSnapshot($snapshot['text'], 'Second input');
        expect(count($input2Refs))->toBe(1);
        $this->clickElement($browser['pageId'], $input2Refs[0]);

        // Type in the new focused field
        $this->typeText($browser['pageId'], 'Testing 123');

        // Verify text was typed in input2
        $value2 = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("input2").value',
        ]);
        expect($value2['result'])->toBe('Testing 123');

        // Verify input1 still has its original value
        $value1Check = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("input1").value',
        ]);
        expect($value1Check['result'])->toBe('Hello World');

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('handles clicking duplicate elements correctly', function () {
    $browser = $this->createTestBrowser();

    try {
        // Create test page with duplicate elements
        $testHtml = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Duplicate Elements Test</title></head>
        <body>
            <div id="output">No clicks yet</div>
            <div class="section">
                <button onclick="document.getElementById('output').innerText = 'First Submit clicked'">Submit</button>
                <button onclick="document.getElementById('output').innerText = 'First Cancel clicked'">Cancel</button>
            </div>
            <div class="section">
                <button onclick="document.getElementById('output').innerText = 'Second Submit clicked'">Submit</button>
                <button onclick="document.getElementById('output').innerText = 'Second Cancel clicked'">Cancel</button>
            </div>
        </body>
        </html>
        HTML;

        $dataUrl = 'data:text/html,'.urlencode($testHtml);
        $this->navigateTo($browser['pageId'], $dataUrl);

        // Get snapshot
        $snapshot = $this->getSnapshot($browser['pageId']);
        $snapshotText = $snapshot['text'];

        // Find all Submit button refs
        $lines = explode("\n", $snapshotText);
        $submitRefs = [];
        foreach ($lines as $line) {
            if (stripos($line, 'button "Submit"') !== false) {
                if (preg_match('/\[ref=(@e\d+)\]/', $line, $match)) {
                    $submitRefs[] = $match[1];
                }
            }
        }

        // Should have 2 Submit buttons with different refs
        expect(count($submitRefs))->toBe(2);
        expect($submitRefs[0])->not->toBe($submitRefs[1]);

        // Click first Submit button
        $this->clickElement($browser['pageId'], $submitRefs[0]);
        $output1 = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("output").innerText',
        ]);
        expect($output1['result'])->toBe('First Submit clicked');

        // Click second Submit button
        $this->clickElement($browser['pageId'], $submitRefs[1]);
        $output2 = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("output").innerText',
        ]);
        expect($output2['result'])->toBe('Second Submit clicked');

        // Find all Cancel button refs
        $cancelRefs = [];
        foreach ($lines as $line) {
            if (stripos($line, 'button "Cancel"') !== false) {
                if (preg_match('/\[ref=(@e\d+)\]/', $line, $match)) {
                    $cancelRefs[] = $match[1];
                }
            }
        }

        expect(count($cancelRefs))->toBe(2);

        // Click second Cancel button (using nth)
        $this->clickElement($browser['pageId'], $cancelRefs[1]);
        $output3 = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("output").innerText',
        ]);
        expect($output3['result'])->toBe('Second Cancel clicked');

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('handles form submission workflow', function () {
    $browser = $this->createTestBrowser();

    try {
        // Create test page with complete form
        $testHtml = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Form Submission Test</title></head>
        <body>
            <div id="result" style="display:none;"></div>
            <form id="testForm" onsubmit="event.preventDefault(); document.getElementById('result').innerText = 'Form submitted with: ' + document.getElementById('username').value + ', ' + document.getElementById('password').value; document.getElementById('result').style.display = 'block'; return false;">
                <input id="username" type="text" placeholder="Username" required>
                <input id="password" type="password" placeholder="Password" required>
                <label>
                    <input id="remember" type="checkbox"> Remember me
                </label>
                <button type="submit">Login</button>
            </form>
        </body>
        </html>
        HTML;

        $dataUrl = 'data:text/html,'.urlencode($testHtml);
        $this->navigateTo($browser['pageId'], $dataUrl);

        // Get snapshot
        $snapshot = $this->getSnapshot($browser['pageId']);
        $snapshotText = $snapshot['text'];

        // Fill username
        $usernameRefs = $this->findRefsInSnapshot($snapshotText, 'Username');
        $this->fillField($browser['pageId'], $usernameRefs[0], 'testuser');

        // Fill password
        $passwordRefs = $this->findRefsInSnapshot($snapshotText, 'Password');
        $this->fillField($browser['pageId'], $passwordRefs[0], 'secretpass');

        // Click checkbox
        $checkboxRefs = $this->findRefsInSnapshot($snapshotText, 'Remember me');
        if (count($checkboxRefs) > 0) {
            $this->clickElement($browser['pageId'], $checkboxRefs[0]);
        }

        // Submit form
        $submitRefs = $this->findRefsInSnapshot($snapshotText, 'Login');
        $this->clickElement($browser['pageId'], $submitRefs[0]);

        // Verify form was submitted
        $result = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("result").innerText',
        ]);
        expect($result['result'])->toBe('Form submitted with: testuser, secretpass');

        // Verify checkbox was checked
        $checkboxState = $this->runJsonCommand([
            'browser:run',
            $browser['pageId'],
            '() => document.getElementById("remember").checked',
        ]);
        expect($checkboxState['result'])->toBeTrue();

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});

it('handles errors for non-existent refs gracefully', function () {
    $browser = $this->createTestBrowser();

    try {
        $testHtml = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Error Test</title></head>
        <body>
            <button id="btn">Test Button</button>
        </body>
        </html>
        HTML;

        $dataUrl = 'data:text/html,'.urlencode($testHtml);
        $this->navigateTo($browser['pageId'], $dataUrl);

        // Try to click non-existent ref
        $result = static::runCommand([
            'browser:click',
            $browser['pageId'],
            '--ref',
            '@e999',
            '--json',
        ], false);

        expect($result['exitCode'])->not->toBe(0);
        $json = json_decode($result['output'], true);
        expect($json['success'])->toBeFalse();
        expect($json['error'])->toContain('not found');

        // Try to fill non-existent ref
        $result2 = static::runCommand([
            'browser:fill',
            $browser['pageId'],
            '--ref',
            '@e999',
            'test value',
            '--json',
        ], false);

        expect($result2['exitCode'])->not->toBe(0);
        $json2 = json_decode($result2['output'], true);
        expect($json2['success'])->toBeFalse();
        expect($json2['error'])->toContain('not found');

    } finally {
        $this->closeBrowser($browser['contextId']);
    }
});
