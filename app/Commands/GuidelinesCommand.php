<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\FuelContext;
use LaravelZero\Framework\Commands\Command;

class GuidelinesCommand extends Command
{
    protected $signature = 'guidelines
        {--add : Inject guidelines into AGENTS.md and CLAUDE.md}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Output task management guidelines for agent instruction files';

    private const TARGET_FILES = ['AGENTS.md', 'CLAUDE.md'];

    public function handle(FuelContext $context): int
    {
        $content = $this->getGuidelinesContent();

        if ($this->option('add')) {
            return $this->injectIntoFiles($content, $context);
        }

        $this->line($content);

        return self::SUCCESS;
    }

    protected function injectIntoFiles(string $content, FuelContext $context): int
    {
        $cwd = $this->option('cwd') ?: $context->getProjectPath();
        $fuelSection = "<fuel>\n{$content}</fuel>\n";

        $updatedFiles = [];
        foreach (self::TARGET_FILES as $filename) {
            if ($this->injectIntoFile($cwd.'/'.$filename, $fuelSection, $filename)) {
                $updatedFiles[] = $filename;
            }
        }

        if ($updatedFiles !== []) {
            $this->info('Fuel guidelines updated: '.implode(', ', $updatedFiles));
        }

        return self::SUCCESS;
    }

    protected function injectIntoFile(string $path, string $fuelSection, string $filename): bool
    {
        if (file_exists($path)) {
            $existing = file_get_contents($path);

            // Replace existing <fuel>...</fuel> section or append
            if (preg_match('/<fuel>.*?<\/fuel>/s', $existing)) {
                $updated = preg_replace('/<fuel>.*?<\/fuel>\n?/s', $fuelSection, $existing);
            } else {
                $updated = rtrim($existing)."\n\n".$fuelSection;
            }

            file_put_contents($path, $updated);

            return true;
        }

        file_put_contents($path, "# Agent Instructions\n\n".$fuelSection);

        return true;
    }

