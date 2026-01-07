# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Fuel is a standalone CLI task management and execution system for AI agents, built with Laravel Zero. It provides task tracking via JSONL files that are git-native and merge-friendly.

The design spec is in `FUEL-PLAN.md`. Reference implementation exists in `agent-resources/old-implementation-within-boost/`. Laravel Zero docs are in `agent-resources/laravel-zero-docs/`.

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

### 🚨 Session Close Protocol

Before ending ANY session, complete this checklist:

```
[ ] ./fuel done <id>              # Close all completed tasks
[ ] ./fuel add "..."              # File tasks for incomplete work
[ ] ./fuel ready                  # Verify task state
[ ] Run tests/linters             # Quality gates (if code changed)
[ ] git commit                    # Commit changes
```

**Work is NOT complete until tasks reflect reality.**

### Workflow

1. `./fuel ready` - Find available work if not provided a particular task (prefer P0 > P1 > P2)
2. `./fuel start <id>` - Claim task before starting
3. Do the work - implement, test, verify
4. Discover new work as you build? Add it `./fuel add "..." --blocked-by=<id>`
5. `./fuel done <id>` - Complete the task

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

Task fields: `id`, `title`, `status` (open/in_progress/closed), `blocked_by` (array of task IDs), `created_at`, `updated_at`.

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
  "blocked_by": [
    "fuel-xxxx"
  ],
  "created_at": "2026-01-07T10:00:00+00:00",
  "updated_at": "2026-01-07T10:00:00+00:00"
}
```

### Command JSON Output

| Command | `--json` Output |
|---------|-----------------|
| `add` | Returns created task object |
| `ready` | Returns array of task objects |
| `start` | Returns task object with in_progress status |
| `done` | Returns completed task object (single ID) or array of task objects (multiple IDs) |
| `dep:add` | Returns updated task object (with new dependency) |
| `dep:remove` | Returns updated task object (dependency removed) |

Error responses: `{"error": "Error message here"}`

### TaskService Method Signatures

```php
// CRUD
create(array $data): array              // Returns task
find(string $id): ?array                 // Partial ID matching
start(string $id): array                 // Returns task with in_progress status
done(string $id, ?string $reason): array // Returns updated task
ready(): Collection                      // Open tasks with no open blockers (excludes in_progress)

// Dependencies
addDependency(string $taskId, string $dependsOnId): array
removeDependency(string $taskId, string $dependsOnId): array
getBlockers(string $taskId): Collection
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
