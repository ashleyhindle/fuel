# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Fuel is a standalone CLI task management and execution system for AI agents, built with Laravel Zero. It provides task tracking via JSONL files that are git-native and merge-friendly.

The original design spec is archived in `FUEL-PLAN-ORIGINAL.md` (outdated - kept for historical reference). Reference implementation exists in `agent-resources/old-implementation-within-boost/`. Laravel Zero docs are in `agent-resources/laravel-zero-docs/`.

<fuel>
## Fuel Task Management

This project uses **Fuel** for lightweight task tracking. Tasks live in `.fuel/tasks.jsonl`.

### Quick Reference

```bash
./fuel ready                      # Show tasks ready to work on
./fuel add "Task title"           # Add a new task
./fuel start <id>                 # Claim a task (in_progress)
./fuel done <id>                  # Mark task complete
./fuel show <id>                  # View task details
./fuel board                      # Kanban view
```

### 🚨 MANDATORY: Session Close Protocol - Land The Plane

**YOU MUST COMPLETE EVERY STEP BELOW BEFORE EXITING. NO EXCEPTIONS.**

This is not optional. Skipping steps breaks the workflow for humans and other agents.

```
[ ] ./fuel done <id>              # Mark your assigned task complete
[ ] ./fuel add "..."              # File tasks for ANY incomplete/discovered work
[ ] Run tests                     # Quality gate (if you changed code)
[ ] Run linter/formatter          # Fix formatting (if you changed code)
[ ] git add <files>               # Stage your changes
[ ] git commit -m "feat/fix:..."  # Commit with conventional commit message
[ ] ./fuel ready                  # Verify task state is correct
```

**Failure to complete these steps means your work is NOT done.**

Commit messages should follow conventional commits: `feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`

### Workflow

1. `./fuel ready` - Find available work if not provided a particular task (prefer P0 > P1 > P2)
2. `./fuel start <id>` - Claim task before starting
3. Do the work - implement, test, verify
4. Discover new work as you build? Add it `./fuel add "..." --blocked-by=<id>`
5. `./fuel done <id>` - Complete the task
6. Land the plane

### Task Options

```bash
./fuel add "Title" \
  --description="Details here" \
  --type=bug|feature|task|chore \
  --priority=0-4 \
  --blocked-by=fuel-xxxx,fuel-yyyy \
  --labels=api,urgent
```

### Dependencies

```bash
./fuel add "Design API"
./fuel add "Implement API" --blocked-by=fuel-xxxx
```

Blocked tasks won't appear in `./fuel ready` until blockers are closed.

### Needs-Human Workflow

When an agent needs human input (credentials, decisions, access), follow this workflow:

**WHEN to create needs-human tasks:**
- Before deploying: 'Test X deployment after deploy'
- When needing credentials/tokens: 'Provide API token for X'
- When human verification required: 'Verify emails sending correctly'
- When manual steps needed: 'Create DNS records'
- When decisions needed: 'Choose between approach A vs B'
- After completing work that can't be automatically tested

**HOW to write them:**
- Clear title describing the action needed
- Description with exact steps: WHAT to test/do and HOW to do it
- Example: `--description='Visit addfuel.dev, verify index.html loads, run: curl -L addfuel.dev/install | sh'`

**Workflow:**
1. **Create a needs-human task** describing exactly what's needed:
   ```bash
   ./fuel add 'Provide Cloudflare API token' \
     --labels=needs-human \
     --description='Run npx wrangler login or set CLOUDFLARE_API_TOKEN'
   ```

2. **Block the current task** on the needs-human task:
   ```bash
   ./fuel dep:add <current-task-id> <needs-human-task-id>
   ```

3. **Mark current task as open** (if it was in_progress):
   ```bash
   # The task will automatically become open when blocked
   ```

4. **Human completes the needs-human task** by providing the required input and marking it done:
   ```bash
   ./fuel done <needs-human-task-id>
   ```

5. **Agent work can resume** - the blocked task will appear in `./fuel ready` once the needs-human task is completed.

**Example:**
```bash
# Agent needs API credentials
./fuel add 'Provide Cloudflare API token' \
  --labels=needs-human \
  --description='Run npx wrangler login or set CLOUDFLARE_API_TOKEN'

# Block current work on it
./fuel dep:add fuel-xxxx fuel-yyyy

# Human provides token, marks task done
./fuel done fuel-yyyy

# Agent can now continue with fuel-xxxx
```