    protected function getGuidelinesContent(): string
    {
        return <<<'MARKDOWN'
## Fuel Task Management

This project uses **Fuel** for lightweight task tracking. Tasks live in `.fuel/agent.db`.

### Quick Reference

```bash
fuel ready                      # Show tasks ready to work on
fuel add "Task title"           # Add a new task
fuel add "Idea" --someday       # Add to backlog (future work)
fuel start <id>                 # Claim a task (in_progress)
fuel done <id>                  # Mark task complete
fuel show <id>                  # View task details
fuel consume --once             # Kanban view
fuel tree                       # Tree view
fuel backlog                    # List backlog items
fuel promote <b-id>             # Promote backlog item to task
fuel defer <f-id>               # Move task to backlog
fuel dep:add <id> <blocker>     # Add dependency
fuel dep:remove <id> <blocker>  # Remove dependency
```

### TodoWrite vs Fuel

Use **TodoWrite** for single-session step tracking. Use **fuel** for work that outlives the session (multi-session, dependencies, discovered work for future). When unsure, prefer fuel.

### 🚨 MANDATORY: Session Close Protocol - Land The Plane
**YOU MUST COMPLETE EVERY STEP BELOW BEFORE EXITING. NO EXCEPTIONS.**

**For EPIC tasks** (task is part of an epic):
```
[ ] Run tests                     # Quality gate (if you changed code)
[ ] Run linter/formatter          # Fix formatting (if you changed code)
[ ] git add <files>               # Stage your changes
[ ] DO NOT commit                 # Commits happen after epic approval
[ ] fuel done <id>                # Mark complete (no --commit needed)
[ ] fuel add "..."                # File tasks for ANY remaining or discovered work
[ ] Hand off                      # Provide context for next session
```

**For STANDALONE tasks** (task has no epic):
```
[ ] Run tests                     # Quality gate (if you changed code)
[ ] Run linter/formatter          # Fix formatting (if you changed code)
[ ] git add <files>               # Stage your changes
[ ] git commit -m "feat/fix:..."  # Conventional commit - note the hash
[ ] fuel done <id> --commit=<hash># Mark complete with commit hash
[ ] fuel add "..."                # File tasks for ANY remaining or discovered work
[ ] Hand off                      # Provide context for next session
```

Commit messages: `feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`

### Workflow to work on one task

1. `fuel ready` - Find available work (prefer P0 > P1 > P2)
2. `fuel start <id>` - Claim task before starting
3. Do the work - implement, test, verify
4. Discover new work? `fuel add "..." --blocked-by=<id>`
5. `fuel done <id> --commit=<hash>` - Complete with commit hash
6. Land the plane

### Exiting Plan Mode

**Immediately after exiting plan mode**, convert your approved plan into well-defined Fuel tasks:

1. **Create an epic** for the overall feature or change
2. **Break down into scoped tasks** - each task should have:
   - Single, clear responsibility
   - Accurate `--complexity` rating
   - Proper `--blocked-by` dependencies
   - Descriptive title and description
3. **Order by dependencies** - foundational work first (models before services, services before commands)

**Example: Converting a plan to Fuel tasks**

After plan approval for "Add user authentication with JWT":

```bash
# Create epic for the feature
fuel epic:add "Add user authentication" --description="JWT-based auth with login/logout endpoints"

# Break into dependency-ordered tasks (note the epic ID from above)
fuel add "Create User model and migration" --epic=e-xxxx --complexity=simple --priority=1
fuel add "Implement JWT token service" --epic=e-xxxx --complexity=moderate --priority=1 --blocked-by=f-user-model
fuel add "Add login/logout API endpoints" --epic=e-xxxx --complexity=moderate --priority=1 --blocked-by=f-jwt-service
fuel add "Add auth middleware" --epic=e-xxxx --complexity=simple --priority=1 --blocked-by=f-jwt-service
fuel add "Add auth tests" --epic=e-xxxx --complexity=simple --priority=1 --blocked-by=f-endpoints,f-middleware
```

### Task Options

```bash
fuel add "Title" --description="..." --type=bug|fix|feature|task|epic|chore|docs|test|refactor --priority=0|1|2|3|4 --blocked-by=f-xxxx --labels=api,urgent --complexity=trivial|simple|moderate|complex
```

### Writing Good Descriptions

Descriptions should be explicit enough for a less capable agent to complete without guessing. Include: files to modify (exact paths), what to change (methods, patterns), expected behavior, and patterns to follow. **Give one clear solution, not options—subagents execute, they don't decide.**

**Bad**: "Fix the ID display bug"
**Good**: "BoardCommand.php:320 uses substr($id, 5, 4) for old format. Change to substr($id, 2, 6) for f-xxxxxx format."

### Complexity

**Always set `--complexity`:** `trivial` (typos) | `simple` (single focus) | `moderate` (multiple files) | `complex` (multiple files, requires judgement or careful coordination)

### Dependencies

```bash
fuel add "Implement API" --blocked-by=f-xxxx
```

Blocked tasks won't appear in `fuel ready` until blockers are done.

### Epics

**Use epics for any feature or change requiring multiple tasks.** Epics group related tasks and trigger a combined review when all tasks complete.

**When to create an epic:**
- Feature with 2+ tasks (e.g., "Add user preferences" → API + UI + tests)
- Refactoring spanning multiple files/concerns
- Any work you'd want reviewed as a coherent whole

**Workflow:**
1. `fuel epic:add "Feature name" --description="What and why"`
2. Break down into tasks, linking each: `fuel add "Task" --epic=e-xxxx`
3. Create a final review task (see below)
4. Work tasks individually; review task auto-unblocks when all dependencies close
5. Complete review task to close out the epic

**Epic review tasks are MANDATORY.** Always use `--complexity=complex` and list acceptance criteria:

```bash
fuel add "Review: Feature name" \
  --epic=e-xxxx \
  --complexity=complex \
  --blocked-by=f-task1,f-task2,... \
  --description="Verify epic complete. Acceptance criteria: 1) [behavior], 2) [API works], 3) [errors handled], 4) All tests pass: vendor/bin/pest path/to/tests"
```

**Review tasks must verify:**
1. **Intent** - Does it match the epic description? Would the user be happy?
2. **Correctness** - Do behaviors work? Tests pass? Edge cases handled?
3. **Quality** - No debug calls (dd, console.log), no useless comments, follows patterns

```bash
fuel epic:add "Add user preferences"    # Create epic (note the ID)
fuel add "Add preferences API" --epic=e-xxxx -e e-xxxx  # Link task
fuel add "Add preferences UI" --epic=e-xxxx             # Link another
fuel epics                               # List all epics with status
fuel epic:show <e-id>                   # View epic + linked tasks
fuel epic:reviewed <e-id>               # Mark as human-reviewed
```

**Always use epics for multi-task work.** Standalone tasks are fine for single-file fixes.

### Epic Commit Workflow

When working on epic tasks, changes are staged but NOT committed until the epic is approved:

1. **During epic work**: Each task stages changes with `git add` but does NOT commit
2. **On epic approval** (`fuel approve <epic>`): A commit task is automatically created
3. **Commit task**: An agent reviews all staged changes and organizes them into meaningful commits

**Benefits:**
- Cleaner git history with related changes grouped together
- Human reviews staged changes before committing
- Commits can be organized logically rather than per-task

**Example flow:**
```bash
# Agent 1 completes task in epic
git add src/Feature.php
fuel done f-abc123  # No --commit

# Agent 2 completes another task in epic
git add src/Service.php tests/FeatureTest.php
fuel done f-def456  # No --commit

# Human approves the epic
fuel approve e-xyz789
# Output: Created commit task: f-commit1

# Agent picks up commit task
# Reviews staged changes, creates organized commits
git commit -m "feat: add Feature with Service integration"
git commit -m "test: add Feature tests"
fuel done f-commit1 --commit=abc1234
```

### Backlog Management

The backlog is for **rough ideas and future work** that isn't ready to implement yet. Tasks are for **work ready to implement now**.

**When to use backlog vs tasks:**

- **Backlog (`fuel add --someday`)**: Rough ideas, future enhancements, "nice to have" features, exploratory concepts, work that needs more thought before implementation
- **Tasks (`fuel add`)**: Work that's ready to implement now, has clear requirements, can be started immediately

**Backlog commands:**

```bash
fuel add "Future idea" --someday          # Add to backlog (ignores other options)
fuel backlog                              # List all backlog items
fuel promote <f-id>                      # Promote backlog item to task (adds --priority, --type, etc.)
fuel defer <f-id>                         # Move a task to backlog
fuel remove <f-id>                        # Delete a backlog item
```

**Promoting backlog to tasks:**

When a backlog item is ready to work on:
1. Review the backlog: `fuel backlog`
2. Promote with task metadata: `fuel promote <f-id> --priority=2 --type=feature --complexity=moderate`
3. The backlog item status is updated from 'someday' to 'open'

**Deferring tasks:**

If a task isn't ready to work on (needs more planning, blocked externally, wrong priority):
1. `fuel defer <f-id>` - Moves task to backlog, preserving title and description
2. Later, promote it back when ready: `fuel promote <f-id> --priority=...`

### Needs-Human Workflow

When blocked on credentials, decisions, or manual steps:
1. `fuel add 'What you need' --labels=needs-human --description='Instructions'`
2. `fuel dep:add <your-task> <needs-human-task>` - Block your work
3. Human runs `fuel done <needs-human-task>`, your task reappears in `fuel ready`

### Parallel Execution to consume the fuel
Do this when asked to consume the fuel

Primary agent coordinates - subagents do NOT pick tasks:

1. Primary runs `fuel ready --json`, identifies parallel work
2. Primary claims each task with `fuel start <id>` before spawning
3. Primary spawns subagents with explicit task IDs and instructions to run `fuel done <id>`
4. Primary reviews subagent work (tests added? tests pass? matches requirements?)
5. If issues found: `fuel add "Fix X from f-xxxx"`
6. Check `fuel ready` for newly unblocked work

When parallel tasks share an interface, define it in a parent task's description. Avoid parallel work on tasks touching same files - use dependencies instead.

### Testing Visual Changes with Browser

This project includes a browser daemon for testing visual output (e.g., CLI output rendering, board displays, console formatting):

**Browser Daemon Setup:**
- Uses Playwright with headless Chrome/Chromium
- Managed via `BrowserDaemonManager` service
- Automatically starts when needed, stops on shutdown

**Testing Visual Output:**

1. **For CLI command output** - capture and verify visual rendering:
```php
use App\Services\BrowserDaemonManager;

test('board command renders correctly', function () {
    $browser = BrowserDaemonManager::getInstance();
    $browser->start();

    // Create context and page
    $browser->createContext('test-ctx', ['viewport' => ['width' => 1280, 'height' => 720]]);
    $browser->createPage('test-ctx', 'test-page');

    // Navigate to test HTML (e.g., terminal output rendered as HTML)
    $browser->goto('test-page', 'file:///path/to/output.html');

    // Take screenshot for visual comparison
    $result = $browser->screenshot('test-page', '/tmp/board-output.png', true);

    // Verify layout with JavaScript
    $check = $browser->eval('test-page', 'document.querySelector(".board-column").offsetWidth');
    expect($check['value'])->toBeGreaterThan(200);

    $browser->closeContext('test-ctx');
});
```

2. **For terminal output formatting** - verify alignment and spacing:
```php
// When testing commands with complex visual output (boards, trees, tables)
// 1. Capture the output
// 2. Convert ANSI to HTML or take terminal screenshot
// 3. Use browser daemon to verify visual properties
// 4. Check alignment, column widths, emoji rendering, etc.
```

**Common Visual Testing Scenarios:**
- Board column alignment with emojis (emojis are 2-chars wide in terminals)
- Tree structure indentation and connecting lines
- Table formatting and cell padding
- ANSI color rendering
- Multi-byte character handling (e.g., Japanese text)

**Environment Variables:**
- `FUEL_BROWSER_EXECUTABLE`: Override browser path if Chrome not in standard location

**Tips:**
- Visual tests should be marked appropriately: `@group visual`
- Screenshots are saved to `/tmp` by default, specify custom paths as needed
- Browser daemon auto-manages lifecycle, no manual cleanup needed
- Use `$browser->status()` to debug daemon state
MARKDOWN;
    }
}
