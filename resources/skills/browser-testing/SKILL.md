---
name: browser-testing
description: Test frontend code changes using the browser daemon. Use when testing visual output, verifying UI changes, checking rendered HTML, or taking screenshots of web pages.
user-invocable: false
---

# Browser Testing

Test frontend and visual changes using Fuel's browser daemon with Playwright.

## When to Use

Use this skill when:
- Testing visual output or UI changes
- Verifying HTML renders correctly
- Checking layout, styling, or formatting
- Taking screenshots for visual comparison
- Interacting with page elements (clicking, scrolling, typing)

## Quick Start

```bash
# Create context with page (page defaults to {context_id}-tab1)
fuel browser:create mytest

# Navigate and screenshot
fuel browser:goto mytest-tab1 "http://localhost:3000"
fuel browser:screenshot mytest-tab1 --path=/tmp/screenshot.png

# Clean up
fuel browser:close mytest
```

## Commands

### Create Context with Page
```bash
fuel browser:create <context_id> [page_id]
```
Creates a browser context and initial page. Page defaults to `{context_id}-tab1`.

Options:
- `--viewport='{"width":1280,"height":720}'` - Set viewport size
- `--user-agent="..."` - Custom user agent
- `--json` - JSON output

**Re-use contexts**: You can navigate the same page to different URLs without recreating the context.

### Navigate to URL
```bash
fuel browser:goto <page_id> <url>
```
Navigate a page to a URL. Can be called multiple times on the same page.

Options:
- `--wait-until=load|domcontentloaded|networkidle` - Wait condition
- `--timeout=30000` - Navigation timeout (ms)

### Take Screenshot
```bash
fuel browser:screenshot <page_id> --path=<file.png>
```
Capture the page as an image.

Options:
- `--full-page` - Capture entire scrollable page

### Run Playwright Code
```bash
fuel browser:run <page_id> "<code>"
```
Run Playwright code with access to the `page` object. Returns the result.

The code runs as an async function with `page` in scope. Use `return` to get values back.

**Click elements**:
```bash
fuel browser:run page1 "await page.click('.submit-btn')"
fuel browser:run page1 "await page.click('a[href=\"/next\"]')"
```

**Type into inputs**:
```bash
fuel browser:run page1 "await page.fill('input[name=\"email\"]', 'test@example.com')"
fuel browser:run page1 "await page.fill('textarea', 'Hello world')"
```

**Wait for elements**:
```bash
fuel browser:run page1 "await page.waitForSelector('.loaded')"
fuel browser:run page1 "await page.waitForSelector('.modal', { state: 'hidden' })"
```

**Get text content**:
```bash
fuel browser:run page1 "return await page.textContent('.message')"
fuel browser:run page1 "return await page.locator('.item').count()"
```

**Query with evaluate** (browser-side JS):
```bash
fuel browser:run page1 "return await page.evaluate(() => document.title)"
fuel browser:run page1 "return await page.evaluate(() => window.scrollY)"
```

**Scroll**:
```bash
fuel browser:run page1 "await page.evaluate(() => window.scrollTo(0, 500))"
fuel browser:run page1 "await page.locator('.section').scrollIntoViewIfNeeded()"
```

**Check visibility**:
```bash
fuel browser:run page1 "return await page.locator('.error').isVisible()"
fuel browser:run page1 "return await page.locator('.button').isEnabled()"
```

**Multi-line code**:
```bash
fuel browser:run page1 "
await page.fill('#username', 'testuser')
await page.fill('#password', 'secret')
await page.click('button[type=\"submit\"]')
await page.waitForURL('**/dashboard')
return await page.title()
"
```

### Create Additional Pages
```bash
fuel browser:page <context_id> <page_id>
```
Create additional pages/tabs in an existing context. Useful for testing multi-tab scenarios.

### Close Context
```bash
fuel browser:close <context_id>
```
Clean up browser context and all its pages.

### Check Status
```bash
fuel browser:status
```
View daemon status and active contexts/pages.

## Testing Workflow

1. **Start local server** (if needed):
   ```bash
   npm run dev &
   ```

2. **Create browser context**:
   ```bash
   fuel browser:create test-ctx
   ```

3. **Navigate and verify**:
   ```bash
   fuel browser:goto test-ctx-tab1 "http://localhost:3000/page"
   fuel browser:screenshot test-ctx-tab1 --path=/tmp/screenshot.png
   ```

4. **Interact and verify**:
   ```bash
   fuel browser:run test-ctx-tab1 "await page.click('.login-btn')"
   fuel browser:run test-ctx-tab1 "await page.waitForSelector('.welcome-message')"
   fuel browser:screenshot test-ctx-tab1 --path=/tmp/after-click.png
   fuel browser:run test-ctx-tab1 "return await page.textContent('.welcome-message')"
   ```

5. **Re-navigate to test another page** (same context):
   ```bash
   fuel browser:goto test-ctx-tab1 "http://localhost:3000/other-page"
   ```

6. **Clean up**:
   ```bash
   fuel browser:close test-ctx
   ```

## Requirements

- The consume daemon must be running (`fuel consume`)
- Browser executable (Chrome/Chromium) must be available
- Set `FUEL_BROWSER_EXECUTABLE` env var if browser is not in standard location
