# Epic: Browser Commands Enhancement (Vercel-style) (e-91922e)

## Goal

Improve browser commands to match Vercel agent-browser patterns: accessibility snapshots with element refs, direct action commands, and proper testing infrastructure.

## Acceptance Criteria

- [x] JS test harness for browser-daemon.js with Vitest
- [x] `browser:snapshot` command with element refs (@e1, @e2)
- [x] `browser:click`, `browser:fill`, `browser:type` action commands
- [x] `browser:text`, `browser:html` query commands
- [x] `browser:wait` command for selector/URL/text
- [x] Element ref support (daemon-side memory, resolve @e1 to locator)
- [x] Updated skill documentation in `resources/skills/fuel-browser/SKILL.md`
- [x] All new commands have feature tests (click, fill, type, text, html, wait all complete)

---

## Architecture Overview

Adding a new browser method requires changes across **6 layers**:
1. `browser-daemon.js` - Add method handler
2. `app/Enums/ConsumeCommandType.php` - Add enum case
3. `app/Ipc/Commands/Browser*Command.php` - Create IPC command class
4. `app/Daemon/BrowserCommandHandler.php` - Add handler method
5. `app/Daemon/IpcCommandDispatcher.php` + `DaemonLoop.php` - Register routing
6. `app/Commands/Browser*Command.php` - Create CLI command

---

## Plan

### Phase 1: Testing Infrastructure

#### 1.1 JS Test Harness for browser-daemon.js
Create Vitest test suite for the daemon in isolation.

**Files:**
- `browser-daemon.test.js` (new)
- `package.json` (add test script + vitest dev dep)

**Approach:**
- Spawn daemon as child process
- Send JSON lines to stdin, read responses from stdout
- Test each method: ping, newContext, newPage, goto, screenshot, run, closeContext, status
- Test error cases: missing params, invalid IDs, duplicate IDs
- No actual browser needed for protocol tests (mock Playwright or use real browser in CI)

#### 1.2 PHP Feature Tests for New Commands
Each new command gets a feature test following existing pattern:
- Mock `ConsumeIpcClient`
- Create fake PID file in test directory
- Simulate `BrowserResponseEvent` responses
- Verify output and exit codes

---

### Phase 2: Core Feature - Snapshot with Refs

#### 2.1 `browser:snapshot` Command (Highest Priority)

**What it does:** Returns accessibility tree with element refs (@e1, @e2) for deterministic element selection.

**browser-daemon.js changes:**
```javascript
case "snapshot": {
  const pageId = params?.pageId;
  const interactiveOnly = params?.interactiveOnly || false;
  const entry = pages.get(pageId);
  // ... validation ...

  const snapshot = await entry.page.accessibility.snapshot({ interestingOnly: interactiveOnly });

  // Assign refs recursively
  let refCounter = 0;
  function assignRefs(node) {
    node.ref = `@e${++refCounter}`;
    if (node.children) node.children.forEach(assignRefs);
    return node;
  }

  return { ok: true, result: { snapshot: assignRefs(snapshot) } };
}
```

**CLI signature:**
```
browser:snapshot {page_id} {--interactive|-i : Only include interactive elements} {--json}
```

**Output format (text mode):**
```
@e1 [button] "Submit"
@e2 [textbox] "Email"
@e3 [link] "Learn more"
```

**Files to modify/create:**
- `browser-daemon.js` - Add snapshot method
- `app/Enums/ConsumeCommandType.php` - Add `BrowserSnapshot` case
- `app/Ipc/Commands/BrowserSnapshotCommand.php` (new)
- `app/Daemon/BrowserCommandHandler.php` - Add `handleBrowserSnapshot()`
- `app/Daemon/IpcCommandDispatcher.php` - Register callback
- `app/Daemon/DaemonLoop.php` - Wire callback
- `app/Services/BrowserDaemonManager.php` - Add `snapshot()` method
- `app/Commands/BrowserSnapshotCommand.php` (new)
- `tests/Feature/Commands/BrowserSnapshotCommandTest.php` (new)

---

### Phase 3: Action Commands

#### 3.1 `browser:click`
```
browser:click {page_id} {selector} {--ref= : Click by element ref from snapshot} {--json}
```

Supports both CSS selector and `@e1` refs. If `--ref` provided, look up actual selector from last snapshot (store in daemon memory per page).

#### 3.2 `browser:fill`
```
browser:fill {page_id} {selector} {value} {--ref=} {--json}
```

#### 3.3 `browser:type`
```
browser:type {page_id} {selector} {text} {--ref=} {--delay=0 : Delay between keystrokes in ms} {--json}
```

**Files per command (same pattern as snapshot):**
- Enum case, IPC command, handler, dispatcher, daemon manager, CLI command, test

---

### Phase 4: Query Commands

#### 4.1 `browser:text`
```
browser:text {page_id} {selector} {--ref=} {--json}
```
Returns `textContent` of element.

#### 4.2 `browser:html`
```
browser:html {page_id} {selector} {--ref=} {--inner : Return innerHTML instead of outerHTML} {--json}
```

