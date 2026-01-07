# Laravel Boost: Minimal Task Management System

**A beads-inspired, JSONL-only task tracking system for Laravel projects**

Version: 1.0 MVP
Last Updated: 2025-01-03

---

## Overview

Ultra-simple git-backed task management system designed for AI-supervised coding workflows. Zero database setup, zero migrations, just read/write JSONL files. Perfect for small projects with <50 tasks.

**Core Value Proposition:** Dependency-aware task tracking that tells AI agents (and humans) what work is ready to start.

---

## Key Features

### Essential Features (MVP)

1. **Create/Update/Close Tasks** - Basic CRUD operations via artisan commands
2. **Dependency Tracking** - "Task X blocks Task Y" relationships
3. **Ready Work Detection** - Show tasks with no open blockers (the killer feature!)
4. **Hash-Based IDs** - Collision-free distributed task creation (`boost-a7f3`)
5. **Git-Native Storage** - JSONL files tracked in git, merge-friendly
6. **JSON Output** - Every command supports `--json` for AI agents
7. **AGENTS.md Integration** - Auto-generate instructions for AI tools

### What's NOT Included (Deliberately Simple)

- ❌ No database (SQLite, MySQL, etc.) - just JSONL files
- ❌ No daemon/background processes - direct file reads/writes
- ❌ No auto-sync - manual via git hooks
- ❌ No complex workflows (gates, formulas, molecules)
- ❌ No web UI - CLI only
- ❌ No user authentication - single-user focused

---

## Design Philosophy

### 1. JSONL-Only Storage (No Database)

**Decision:** Use plain JSONL files instead of SQLite/MySQL.

**Why:**
- Zero setup complexity (no migrations, no schema)
- <50 tasks = <50 lines = <5KB file
- Linear scan is fast enough (<10ms on modern hardware)
- Laravel Collections make filtering elegant
- Git-native (diff-friendly, merge-friendly)
- Works everywhere (no DB driver dependencies)

