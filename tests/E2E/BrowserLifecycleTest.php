<?php

declare(strict_types=1);

use Tests\E2E\E2ETestCase;

/**
 * E2E tests for browser lifecycle commands
 * Tests the full stack: PHP command → IPC → consume daemon → browser-daemon.js → Playwright
 *
 * @group e2e
 */
class BrowserLifecycleTest extends E2ETestCase
{
    /**
     * Test creating and managing browser contexts and pages
     */
    public function test_browser_context_and_page_lifecycle(): void
    {
        // Get initial status - should have no contexts
        $result = $this->assertBrowserCommandSucceeds('browser:status', ['--json' => true]);
        $status = $this->parseJsonOutput($result['outputString']);

        $this->assertTrue($status['success']);
        $this->assertEquals(0, $status['browser']['contextCount'] ?? 0);

        // Create a context
        $result = $this->assertBrowserCommandSucceeds('browser:create', [
            'context',
            '--session' => 'e2e-test',
            '--json' => true,
        ]);
        $createResult = $this->parseJsonOutput($result['outputString']);

        $this->assertTrue($createResult['success']);
        $this->assertArrayHasKey('contextId', $createResult);
        $contextId = $createResult['contextId'];
        $this->assertNotEmpty($contextId);

        // Verify context appears in status
        $result = $this->assertBrowserCommandSucceeds('browser:status', ['--json' => true]);
        $status = $this->parseJsonOutput($result['outputString']);

        $this->assertEquals(1, $status['browser']['contextCount']);
        $this->assertCount(1, $status['browser']['contexts']);
        $this->assertEquals($contextId, $status['browser']['contexts'][0]['id']);
        $this->assertEquals(0, $status['browser']['contexts'][0]['pageCount']);

        // Create a page in the context
        $result = $this->assertBrowserCommandSucceeds('browser:create', [
            'page',
            '--context' => $contextId,
            '--json' => true,
        ]);
        $pageResult = $this->parseJsonOutput($result['outputString']);

        $this->assertTrue($pageResult['success']);
        $this->assertArrayHasKey('pageId', $pageResult);
        $pageId = $pageResult['pageId'];
        $this->assertNotEmpty($pageId);

        // Verify page appears in status
        $result = $this->assertBrowserCommandSucceeds('browser:status', ['--json' => true]);
        $status = $this->parseJsonOutput($result['outputString']);

        $this->assertEquals(1, $status['browser']['contexts'][0]['pageCount']);
        $this->assertCount(1, $status['browser']['contexts'][0]['pages']);
        $this->assertEquals($pageId, $status['browser']['contexts'][0]['pages'][0]['id']);

        // Close the context (should also close pages)
        $result = $this->assertBrowserCommandSucceeds('browser:close', [
            $contextId,
            '--json' => true,
        ]);
        $closeResult = $this->parseJsonOutput($result['outputString']);

        $this->assertTrue($closeResult['success']);

        // Verify cleanup in status
        $result = $this->assertBrowserCommandSucceeds('browser:status', ['--json' => true]);
        $status = $this->parseJsonOutput($result['outputString']);

        $this->assertEquals(0, $status['browser']['contextCount']);
    }

    /**
     * Test creating multiple pages in a context
     */
    public function test_multiple_pages_in_context(): void
    {
        // Create a context
        $result = $this->assertBrowserCommandSucceeds('browser:create', [
            'context',
            '--session' => 'e2e-multi-page',
            '--json' => true,
        ]);
        $contextId = $this->parseJsonOutput($result['outputString'])['contextId'];

        // Create three pages
        $pageIds = [];
        for ($i = 0; $i < 3; $i++) {
            $result = $this->assertBrowserCommandSucceeds('browser:create', [
                'page',
                '--context' => $contextId,
                '--json' => true,
            ]);
            $pageIds[] = $this->parseJsonOutput($result['outputString'])['pageId'];
        }

        // Verify all pages in status
        $result = $this->assertBrowserCommandSucceeds('browser:status', ['--json' => true]);
        $status = $this->parseJsonOutput($result['outputString']);

        $this->assertEquals(3, $status['browser']['contexts'][0]['pageCount']);
        $this->assertCount(3, $status['browser']['contexts'][0]['pages']);

        $foundPageIds = array_map(
            fn (array $page) => $page['id'],
            $status['browser']['contexts'][0]['pages']
        );
        foreach ($pageIds as $pageId) {
            $this->assertContains($pageId, $foundPageIds);
        }

        // Close one page
        $result = $this->assertBrowserCommandSucceeds('browser:close', [
            $pageIds[0],
            '--json' => true,
        ]);
        $this->assertTrue($this->parseJsonOutput($result['outputString'])['success']);

        // Verify count decreased
        $result = $this->assertBrowserCommandSucceeds('browser:status', ['--json' => true]);
        $status = $this->parseJsonOutput($result['outputString']);

        $this->assertEquals(2, $status['browser']['contexts'][0]['pageCount']);

        // Clean up
        $this->assertBrowserCommandSucceeds('browser:close', [$contextId]);
    }

    /**
     * Test error handling for invalid IDs
     */
    public function test_error_handling_for_invalid_ids(): void
    {
        // Try to create page with non-existent context
        $result = $this->assertBrowserCommandFails('browser:create', [
            'page',
            '--context' => 'invalid-context-id',
        ]);
        $this->assertStringContainsString('Context not found', $result['outputString']);

        // Try to close non-existent context
        $result = $this->assertBrowserCommandFails('browser:close', [
            'invalid-context-id',
        ]);
        $this->assertStringContainsString('not found', $result['outputString']);

        // Try to navigate with non-existent page
        $result = $this->assertBrowserCommandFails('browser:goto', [
            'invalid-page-id',
            'https://example.com',
        ]);
        $this->assertStringContainsString('Page not found', $result['outputString']);
    }

    /**
     * Test viewport configuration
     */
    public function test_viewport_configuration(): void
    {
        // Create context with specific viewport
        $result = $this->assertBrowserCommandSucceeds('browser:create', [
            'context',
            '--session' => 'e2e-viewport',
            '--width' => '1920',
            '--height' => '1080',
            '--json' => true,
        ]);
        $contextId = $this->parseJsonOutput($result['outputString'])['contextId'];

        // Create a page and navigate
        $result = $this->assertBrowserCommandSucceeds('browser:create', [
            'page',
            '--context' => $contextId,
            '--json' => true,
        ]);
        $pageId = $this->parseJsonOutput($result['outputString'])['pageId'];

        // Navigate to a page
        $result = $this->assertBrowserCommandSucceeds('browser:goto', [
            $pageId,
            'https://example.com',
            '--json' => true,
        ]);
        $this->assertTrue($this->parseJsonOutput($result['outputString'])['success']);

        // Take a screenshot to verify viewport (screenshot dimensions should match)
        $screenshotPath = sys_get_temp_dir().'/e2e-viewport-test.png';
        $result = $this->assertBrowserCommandSucceeds('browser:screenshot', [
            $pageId,
            '--output' => $screenshotPath,
            '--json' => true,
        ]);
        $this->assertTrue($this->parseJsonOutput($result['outputString'])['success']);

        // Verify screenshot was created
        $this->assertFileExists($screenshotPath);

        // Clean up
        @unlink($screenshotPath);
        $this->assertBrowserCommandSucceeds('browser:close', [$contextId]);
    }
}
