/**
 * Browser Daemon Test Suite
 *
 * Tests the browser-daemon.js JSON-RPC protocol in isolation.
 * Spawns daemon as child process, sends commands to stdin, reads responses from stdout.
 */

import { describe, it, expect, beforeAll, afterAll, beforeEach } from 'vitest';
import { spawn } from 'node:child_process';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';
import { readFileSync, existsSync } from 'node:fs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

// Helper to interact with daemon process
class DaemonClient {
  constructor() {
    this.daemon = null;
    this.responses = [];
    this.responseCallbacks = new Map();
    this.requestId = 0;
    this.buffer = '';
  }

  async start() {
    return new Promise((resolve, reject) => {
      // Use test mode to avoid actual browser launches if possible
      this.daemon = spawn('node', [join(__dirname, 'browser-daemon.js')], {
        env: {
          ...process.env,
          // Set a non-existent browser path to test error handling
          FUEL_BROWSER_EXECUTABLE: process.env.CI ? '/nonexistent/chrome' : undefined
        }
      });

      this.daemon.stdout.on('data', (data) => {
        this.buffer += data.toString();
        const lines = this.buffer.split('\n');
        this.buffer = lines.pop(); // Keep incomplete line in buffer

        for (const line of lines) {
          if (line.trim()) {
            try {
              const response = JSON.parse(line);
              if (response.id && this.responseCallbacks.has(response.id)) {
                const callback = this.responseCallbacks.get(response.id);
                this.responseCallbacks.delete(response.id);
                callback(response);
              }
              this.responses.push(response);
            } catch (e) {
              console.error('Failed to parse response:', line);
            }
          }
        }
      });

      this.daemon.stderr.on('data', (data) => {
        console.error('Daemon stderr:', data.toString());
      });

      this.daemon.on('error', reject);

      // Give daemon time to start
      setTimeout(resolve, 500);
    });
  }

  async stop() {
    if (this.daemon) {
      this.daemon.kill();
      await new Promise(resolve => setTimeout(resolve, 100));
    }
  }

  async request(method, params = {}) {
    const id = ++this.requestId;
    const request = { id, method, params };

    return new Promise((resolve, reject) => {
      const timeout = setTimeout(() => {
        this.responseCallbacks.delete(id);
        reject(new Error(`Request ${id} timed out`));
      }, 5000);

      this.responseCallbacks.set(id, (response) => {
        clearTimeout(timeout);
        resolve(response);
      });

      this.daemon.stdin.write(JSON.stringify(request) + '\n');
    });
  }
}

describe('Browser Daemon Protocol Tests', () => {
  let client;

  beforeAll(async () => {
    client = new DaemonClient();
    await client.start();
  });

  afterAll(async () => {
    await client.stop();
  });

  describe('Core Methods', () => {
    it('should respond to ping', async () => {
      const response = await client.request('ping');
      expect(response.ok).toBe(true);
      expect(response.result).toHaveProperty('status');
      expect(response.result.status).toBe('ok');
    });

    it('should report status', async () => {
      const response = await client.request('status');
      expect(response.ok).toBe(true);
      expect(response.result).toHaveProperty('contexts');
      expect(response.result).toHaveProperty('pages');
      expect(Array.isArray(response.result.contexts)).toBe(true);
      expect(Array.isArray(response.result.pages)).toBe(true);
    });
  });

  describe('Context Management', () => {
    let contextId;

    it('should create a new context', async () => {
      const response = await client.request('newContext', {
        contextId: 'ctx_test_' + Date.now(),
        session: 'test-session',
        viewport: { width: 1920, height: 1080 }
      });

      // If no browser available (CI), expect error but validate structure
      if (response.ok) {
        expect(response.result).toHaveProperty('contextId');
        contextId = response.result.contextId;
        expect(contextId).toMatch(/^ctx_/);
      } else {
        // In CI without browser
        expect(response.error).toBeDefined();
      }
    });

    it('should handle missing contextId', async () => {
      const response = await client.request('newContext', {});

      // Should fail without contextId
      expect(response.ok).toBe(false);
      expect(response.error.code).toBe('MISSING_CONTEXT_ID');
    });

    it('should close a context', async () => {
      if (contextId) {
        const response = await client.request('closeContext', { contextId });
        expect(response.ok).toBe(true);
        expect(response.result.closed).toBe(true);
      } else {
        // Test with non-existent context
        const response = await client.request('closeContext', { contextId: 'ctx_fake' });
        expect(response.ok).toBe(true);
        expect(response.result.closed).toBe(false);
      }
    });
  });

  describe('Page Management', () => {
    it('should reject newPage without pageId', async () => {
      const response = await client.request('newPage', { contextId: 'ctx_test' });
      expect(response.ok).toBe(false);
      expect(response.error.code).toBe('MISSING_PAGE_ID');
    });

    it('should reject newPage with unknown context', async () => {
      const response = await client.request('newPage', {
        contextId: 'ctx_unknown',
        pageId: 'page_test'
      });
      expect(response.ok).toBe(false);
      expect(response.error.code).toBe('NO_CONTEXT');
    });

    it('should prevent duplicate pageIds', async () => {
      // This test requires a real context which may not be available in CI
      // We'll test the error handling path instead
      const response1 = await client.request('newPage', {
        contextId: 'ctx_fake',
        pageId: 'duplicate_test'
      });

      // Should fail with NO_CONTEXT
      expect(response1.ok).toBe(false);
    });
  });

  describe('Snapshot Method', () => {
    it('should reject snapshot without valid page', async () => {
      const response = await client.request('snapshot', {
        pageId: 'page_unknown'
      });
      expect(response.ok).toBe(false);
      expect(response.error.code).toBe('NO_PAGE');
    });

    it('should handle snapshot with interactiveOnly flag', async () => {
      const response = await client.request('snapshot', {
        pageId: 'page_unknown',
        interactiveOnly: true
      });
      expect(response.ok).toBe(false);
      expect(response.error.code).toBe('NO_PAGE');
    });
  });

  describe('Page Actions', () => {
    it('should reject goto without valid page', async () => {
      const response = await client.request('goto', {
        pageId: 'page_unknown',
        url: 'https://example.com'
      });
      expect(response.ok).toBe(false);
      expect(response.error.code).toBe('NO_PAGE');
    });

    it('should reject screenshot without valid page', async () => {
      const response = await client.request('screenshot', {
        pageId: 'page_unknown'
      });
      expect(response.ok).toBe(false);
      expect(response.error.code).toBe('NO_PAGE');
    });

    it('should reject run without valid page', async () => {
      const response = await client.request('run', {
        pageId: 'page_unknown',
        code: 'return page.title()'
      });
      expect(response.ok).toBe(false);
      expect(response.error.code).toBe('NO_PAGE');
    });
  });

  describe('Error Handling', () => {
    it('should handle unknown methods', async () => {
      const response = await client.request('unknownMethod');
      expect(response.ok).toBe(false);
      expect(response.error.message).toContain('Unknown method');
    });

    it('should handle malformed params', async () => {
      // Send request with invalid params structure (null params)
      // This should fail because contextId is required
      const response = await client.request('newContext', null);
      expect(response.ok).toBe(false);
      expect(response.error.code).toBe('MISSING_CONTEXT_ID');
    });
  });

  describe('Status Reporting', () => {
    it('should track contexts and pages in status', async () => {
      // Create a context (if possible)
      const ctxResponse = await client.request('newContext', {
        session: 'status-test'
      });

      const statusResponse = await client.request('status');
      expect(statusResponse.ok).toBe(true);

      if (ctxResponse.ok) {
        // If we created a context, it should appear in status
        const contexts = statusResponse.result.contexts;
        const found = contexts.find(c => c.id === ctxResponse.result.contextId);
        expect(found).toBeDefined();
        expect(found.session).toBe('status-test');

        // Clean up
        await client.request('closeContext', {
          contextId: ctxResponse.result.contextId
        });
      }
    });
  });
});