**Trade-offs Accepted:**
- No SQL queries (use Collections instead)
- No foreign keys (validate in code)
- No indexes (don't need them at this scale)
- Parse entire file each time (fast enough)

**When to Migrate:**
- >100 tasks (queries start slowing down)
- Need complex joins/aggregations
- Performance becomes an issue

### 2. Hash-Based IDs (Collision-Free)

**Decision:** Use content-derived hash IDs instead of sequential IDs.

**Why:**
```
Sequential (BAD):
  Branch A: creates task → fuel-10
  Branch B: creates task → fuel-10 (COLLISION!)

Hash-Based (GOOD):
  Branch A: creates task → fuel-a7f3
  Branch B: creates task → fuel-k9m2 (NO COLLISION)
```

**Implementation:**
```php
$hash = hash('sha256', uniqid('fuel-', true) . microtime());
$id = 'fuel-' . substr($hash, 0, $length);
```

**Adaptive Length:**
- <500 tasks: 4 chars (~16K combinations)
- <1,500 tasks: 5 chars (~1M combinations)
- <10,000 tasks: 6 chars (~16M combinations)
- 10,000+ tasks: 7 chars (~268M combinations)

### 3. Tombstone Pattern (Prevent Resurrection)

**Decision:** Soft-delete via tombstone records instead of hard-deleting.

**Why:**
```
Without Tombstones:
  1. Branch A deletes task boost-x1y2
  2. Branch B still has task boost-x1y2
  3. Merge → task resurrects! (BAD)

With Tombstones:
  1. Branch A marks boost-x1y2 as tombstone
  2. Branch B still has live task boost-x1y2
  3. Merge → tombstone wins, task stays deleted (GOOD)
```

**Implementation:**
```json
{
  "id": "boost-x1y2",
  "status": "tombstone",
  "title": "Original title",
  "deleted_at": "2025-01-15T10:30:00Z",
  "deleted_by": "user"
}
```

### 4. Atomic Writes (Prevent Corruption)

**Decision:** Always use temp file + rename for writes.

**Why:**
```
Direct Write (BAD):
  1. Open tasks.jsonl for writing
  2. Power outage during write
  3. File corrupted! (CATASTROPHIC)

Temp File + Rename (GOOD):
  1. Write to tasks.jsonl.tmp
  2. Rename to tasks.jsonl (atomic on Unix/Windows)
  3. Power outage → old file intact OR new file complete (SAFE)
```

**Implementation:**
```php
$tempPath = $filePath . '.tmp';
file_put_contents($tempPath, $content);
rename($tempPath, $filePath);  // Atomic operation
```

### 5. Sorted JSONL (Merge-Friendly)

**Decision:** Always sort tasks by ID before writing.

**Why:**
```
Unsorted (BAD):
  Branch A: [task-b, task-a, task-c]
  Branch B: [task-c, task-a, task-b]
  Merge → massive conflicts (line-by-line diff)

Sorted (GOOD):
  Branch A: [task-a, task-b, task-c]
  Branch B: [task-a, task-b, task-c]
  Merge → minimal conflicts (only actual changes)
```

**Implementation:**
```php
$tasks->sortBy('id')->each(fn($task) =>
    fwrite($handle, json_encode($task) . "\n")
);
```

---

## Data Structure

### Task JSON Schema

Each line in `tasks.jsonl` is a complete task:

```json
{
  "id": "fuel-a7f3",
  "title": "Implement user authentication",
  "description": "Add login/logout functionality with session management",
  "type": "feature",
  "status": "open",
  "priority": 1,
  "created_at": "2025-01-15T10:30:00Z",
  "updated_at": "2025-01-15T10:30:00Z",
  "created_by": "agent-123",
  "dependencies": [
    {
      "depends_on": "fuel-x1y2",
      "type": "blocks"
    }
  ],
  "labels": ["auth", "backend", "security"]
}
```

### Field Definitions

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `id` | string | Yes | auto | Hash-based ID (fuel-{4-7chars}) |
| `title` | string | Yes | - | Task title (max 255 chars) |
| `description` | string | No | null | Long description |
| `type` | enum | No | task | bug\|feature\|task\|epic\|chore |
| `status` | enum | No | open | open\|in_progress\|closed\|tombstone |
| `priority` | int | No | 2 | 0 (critical) to 4 (backlog) |
| `created_at` | ISO8601 | Yes | auto | When created |
| `updated_at` | ISO8601 | Yes | auto | Last modification |
| `created_by` | string | No | null | Actor who created |
| `dependencies` | array | No | [] | Array of dependency objects |
| `labels` | array | No | [] | Array of label strings |

### Enums

**Task Types:**
- `bug` - Something broken that needs fixing
- `feature` - New functionality
- `task` - Work item (tests, docs, refactoring)
- `epic` - Large feature composed of multiple tasks
- `chore` - Maintenance work (dependencies, tooling)

**Task Statuses:**
- `open` - Not started yet
- `in_progress` - Currently being worked on
- `closed` - Completed successfully
- `tombstone` - Soft-deleted (prevents resurrection)

**Priorities:**
- `0` - Critical (security, data loss, broken builds)
- `1` - High (major features, important bugs)
- `2` - Medium (nice-to-have features, minor bugs) **[default]**
- `3` - Low (polish, optimization)
- `4` - Backlog (future ideas)

**Dependency Types:**
- `blocks` - Hard dependency (task X must close before Y can start)

---

## Architecture

### File Structure

```
.ai/fuel/
└── tasks.jsonl          # All tasks (one JSON object per line)

AGENTS.md                 # Updated with task instructions
```

### Service Layer

**Core Service:** `TaskService.php`

Responsibilities:
- Read/write JSONL file (atomic)
- Parse tasks into PHP arrays
- Filter/query using Laravel Collections
- Generate hash-based IDs
- Validate dependencies (no cycles)
- Calculate ready work (blocked/unblocked detection)

**Key Methods:**

```php
// CRUD
all(): Collection                    // Load all tasks
find(string $id): ?array            // Find by ID
create(array $data): array          // Create task
update(string $id, array $data): array  // Update task
close(string $id, ?string $reason): array  // Close task
delete(string $id): void            // Tombstone

// Queries
ready(): Collection                 // Unblocked tasks
blocked(): Collection               // Blocked tasks
search(array $filters): Collection  // Generic filter

// Dependencies
addDependency(string $from, string $to, string $type): void
removeDependency(string $from, string $to): void
getDependencies(string $id): Collection
getBlockers(string $id): Collection

// Utilities
generateId(): string                // Hash-based ID generation
validateNoCycles(string $from, string $to): bool
```

### Algorithm: Ready Work Calculation

**Problem:** Find tasks with no open blockers.

**Approach:**

```php
function ready(): Collection {
    $tasks = loadAll();

    // Step 1: Build blocker index
    $blockedIds = [];
    foreach ($tasks as $task) {
        foreach ($task['dependencies'] as $dep) {
            if ($dep['type'] === 'blocks') {
                $blocker = $tasks[$dep['depends_on']];
                if ($blocker && $blocker['status'] !== 'closed') {
                    $blockedIds[] = $task['id'];
                }
            }
        }
    }

    // Step 2: Filter to open tasks without blockers
    return $tasks
        ->filter(fn($t) => $t['status'] === 'open')
        ->filter(fn($t) => !in_array($t['id'], $blockedIds))
        ->sortBy([
            ['priority', 'asc'],
            ['created_at', 'asc']
        ]);
}
```

**Complexity:** O(n²) worst case (every task blocks every other task)
**Performance:** <10ms for <50 tasks

### Algorithm: Cycle Detection

**Problem:** Prevent circular dependencies (A blocks B blocks C blocks A).

**Approach:** Breadth-first search

```php
function validateNoCycles(string $from, string $to): bool {
    $tasks = loadAll();
    $visited = [];
    $queue = [$to];

    while (!empty($queue)) {
        $current = array_shift($queue);

        if ($current === $from) {
            return false;  // Cycle detected!
        }

        if (in_array($current, $visited)) {
            continue;
        }

        $visited[] = $current;

        // Add all blockers to queue
        $task = $tasks[$current];
        foreach ($task['dependencies'] ?? [] as $dep) {
            if ($dep['type'] === 'blocks') {
                $queue[] = $dep['depends_on'];
            }
        }
    }

    return true;  // No cycle
}
```

**Complexity:** O(n²) worst case (dense dependency graph)
**Performance:** <10ms for <50 tasks

---

## Artisan Commands

### Command: `boost:init`

**Initialize task tracking in project.**

```bash
php artisan boost:init
```

**What It Does:**
1. Creates `tasks.jsonl` (empty file)
2. Updates `AGENTS.md` with task management instructions
3. Optionally installs git hooks (pre-commit, post-merge)
4. Shows welcome message with next steps

**Interactive Prompts:**
- "Initialize task tracking?" [yes/no]
- "Install git hooks?" [yes/no]

**Output:**
```
  Laravel Boost Task Management

  ✓ Created storage/boost/tasks.jsonl
  ✓ Updated AGENTS.md with task instructions
  ✓ Installed git hooks

  Get started:
    php artisan fuel:create "Your first task"
    php artisan fuel:ready
```

### Command: `fuel:create`

**Create a new task.**

```bash
php artisan fuel:create "Implement feature X" \
  --type=feature \
  --priority=1 \
  --description="Long description here" \
  --depends-on=boost-x1y2,boost-z3k4 \
  --labels=backend,api \
  --json
```

**Options:**
- `title` (required) - Task title
- `--type=` - Task type (default: task)
- `--priority=` - Priority 0-4 (default: 2)
- `--description=` - Long description
- `--depends-on=` - Comma-separated task IDs this depends on
- `--labels=` - Comma-separated labels
- `--json` - Output JSON instead of human-readable

**Output (Human):**
```
✓ Created task: boost-a7f3
  Title: Implement feature X
  Type: feature
  Priority: 1
```

**Output (JSON):**
```json
{
  "id": "boost-a7f3",
  "title": "Implement feature X",
  "type": "feature",
  "status": "open",
  "priority": 1,
  "created_at": "2025-01-15T10:30:00Z",
  "dependencies": [...]
}
```

### Command: `fuel:ready`

**Show tasks with no open blockers.**

```bash
php artisan fuel:ready --json
```

**What It Shows:**
- Tasks with `status=open`
- That have NO dependencies on open tasks
- Sorted by priority (0→4), then creation date

**Output (Human):**
```
Ready Work (3 tasks):

╔═══════════╤══════════════╤═════════╤═══════════════════════════╗
║ ID        │ Priority     │ Type    │ Title                     ║
╠═══════════╪══════════════╪═════════╪═══════════════════════════╣
║ boost-a7f3│ P1 (High)    │ feature │ Implement authentication  ║
║ boost-k9m2│ P2 (Medium)  │ bug     │ Fix login redirect        ║
║ boost-x3y4│ P2 (Medium)  │ task    │ Write unit tests          ║
╚═══════════╧══════════════╧═════════╧═══════════════════════════╝
```

**Output (JSON):**
```json
[
  {
    "id": "boost-a7f3",
    "title": "Implement authentication",
    "type": "feature",
    "status": "open",
    "priority": 1,
    "created_at": "2025-01-15T10:30:00Z"
  },
  ...
]
```

### Command: `fuel:list`

**List tasks with filters.**

```bash
php artisan fuel:list \
  --status=open \
  --type=bug \
  --priority=1 \
  --labels=backend \
  --json
```

**Options:**
- `--status=` - Filter by status (open|in_progress|closed)
- `--type=` - Filter by type (bug|feature|task|epic|chore)
- `--priority=` - Filter by priority (0-4)
- `--labels=` - Comma-separated labels (AND logic)
- `--json` - Output JSON

### Command: `fuel:show`

**Show full task details.**

```bash
php artisan fuel:show boost-a7f3 --json
```

**Output:**
```
Task: boost-a7f3

  Title:       Implement user authentication
  Type:        feature
  Status:      open
  Priority:    1 (High)
  Created:     2025-01-15 10:30:00
  Updated:     2025-01-15 10:30:00

  Description:
  Add login/logout functionality with session management

  Dependencies (blocks):
    boost-x1y2 - Design auth system

  Labels:
    auth, backend, security
```

### Command: `fuel:update`

**Update task fields.**

```bash
php artisan fuel:update boost-a7f3 \
  --status=in_progress \
  --priority=0 \
  --title="New title" \
  --add-labels=urgent \
  --remove-labels=backend \
  --json
```

**Options:**
- `--status=` - Update status
- `--priority=` - Update priority
- `--title=` - Update title
- `--description=` - Update description
- `--add-labels=` - Add labels (comma-separated)
- `--remove-labels=` - Remove labels (comma-separated)
- `--json` - Output JSON

### Command: `fuel:close`

**Close a task.**

```bash
php artisan fuel:close boost-a7f3 --reason="Completed successfully"
```

**What It Does:**
1. Sets `status` to `closed`
2. Sets `closed_at` to current timestamp
3. Records close reason (optional)

**Options:**
- `--reason=` - Why the task was closed
- `--json` - Output JSON

### Command: `fuel:dep:add`

**Add dependency between tasks.**

```bash
php artisan fuel:dep:add boost-child boost-parent --type=blocks
```

**Arguments:**
- `from` - Task that depends on something
- `to` - Task it depends on
- `--type=` - Dependency type (default: blocks)

**Validations:**
- Both tasks must exist
- No cycles allowed (prevents A→B→C→A)

**Output:**
```
✓ Added dependency: boost-child blocks on boost-parent
```

### Command: `fuel:dep:remove`

**Remove dependency.**

```bash
php artisan fuel:dep:remove boost-child boost-parent
```

**Output:**
```
✓ Removed dependency between boost-child and boost-parent
```

---

## Git Integration

### Pre-Commit Hook

**File:** `.git/hooks/pre-commit`

**Purpose:** Auto-stage `tasks.jsonl` if modified.

**Implementation:**
```bash
#!/bin/sh
# Auto-stage tasks.jsonl if modified
if [ -f storage/boost/tasks.jsonl ]; then
    git add storage/boost/tasks.jsonl
fi
```

**Why:** Ensures task changes are always committed with code changes.

### Post-Merge Hook

**File:** `.git/hooks/post-merge`

**Purpose:** Nothing needed! JSONL is already merged by git.

**Note:** In the future, could add custom 3-way merge driver for smart conflict resolution.

### Installation

```bash
php artisan fuel:hooks --install
```

**What It Does:**
1. Copies hooks from package `stubs/hooks/` to `.git/hooks/`
2. Makes hooks executable (`chmod +x`)
3. Shows confirmation message

---

## AGENTS.md Integration

When user runs `boost:init`, append these instructions to `AGENTS.md`:

```markdown
## Task Management with Laravel Boost

This project uses Laravel Boost for task tracking. All tasks are stored in `storage/boost/tasks.jsonl` and synced via git.

### Quick Reference

```bash
# Find ready work (no blockers)
php artisan fuel:ready --json

# Create new task
php artisan fuel:create "Task title" \
  --type=feature \
  --priority=1 \
  --description="Details here" \
  --json

# Update task status
php artisan fuel:update boost-a7f3 --status=in_progress --json

# Complete work
php artisan fuel:close boost-a7f3 --reason="Done" --json

# Show task details
php artisan fuel:show boost-a7f3 --json

# Link discovered work to current task
php artisan fuel:dep:add boost-new boost-current --type=blocks
```

### Workflow

1. **Check for ready work**: `fuel:ready --json`
2. **Claim a task**: `fuel:update <id> --status=in_progress`
3. **Work on it**: Implement, test, document
4. **Discover new work?**: Create tasks and link with dependencies
5. **Complete**: `fuel:close <id> --reason="Implemented"`
6. **Commit changes**: Git automatically stages `tasks.jsonl`

### Task Types

- `bug` - Something broken that needs fixing
- `feature` - New functionality
- `task` - Work item (tests, docs, refactoring)
- `epic` - Large feature composed of multiple tasks
- `chore` - Maintenance work

### Priorities

- `0` - Critical (security, data loss, broken builds)
- `1` - High (major features, important bugs)
- `2` - Medium (nice-to-have features, minor bugs) *[default]*
- `3` - Low (polish, optimization)
- `4` - Backlog (future ideas)

### Dependency Types

- `blocks` - Hard dependency (task X blocks task Y)
- Only `blocks` dependencies affect the ready work queue

### JSON Output

All commands support `--json` flag for programmatic use. Output format:

```json
{
  "id": "boost-a7f3",
  "title": "Task title",
  "type": "feature",
  "status": "open",
  "priority": 1,
  "created_at": "2025-01-15T10:30:00Z",
  "dependencies": [...]
}
```
```

---

## Performance Characteristics

### For <50 Tasks

| Operation | Complexity | Time (Estimate) |
|-----------|-----------|-----------------|
| Read all tasks | O(n) | ~2ms |
| Find by ID | O(n) | ~2ms |
| Create task | O(n log n) | ~5ms |
| Update task | O(n log n) | ~5ms |
| Ready work | O(n²) worst | ~10ms |
| Cycle detection | O(n²) worst | ~10ms |

**Why This Is Acceptable:**
- Modern PHP: 50-line JSON parse is instant (<5ms)
- Laravel Collections: Optimized C-level code
- File I/O on SSD: Read/write ~1ms
- Total latency: <20ms for any operation
- Perfectly acceptable for CLI tool

### Scaling Considerations

**Sweet Spot:** 10-50 tasks
**Acceptable:** 50-100 tasks
**Needs Migration:** >100 tasks (consider SQLite)

**Performance Degradation:**
- 50 tasks: <20ms operations
- 100 tasks: <50ms operations
- 200 tasks: <200ms operations (noticeable lag)
- 500 tasks: <1s operations (definitely need SQLite)

### When to Migrate to SQLite

**Signals:**
- Operations taking >100ms
- Frequent timeout errors
- Users complaining about slowness
- Task count exceeding 100

**Migration Path:**
```bash
php artisan boost:migrate:to-sqlite
```

This would:
1. Create SQLite database
2. Import all tasks from JSONL
3. Create indexes for performance
4. Switch `TaskService` to SQLite backend
5. Keep JSONL in sync for git (dual storage mode)

---

## Error Handling

### Validation Errors

**Cycle Detection:**
```
Error: Circular dependency detected!
  boost-a7f3 → boost-k9m2 → boost-x3y4 → boost-a7f3

Dependencies must form a directed acyclic graph (DAG).
```

**Missing Task:**
```
Error: Task 'boost-xyz' not found

Available tasks:
  - boost-a7f3
  - boost-k9m2
  - boost-x3y4
```

**Invalid Status:**
```
Error: Invalid status 'invalid-status'

Valid statuses:
  - open
  - in_progress
  - closed
  - tombstone
```

### File System Errors

**Permission Denied:**
```
Error: Cannot write to storage/boost/tasks.jsonl

Please check file permissions:
  chmod 644 storage/boost/tasks.jsonl
  chmod 755 storage/boost/
```

**Corrupted JSONL:**
```
Error: Failed to parse tasks.jsonl on line 42

JSON Parse Error: Unexpected token at character 123

Please check the file for syntax errors or restore from git.
```

---

## Testing Strategy

### Unit Tests

**Core Service Tests:**
```php
TaskServiceTest
  ✓ creates task with hash-based ID
  ✓ finds task by ID
  ✓ updates task fields
  ✓ closes task with reason
  ✓ marks deleted task as tombstone
  ✓ ready() excludes blocked tasks
  ✓ ready() excludes closed tasks
  ✓ validates no cycles on add dependency
  ✓ prevents circular dependencies
  ✓ sorts tasks by ID before write
  ✓ uses atomic write (temp file + rename)
```

**ID Generation Tests:**
```php
IdGenerationTest
  ✓ generates 4-char hash for <500 tasks
  ✓ generates 5-char hash for 500-1500 tasks
  ✓ generates 6-char hash for 1500-10000 tasks
  ✓ generates unique IDs for concurrent creation
  ✓ IDs are URL-safe (no special chars)
```

**Dependency Tests:**
```php
DependencyTest
  ✓ adds blocks dependency
  ✓ removes dependency
  ✓ gets all dependencies of task
  ✓ gets all blockers of task
  ✓ detects simple cycle (A→B→A)
  ✓ detects complex cycle (A→B→C→A)
  ✓ allows non-cyclic dependencies
```

### Command Tests

```php
CreateCommandTest
  ✓ creates task via CLI
  ✓ creates task with dependencies
  ✓ creates task with labels
  ✓ outputs JSON when --json flag

ReadyCommandTest
  ✓ shows unblocked tasks
  ✓ excludes blocked tasks
  ✓ excludes closed tasks
  ✓ sorts by priority then creation date
  ✓ outputs JSON when --json flag
```

### Integration Tests

```php
GitIntegrationTest
  ✓ pre-commit hook stages tasks.jsonl
  ✓ tasks.jsonl is git-tracked
  ✓ merge doesn't corrupt file
  ✓ tombstones prevent resurrection
```

---

## Future Enhancements

### Version 1.1 (Polish)

- [ ] Better terminal output (colors, tables, progress bars)
- [ ] More filtering options (date ranges, created_by)
- [ ] Statistics command (`fuel:stats`)
- [ ] Better validation error messages
- [ ] Tab completion for task IDs
- [ ] Interactive task creation (prompts for missing fields)

### Version 1.2 (Advanced Features)

- [ ] Comments on tasks
- [ ] Task templates (common patterns)
- [ ] Bulk operations (close multiple, update multiple)
- [ ] Search command (full-text search in titles/descriptions)
- [ ] Export to other formats (markdown, CSV, JSON)

### Version 2.0 (Scale)

- [ ] Optional SQLite backend (for >100 tasks)
- [ ] Blocked issues cache (pre-computed for performance)
- [ ] 3-way merge driver (smart git conflict resolution)
- [ ] Compaction (archive old closed tasks)
- [ ] Time-based scheduling (due dates, defer until)
- [ ] Assignee field (multi-user support)

### Version 3.0 (AI Workflows)

- [ ] Gates for async coordination (human approval, CI checks)
- [ ] Agent tracking (who worked on what)
- [ ] Audit trail (full change history)
- [ ] Multi-project support (dependencies across projects)
- [ ] Formula system (workflow templates)

---

## Comparison to Full Beads

| Feature | Laravel Boost (JSONL) | Full Beads |
|---------|----------------------|------------|
| **Storage** | JSONL only | SQLite + JSONL |
| **Performance** | <50 tasks | 10,000+ tasks |
| **Setup Complexity** | Zero (no DB) | Medium (migrations) |
| **Auto-Sync** | Git hooks | Daemon with fsnotify |
| **Concurrency** | Single-process | Multi-agent safe |
| **Dependencies** | Basic (blocks only) | Full (4 types) |
| **Ready Work** | Yes | Yes (cached) |
| **Cycle Detection** | Yes | Yes |
| **Tombstones** | Yes | Yes (with TTL) |
| **Hash-Based IDs** | Yes | Yes |
| **Git Integration** | Hooks | Hooks + merge driver |
| **CLI Commands** | 9 commands | 50+ commands |
| **JSON Output** | Yes | Yes |
| **Formulas** | No | Yes (templates) |
| **Gates** | No | Yes (async coordination) |
| **MCP Server** | No | Yes (Claude integration) |
| **Daemon** | No | Yes (background process) |
| **Multi-Project** | No | Yes (convoys) |

**Summary:** Laravel Boost is 20% of the complexity, 80% of the value.

---

## Why This Design Wins

### For Solo Developers

✅ **Zero setup** - No database, no config
✅ **Instant start** - `composer install && boost:init`
✅ **Git-native** - Works offline, version controlled
✅ **Fast enough** - <20ms operations
✅ **Simple** - 1,000 lines of understandable code

### For AI Agents

✅ **JSON API** - Every command supports `--json`
✅ **Clear instructions** - Auto-generated AGENTS.md
✅ **Dependency tracking** - Know what's blocked
✅ **Ready work detection** - Know what to work on
✅ **Distributed** - No coordination needed

### For Small Teams

✅ **Merge-friendly** - Sorted JSONL, hash IDs
✅ **Distributed** - No central server
✅ **Conflicts are rare** - Hash IDs, tombstones
✅ **Portable** - Works anywhere (just Laravel)

### Simplicity Wins

This is the **80/20 solution**:

- **80% of the value** - Dependency-aware task tracking with ready work detection
- **20% of the complexity** - No database, no daemon, no RPC, no Formula system

Perfect for small projects where simplicity > scalability.

---

## Appendix: Implementation Checklist

### Phase 1: Core Service (~500 LOC)

- [ ] `TaskService.php` class structure
- [ ] JSONL read/write with atomic operations
- [ ] Hash-based ID generation (adaptive length)
- [ ] CRUD methods (create, read, update, delete)
- [ ] Ready work calculation (blocked detection)
- [ ] Cycle detection (BFS algorithm)
- [ ] Dependency management (add, remove, query)
- [ ] Tombstone soft-delete

### Phase 2: Artisan Commands (~50 LOC each)

- [ ] `InitCommand` - Initialize task system
- [ ] `CreateCommand` - Create tasks
- [ ] `ReadyCommand` - Show ready work
- [ ] `ListCommand` - List with filters
- [ ] `ShowCommand` - Show details
- [ ] `UpdateCommand` - Update tasks
- [ ] `CloseCommand` - Close tasks
- [ ] `DepAddCommand` - Add dependency
- [ ] `DepRemoveCommand` - Remove dependency

### Phase 3: Integration

- [ ] `BoostServiceProvider` - Register commands
- [ ] Git hooks (stubs)
- [ ] AGENTS.md template (stub)
- [ ] Package configuration

### Phase 4: Testing

- [ ] Unit tests for `TaskService`
- [ ] Command tests
- [ ] Integration tests

### Total Estimate

- **Lines of Code:** ~1,000 lines
- **Implementation Time:** 1-2 days for experienced Laravel dev
- **Testing:** 1 day
- **Documentation:** Already done!

---

## Questions & Answers

**Q: Why not just use a database?**
A: For <50 tasks, JSONL is simpler and faster to set up. No migrations, no schema, no DB driver dependencies.

**Q: What if I need >100 tasks?**
A: Migrate to SQLite backend (keep JSONL for git). Interface stays the same.

**Q: How do I handle merge conflicts?**
A: Sorted JSONL + hash IDs = rare conflicts. Future: 3-way merge driver.

**Q: Can multiple agents work concurrently?**
A: Yes, via git branches. No daemon needed. Hash IDs prevent collisions.

**Q: What about performance?**
A: <20ms for <50 tasks. Perfectly acceptable for CLI tool.

**Q: Why Laravel Collections instead of SQL?**
A: Collections are elegant, fast enough, and require no schema.

**Q: How do I query tasks?**
A: Use `search()` with filters, or write custom Collection pipelines.

**Q: What if JSONL gets corrupted?**
A: Restore from git! Every commit is a backup.

**Q: Can I use this in production?**
A: Yes, for small projects. Migrate to SQLite for larger projects.

**Q: How do I contribute?**
A: This is a design doc. Implement and open-source it!

---

**End of Design Document**

*Last Updated: 2025-01-03*
*Version: 1.0 MVP*
*Author: Claude (Anthropic)*
*License: MIT (suggested)*