### Parallel Execution

Primary agent coordinates - subagents do NOT pick tasks:

1. Primary runs `./fuel ready --json`, identifies parallel work
2. Primary claims each task with `./fuel start <id>`
3. Primary spawns subagents with explicit task ID assignments
4. Subagents complete work and run `./fuel done <id>`
5. Primary checks `./fuel ready` for newly unblocked work

**Subagent instructions must include:** task ID, task information, instruction to run `./fuel done <id>` after landing the plane.

Avoid parallel work on tasks touching same files - use dependencies instead.
</fuel>

## Development Commands

```bash
# Run tests
./vendor/bin/pest

# Run a single test file
./vendor/bin/pest tests/Unit/TaskServiceTest.php

# Run tests matching a pattern
./vendor/bin/pest --filter="creates a task"

# Code formatting
./vendor/bin/pint

# Create a new command
./fuel make:command CommandName
```

## Architecture

**Laravel Zero CLI application** - A micro-framework for console apps built on Laravel components.

### Directory Structure
- `app/Commands/` - CLI commands (AddCommand, ReadyCommand, StartCommand, DoneCommand)
- `app/Services/` - Core services (TaskService)
- `app/Providers/` - Service providers
- `tests/` - Pest tests (Feature and Unit suites)
- `fuel` - CLI entry point (executable)

### TaskService (`app/Services/TaskService.php`)
Core service handling JSONL storage with:
- Atomic writes (temp file + rename)
- File locking (flock with retry)
- Hash-based IDs (`fuel-{4 chars}`)
- Sorted output (merge-friendly)
- Partial ID matching

### Data Storage
Single file: `.fuel/tasks.jsonl` - one JSON object per line, sorted by ID.

Task fields: `id`, `title`, `status` (open/in_progress/closed), `description`, `type`, `priority`, `labels`, `size`, `blocked_by` (array of task IDs), `created_at`, `updated_at`, and optionally `reason`, `consumed`, `consumed_at`, `consumed_exit_code`, `consumed_output`.

## Interface Contracts

**IMPORTANT:** When implementing features in parallel (multiple subagents), contracts ensure consistency.

### Contract-First Pattern with `--description`

When parallel tasks share an interface, **define the contract in a parent task's description**:

1. **Create a contract task** with `--description` specifying the exact interface:
   ```bash
   ./fuel add "Add UserService" --description="Schema: {id, name, email, role (admin|user), created_at}. Methods: create(array): array, find(string): ?array, update(string, array): array"
   ```

2. **Create dependent tasks** that reference the contract:
   ```bash
   ./fuel add "Add create user endpoint - use contract from fuel-xxxx" --blocked-by=fuel-xxxx
   ./fuel add "Add update user endpoint - use contract from fuel-xxxx" --blocked-by=fuel-xxxx
   ```

3. **Subagents read the parent task** to see the interface they must implement

**Why this works:** The contract lives IN the task description. When an agent picks up a dependent task, they read the parent task's description to see the exact interface. No external docs needed - the task system IS the documentation.

For project-wide contracts (task schema, command output formats), document them in CLAUDE.md below.

### Task Object Schema

All commands that return task data use this structure:

```json
{
  "id": "fuel-a7f3",
  "title": "Task title",
  "status": "open",  // Can be: "open", "in_progress", or "closed"
  "description": "Long description (optional)",
  "type": "task",  // Can be: "bug", "feature", "task", "epic", "chore"
  "priority": 2,  // Integer 0-4 (0=critical, 4=backlog)
  "labels": ["api", "urgent"],  // Array of label strings
  "size": "m",  // Can be: "xs", "s", "m", "l", "xl"
  "blocked_by": [
    "fuel-xxxx"
  ],
  "created_at": "2026-01-07T10:00:00+00:00",
  "updated_at": "2026-01-07T10:00:00+00:00",
  "reason": "Completion reason (optional, set when done)",
  "consumed": true,  // Optional: true if task was consumed by agent
  "consumed_at": "2026-01-07T10:00:00+00:00",  // Optional: when consumed
  "consumed_exit_code": 0,  // Optional: exit code from agent execution
  "consumed_output": "Agent output..."  // Optional: agent output (truncated to 10KB)
}
```