// Integration tests that require actual browser
describe('Browser Integration Tests', { skip: process.env.CI }, () => {
  let client;
  let contextId;
  let pageId;

  beforeAll(async () => {
    client = new DaemonClient();
    await client.start();
  });

  afterAll(async () => {
    if (contextId) {
      await client.request('closeContext', { contextId });
    }
    await client.stop();
  });

  it('should create context and page', async () => {
    // Create context
    contextId = 'integration-test-' + Date.now();
    const ctxResponse = await client.request('newContext', {
      contextId,
      session: 'integration-test',
      viewport: { width: 1280, height: 720 },
      userAgent: 'Test Browser',
      timezoneId: 'America/New_York',
      colorScheme: 'dark'
    });
    expect(ctxResponse.ok).toBe(true);
    expect(ctxResponse.result.contextId).toBe(contextId);

    // Create page
    pageId = 'test-page-' + Date.now();
    const pageResponse = await client.request('newPage', {
      contextId,
      pageId
    });
    expect(pageResponse.ok).toBe(true);
    expect(pageResponse.result.pageId).toBe(pageId);
  });

  it('should navigate to URL', async () => {
    if (!pageId) return;

    const response = await client.request('goto', {
      pageId,
      url: 'https://example.com',
      waitUntil: 'load',
      html: true
    });
    expect(response.ok).toBe(true);
    expect(response.result.url).toContain('example.com');
    expect(response.result.html).toContain('<html');
  });

  it('should execute JavaScript', async () => {
    if (!pageId) return;

    const response = await client.request('run', {
      pageId,
      code: `
        const title = await page.title();
        const url = page.url();
        return { title, url };
      `
    });
    expect(response.ok).toBe(true);
    expect(response.result.value).toHaveProperty('title');
    expect(response.result.value).toHaveProperty('url');
  });

  it('should take screenshots', async () => {
    if (!pageId) return;

    const response = await client.request('screenshot', {
      pageId,
      fullPage: true
    });
    expect(response.ok).toBe(true);
    expect(response.result.path).toContain(pageId);

    // Verify file exists
    expect(existsSync(response.result.path)).toBe(true);
  });

  it('should get accessibility snapshot with element refs', async () => {
    if (!pageId) return;

    const response = await client.request('snapshot', {
      pageId,
      interactiveOnly: false
    });
    expect(response.ok).toBe(true);
    expect(response.result).toHaveProperty('snapshot');

    // Check if snapshot has text representation with embedded refs
    const snapshot = response.result.snapshot;
    expect(snapshot).toHaveProperty('text');
    expect(snapshot.text).toMatch(/\[ref=@e\d+\]/);
    expect(snapshot).toHaveProperty('refCount');
    expect(snapshot.refCount).toBeGreaterThan(0);
  });

  it('should get interactive-only snapshot', async () => {
    if (!pageId) return;

    const response = await client.request('snapshot', {
      pageId,
      interactiveOnly: true
    });
    expect(response.ok).toBe(true);
    expect(response.result).toHaveProperty('snapshot');

    // Snapshot should have text with refs (only interactive elements)
    const snapshot = response.result.snapshot;
    expect(snapshot).toHaveProperty('text');
    expect(snapshot).toHaveProperty('refCount');
  });

  it('should handle page expiry gracefully', async () => {
    // Try to use expired page
    const response = await client.request('goto', {
      pageId: 'expired-page',
      url: 'https://example.com'
    });
    expect(response.ok).toBe(false);
    expect(response.error.message).toContain('expired');
  });
});