---

### Phase 5: Wait Command

#### 5.1 `browser:wait`
```
browser:wait {page_id} {--selector= : Wait for selector} {--url= : Wait for URL pattern} {--text= : Wait for text to appear} {--state=visible : visible|hidden|attached|detached} {--timeout=30000} {--json}
```

---

### Phase 6: Element Ref Support

**Decision:** Daemon-side memory (confirmed)

Store last snapshot per page in daemon memory. When action commands receive `--ref=@e3`, resolve to actual selector/element server-side.

**Implementation:**
- `browser-daemon.js`: Add `pageSnapshots` Map (pageId → ref→node mapping)
- On snapshot: Build flat ref map, store in `pageSnapshots.set(pageId, refMap)`
- On click/fill/type with ref param: Look up node from `pageSnapshots.get(pageId)`
- Use Playwright's accessibility locator: `page.getByRole(node.role, { name: node.name })`
- Clear snapshot on page navigation (goto) to prevent stale refs
- Refs are ephemeral - must re-snapshot after page changes

---

### Phase 7: Documentation

#### 7.1 Update Skill Documentation
**File:** `resources/skills/fuel-browser/SKILL.md`

Add sections for:
- Snapshot workflow (snapshot → identify refs → act)
- New action commands
- New query commands
- Wait command
- Example workflows for AI agents

---

## File Summary

**New files:**
- `browser-daemon.test.js`
- `app/Ipc/Commands/BrowserSnapshotCommand.php`
- `app/Ipc/Commands/BrowserClickCommand.php`
- `app/Ipc/Commands/BrowserFillCommand.php`
- `app/Ipc/Commands/BrowserTypeCommand.php`
- `app/Ipc/Commands/BrowserTextCommand.php`
- `app/Ipc/Commands/BrowserHtmlCommand.php`
- `app/Ipc/Commands/BrowserWaitCommand.php`
- `app/Commands/BrowserSnapshotCommand.php`
- `app/Commands/BrowserClickCommand.php`
- `app/Commands/BrowserFillCommand.php`
- `app/Commands/BrowserTypeCommand.php`
- `app/Commands/BrowserTextCommand.php`
- `app/Commands/BrowserHtmlCommand.php`
- `app/Commands/BrowserWaitCommand.php`
- `tests/Feature/Commands/BrowserSnapshotCommandTest.php`
- `tests/Feature/Commands/BrowserClickCommandTest.php`
- (etc. for each command)

**Modified files:**
- `browser-daemon.js` - Add 7 new methods
- `package.json` - Add vitest + test script
- `app/Enums/ConsumeCommandType.php` - Add 7 enum cases
- `app/Daemon/BrowserCommandHandler.php` - Add 7 handlers
- `app/Daemon/IpcCommandDispatcher.php` - Register 7 callbacks
- `app/Daemon/DaemonLoop.php` - Wire 7 callbacks
- `app/Services/BrowserDaemonManager.php` - Add 7 wrapper methods
- `resources/skills/fuel-browser/SKILL.md` - Document new commands

---

## Verification

1. **JS daemon tests:** `npm test` passes
2. **PHP tests:** `./vendor/bin/pest tests/Feature/Commands/Browser*` passes
3. **Manual E2E:**
   ```bash
   fuel consume &
   fuel browser:create test test-page
   fuel browser:goto test-page "https://example.com"
   fuel browser:snapshot test-page -i
   # Should show interactive elements with @e1 refs
   fuel browser:click test-page "@e1"
   fuel browser:text test-page "h1"
   fuel browser:close test
   ```

---

## Progress Log

<!-- Self-guided task appends progress entries here -->
- Iteration 1: Implemented browser:snapshot command with element refs (@e1, @e2) and daemon-side ref storage
- Iteration 6: Added comprehensive test suite for browser-daemon.js with Vitest, including snapshot method tests
- Iteration 7: Implemented browser action commands (click, fill, type) with full element ref support across all 6 layers
- Iteration 8: Implemented browser:text and browser:html query commands with selector and ref support
- Iteration 9: Implemented browser:wait command for selector/URL/text with timeout support
- Iteration 10: Added feature test for browser:snapshot command
- Iteration 11: Added feature tests for browser:click and browser:fill commands
- Iteration 12: Added feature test for browser:type command with selector, ref, delay, and JSON output support
- Iteration 13: Added feature test for browser:text command and fixed namespace import bugs in BrowserTextCommand and BrowserHtmlCommand
- Iteration 14: Added feature test for browser:html command and fixed critical bugs in both browser:text and browser:html commands (port passing, IPC interface implementation, response handling)
- Iteration 15: Updated skill documentation in resources/skills/fuel-browser/SKILL.md with comprehensive documentation for all new browser commands, including element ref usage examples, 4 workflow patterns for AI agents, and best practices tips

## Implementation Notes

<!-- Tasks: append discoveries, decisions, gotchas here -->

## Interfaces Created

<!-- Tasks: document interfaces/contracts created -->
