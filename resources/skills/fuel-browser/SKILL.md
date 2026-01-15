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

### Accessibility Snapshot
```bash
fuel browser:snapshot <page_id> [--interactive] [--json]
```
Get the accessibility tree with element references (@e1, @e2, etc) for deterministic element selection.

Options:
- `--interactive` / `-i` - Only include interactive elements (buttons, links, inputs)
- `--json` - Output as JSON instead of formatted text

Example output (text mode):
```
@e1 [heading] "Welcome to Example.com"
@e2 [link] "Learn more"
@e3 [button] "Subscribe"
@e4 [textbox] "Email address"
```

**Workflow:** Snapshot first to get refs, then use refs in action commands:
```bash
# Get snapshot to see available elements
fuel browser:snapshot page1 --interactive

# Use the refs in subsequent commands
fuel browser:click page1 --ref=@e3
fuel browser:fill page1 --ref=@e4 "user@example.com"
```

### Click Element
```bash
fuel browser:click <page_id> <selector> [--ref=] [--json]
```
Click an element by CSS selector or element ref from snapshot.

Options:
- `--ref=@e1` - Click by element ref from last snapshot (alternative to selector)
- `--json` - JSON output

Examples:
```bash
# Click by CSS selector
fuel browser:click page1 "button.submit"

# Click by element ref from snapshot
fuel browser:click page1 --ref=@e3
```

### Fill Input
```bash
fuel browser:fill <page_id> <selector> <value> [--ref=] [--json]
```
Fill an input field with text.

Options:
- `--ref=@e1` - Target by element ref instead of selector
- `--json` - JSON output

Examples:
```bash
# Fill by CSS selector
fuel browser:fill page1 "input#email" "test@example.com"

# Fill by element ref
fuel browser:fill page1 --ref=@e4 "test@example.com"
```

### Type Text
```bash
fuel browser:type <page_id> <selector> <text> [--ref=] [--delay=0] [--json]
```
Type text character by character, simulating real keyboard input.

Options:
- `--ref=@e1` - Target by element ref instead of selector
- `--delay=100` - Delay between keystrokes in milliseconds
- `--json` - JSON output

Examples:
```bash
# Type with realistic delay
fuel browser:type page1 "input#search" "fuel task manager" --delay=50

# Type using element ref
fuel browser:type page1 --ref=@e2 "hello world"
```

### Get Text Content
```bash
fuel browser:text <page_id> <selector> [--ref=] [--json]
```
Get the text content of an element.

Options:
- `--ref=@e1` - Target by element ref instead of selector
- `--json` - JSON output

Examples:
```bash
# Get heading text
fuel browser:text page1 "h1"

# Get text using element ref
fuel browser:text page1 --ref=@e1
```

### Get HTML
```bash
fuel browser:html <page_id> <selector> [--ref=] [--inner] [--json]
```
Get the HTML of an element.

Options:
- `--ref=@e1` - Target by element ref instead of selector
- `--inner` - Return innerHTML instead of outerHTML
- `--json` - JSON output

Examples:
```bash
# Get outer HTML of element
fuel browser:html page1 "div.content"

# Get inner HTML only
fuel browser:html page1 "div.content" --inner

# Get HTML using element ref
fuel browser:html page1 --ref=@e5 --inner
```

### Wait for Condition
```bash
fuel browser:wait <page_id> [--selector=] [--url=] [--text=] [--state=visible] [--timeout=30000] [--json]
```
Wait for various conditions: selector to appear, URL to match, or text to be visible.

Options:
- `--selector="..."` - Wait for CSS selector
- `--url="..."` - Wait for URL pattern (supports wildcards)
- `--text="..."` - Wait for text to appear anywhere on page
- `--state=visible|hidden|attached|detached` - State for selector (default: visible)
- `--timeout=30000` - Timeout in milliseconds
- `--json` - JSON output

Examples:
```bash
# Wait for modal to appear
fuel browser:wait page1 --selector=".modal" --state=visible

# Wait for navigation
fuel browser:wait page1 --url="**/dashboard"

# Wait for success message
fuel browser:wait page1 --text="Payment successful"

# Wait for loading spinner to disappear
fuel browser:wait page1 --selector=".spinner" --state=hidden --timeout=10000
```

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

## Example Workflows for AI Agents

### Workflow 1: Testing Form Submission

```bash
# 1. Create browser and navigate to form
fuel browser:create test test-page
fuel browser:goto test-page "http://localhost:3000/contact"

# 2. Get snapshot to see available elements
fuel browser:snapshot test-page --interactive
# Output:
# @e1 [textbox] "Name"
# @e2 [textbox] "Email"
# @e3 [textarea] "Message"
# @e4 [button] "Send Message"

# 3. Fill form using element refs
fuel browser:fill test-page --ref=@e1 "John Doe"
fuel browser:fill test-page --ref=@e2 "john@example.com"
fuel browser:type test-page --ref=@e3 "This is a test message" --delay=50

# 4. Submit and wait for success
fuel browser:click test-page --ref=@e4
fuel browser:wait test-page --text="Thank you for your message"

# 5. Verify result and cleanup
fuel browser:text test-page ".success-message"
fuel browser:close test
```

### Workflow 2: Visual Regression Testing

```bash
# Quick screenshot for comparison
fuel browser:screenshot --url="http://localhost:3000" --path=/tmp/homepage-desktop.png --width=1920 --height=1080
fuel browser:screenshot --url="http://localhost:3000" --path=/tmp/homepage-mobile.png --width=375 --height=812
fuel browser:screenshot --url="http://localhost:3000" --path=/tmp/homepage-dark.png --dark
```

### Workflow 3: SPA Navigation Testing

```bash
# Create context
fuel browser:create spa spa-page

# Navigate to app
fuel browser:goto spa-page "http://localhost:3000" --wait-until=networkidle

# Get initial snapshot
fuel browser:snapshot spa-page -i

# Navigate within SPA
fuel browser:click spa-page "a[href='/products']"
fuel browser:wait spa-page --url="**/products"

# Verify content loaded
fuel browser:wait spa-page --selector=".product-grid" --state=visible
fuel browser:text spa-page "h1"  # Should show "Products"

# Test search
fuel browser:fill spa-page "input[type='search']" "laptop"
fuel browser:wait spa-page --text="Search results"

# Cleanup
fuel browser:close spa
```

### Workflow 4: Accessibility-First Testing

```bash
# Navigate to page
fuel browser:create a11y page1
fuel browser:goto page1 "http://example.com"

# Get full accessibility tree
fuel browser:snapshot page1

# Interact using semantic roles (from snapshot refs)
fuel browser:click page1 --ref=@e5  # Click button by ref
fuel browser:fill page1 --ref=@e12 "search term"  # Fill search box

# Verify ARIA states
fuel browser:html page1 "[role='alert']" --inner

# Cleanup
fuel browser:close a11y
```

## Tips for AI Agents

1. **Always snapshot first** when you need to interact with a page - it gives you reliable element references
2. **Use element refs (@e1, @e2)** for reliability - they're based on accessibility tree, not brittle CSS selectors
3. **Use --wait-until=networkidle** for SPAs to ensure all async content loads
4. **Use browser:wait** after actions that trigger navigation or async updates
5. **Use --interactive flag** on snapshot to see only actionable elements
6. **Re-snapshot after navigation** - refs become invalid after page changes
