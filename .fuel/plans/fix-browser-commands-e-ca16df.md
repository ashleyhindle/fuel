# Epic: Fix browser commands (e-ca16df)

## Plan

Fix all broken browser commands and improve consistency across the browser command suite.

### Issues Found (from browser-overview.md testing)

**Critical (broken commands):**
1. `browser:wait` - PHP fatal error: calls `isDaemonRunning()` which doesn't exist
2. `browser:html` - Times out, daemon doesn't respond to BrowserHtmlCommand
3. `browser:text` - Times out, daemon doesn't respond to BrowserTextCommand

**Minor (partial functionality):**
4. `browser:goto --html` flag accepted but doesn't return HTML in response

**Code quality:**
5. `browser:html`, `browser:text`, `browser:wait` don't extend BrowserCommand base class (duplicated boilerplate)

### Files to Modify

**PHP Commands:**
- `app/Commands/BrowserWaitCommand.php` - Fix `isDaemonRunning()` call, refactor to extend BrowserCommand
- `app/Commands/BrowserHtmlCommand.php` - Refactor to extend BrowserCommand
- `app/Commands/BrowserTextCommand.php` - Refactor to extend BrowserCommand

**Node.js Daemon (browser handlers):**
- `daemon/src/browser/handlers.ts` or similar - Add handlers for html/text commands

**IPC Commands:**
- Check `app/Ipc/Commands/BrowserHtmlCommand.php` and `BrowserTextCommand.php` match daemon expectations

## Acceptance Criteria

- [x] `browser:wait --selector=form` works without PHP errors
- [x] `browser:wait --text=Hello` works without PHP errors
- [x] `browser:wait --url=example` works without PHP errors
- [x] `browser:wait --selector=.missing --timeout=2000` times out gracefully with proper error
- [x] `browser:html page_id 'h1'` returns HTML content
- [ ] `browser:html page_id --ref=@e1` returns HTML using snapshot ref
- [ ] `browser:html page_id 'div' --inner` returns innerHTML
- [x] `browser:text page_id 'h1'` returns text content
- [ ] `browser:text page_id --ref=@e1` returns text using snapshot ref
- [ ] `browser:goto page_id url --html` returns HTML in response
- [ ] All browser commands have consistent error handling
- [ ] Run `./vendor/bin/pest tests/Feature/Commands/Browser*` - all tests pass
- [ ] Run `./vendor/bin/pint` - code formatted

## Progress Log

- Iteration 1: Fixed browser:wait PHP fatal error by refactoring to extend BrowserCommand base class, updated tests to match new pattern (commit 14df009)
- Iteration 2: Fixed browser:html and browser:text commands by refactoring them to extend BrowserCommand base class, both now working correctly

## Implementation Notes

### BrowserWaitCommand Fix
The command currently uses dependency injection for `ConsumeIpcClient` but calls a non-existent method. Two options:
1. Add `isDaemonRunning()` method to ConsumeIpcClient
2. Use `isRunnerAlive($pidFilePath)` pattern like other commands

Recommend option 2 for consistency with other browser commands.

### Daemon Handler Investigation
Need to check `daemon/` directory for:
- How other browser commands are handled
- Pattern for adding new handlers
- Whether BrowserHtmlCommand/BrowserTextCommand IPC messages are being sent correctly

### BrowserCommand Base Class
Commands that should extend BrowserCommand but don't:
- BrowserHtmlCommand (has its own boilerplate)
- BrowserTextCommand (has its own boilerplate)
- BrowserWaitCommand (different pattern entirely)
- BrowserSnapshotCommand (has its own boilerplate)

These share common patterns that could be deduplicated.

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->
