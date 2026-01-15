---
name: fuel-browser
description: Control a headless browser for testing, screenshots, and web automation. Use when testing web pages, taking screenshots, interacting with web pages, verifying UI changes, or scraping rendered HTML.
user-invocable: false
---

# Headless Browser

## Quick Screenshot

Take a screenshot of any URL in one command - no setup needed:

```bash
fuel browser:screenshot --url="http://localhost:3000" [--path=/tmp/mobile.png] [--width=375] [--height=812] [--dark] [--full-page]
```

This creates a temporary browser context, navigates, screenshots, and cleans up automatically.

## Manual Context Management

For multiple operations on the same page, create a context:

```bash
# Create browser context with a new tab with a page name (mytest-page)
fuel browser:create mytest mytest-page

# Navigate and screenshot
fuel browser:goto mytest-page "http://localhost:3000" [--html]
fuel browser:screenshot mytest-page --path=/tmp/screenshot.png

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
- `--dark` - Use dark color scheme
- `--json` - JSON output

**Re-use contexts**: You can navigate the same page to different URLs without recreating the context.

### Navigate to URL
```bash
fuel browser:goto <page_id> <url>
```
Navigate a page to a URL. Can be called multiple times on the same page.

Options:
- `--wait-until=load|domcontentloaded|networkidle` - Wait condition (use `networkidle` for SPAs)
- `--timeout=30000` - Navigation timeout (ms)
- `--html` - Return rendered HTML/DOM after navigation

### Take Screenshot
```bash
# Quick screenshot (recommended) - no setup needed
fuel browser:screenshot --url=<url> --path=<file.png>

# Screenshot existing page
fuel browser:screenshot <page_id> --path=<file.png>
```
Capture the page as an image.

Options:
- `--full-page` - Capture entire scrollable page, it will scroll for you
- `--width=1280` - Viewport width (only with --url, default: 1280)
- `--height=720` - Viewport height (only with --url, default: 720)
- `--dark` - Use dark color scheme (only with --url)

### Run Playwright Code
```bash
fuel browser:run <page_id> "<code>"
```
Run Playwright code with access to the `page` object. Returns the result.

The code runs as an async function with `page` in scope. Use `return` to get values back.

Regular playwright code, some basic examples:
```bash
fuel browser:run page1 "await page.click('.submit-btn')"
fuel browser:run page1 "await page.fill('input[name=\"email\"]', 'test@example.com')"
fuel browser:run page1 "await page.waitForSelector('.modal', { state: 'hidden' })"
fuel browser:run page1 "return await page.locator('.item').count()"
fuel browser:run page1 "return await page.evaluate(() => window.scrollY)"
fuel browser:run page1 "await page.locator('.section').scrollIntoViewIfNeeded()"
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
Close a browser context and all its pages.