**Required fields:** `id`, `title`, `status`, `created_at`, `updated_at`  
**Default fields:** `description` (null), `type` ("task"), `priority` (2), `labels` ([]), `size` ("m"), `blocked_by` ([])  
**Optional fields:** `reason`, `consumed`, `consumed_at`, `consumed_exit_code`, `consumed_output`

### Command List

**Core Commands:**
- `add` - Create a new task
- `ready` - Show tasks ready to work on (open, unblocked, not needs-human)
- `start <id>` - Claim a task (set status to in_progress)
- `done <id> [id...]` - Mark one or more tasks as complete
- `show <id>` - View full task details
- `update <id>` - Update task fields (--title, --description, --type, --priority, --status, --size, --add-labels, --remove-labels)
- `reopen <id> [id...]` - Reopen closed or in_progress tasks (set status to open)

**Dependency Commands:**
- `dep:add <task-id> <blocker-id>` - Add dependency (task blocked by blocker)
- `dep:remove <task-id> <blocker-id>` - Remove dependency

**Query Commands:**
- `list` - List tasks with filters (--status, --type, --priority, --labels, --size)
- `blocked` - Show open tasks with unresolved dependencies
- `completed` - Show recently completed tasks
- `human` - Show tasks with 'needs-human' label
- `status` - Show task statistics overview (counts by status)
- `available` - Echo count of ready tasks, exit 0 if any, 1 if none

**Display Commands:**
- `board` - Kanban board view (open/in_progress/closed columns)
- `q "title"` - Quick capture (create task, output only ID)

**Workflow Commands:**
- `consume` - Auto-spawn agents to work through available tasks (shows board, loops while available)
- `init` - Initialize fuel in project (creates .fuel/, adds guidelines to AGENTS.md)

**Utility Commands:**
- `guidelines` - Output fuel task management guidelines (use --add to inject into AGENTS.md)
- `migrate` - Migrate tasks from old dependency schema to new schema
- `inspire` - Laravel Zero built-in command

### Command JSON Output

| Command | `--json` Output |
|---------|-----------------|
| `add` | Returns created task object |
| `ready` | Returns array of task objects |
| `start` | Returns task object with in_progress status |
| `done` | Returns completed task object (single ID) or array of task objects (multiple IDs) |
| `reopen` | Returns reopened task object (single ID) or array of task objects (multiple IDs) |
| `show` | Returns task object |
| `update` | Returns updated task object |
| `list` | Returns array of task objects |
| `blocked` | Returns array of blocked task objects |
| `completed` | Returns array of completed task objects |
| `human` | Returns array of needs-human task objects |
| `status` | Returns object with status counts |
| `dep:add` | Returns updated task object (with new dependency) |
| `dep:remove` | Returns updated task object (dependency removed) |
| `q` | Outputs only task ID (no JSON, for scripting) |
| `available` | Outputs count only (no JSON, for scripting) |

Error responses: `{"error": "Error message here"}`

### TaskService Method Signatures

```php
// CRUD
all(): Collection                        // Load all tasks (with shared lock)
create(array $data): array              // Returns task
find(string $id): ?array                 // Partial ID matching
update(string $id, array $data): array // Returns updated task
start(string $id): array                 // Returns task with in_progress status
done(string $id, ?string $reason): array // Returns updated task
reopen(string $id): array               // Returns task with open status

// Queries
ready(): Collection                     // Open tasks with no open blockers (excludes in_progress, excludes needs-human)
blocked(): Collection                   // Open tasks with unresolved dependencies

// Dependencies
addDependency(string $taskId, string $dependsOnId): array
removeDependency(string $fromId, string $toId): array
getBlockers(string $taskId): Collection  // Returns open blockers for a task

// Utilities
generateId(int $taskCount = 0): string  // Generate hash-based ID
initialize(): void                       // Create storage directory and file
getStoragePath(): string                 // Get storage path
setStoragePath(string $path): self      // Set custom storage path
migrateDependencies(): array            // Migrate old dependency schema to blocked_by
```

### Testing Patterns

For command tests that check JSON output, use `Artisan::call()` + `Artisan::output()`:

```php
// CORRECT - captures output reliably
Artisan::call('command', ['--json' => true]);
$output = Artisan::output();
expect($output)->toContain('expected');

// INCORRECT - expectsOutputToContain() unreliable with JSON
$this->artisan('command', ['--json' => true])
    ->expectsOutputToContain('expected');  // May fail unexpectedly
```
