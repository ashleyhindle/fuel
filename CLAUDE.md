# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Fuel is a standalone CLI task management and execution system for AI agents, built with Laravel Zero. It provides task tracking via JSONL files that are git-native and merge-friendly.

The design spec is in `FUEL-PLAN.md`. Reference implementation exists in `agent-resources/old-implementation-within-boost/`. Laravel Zero docs are in `agent-resources/laravel-zero-docs/`.

## Fuel Task Management

Tasks are stored in `.fuel/tasks.jsonl` in the current working directory.

### Quick Reference

```bash
./fuel add "Task title"           # Add a new task
./fuel ready                      # Show all open tasks
./fuel done <id>                  # Mark task as complete (supports partial ID)
```

### Starting a Session

Always begin by checking for available work:

```bash
./fuel ready --json
```

Pick a task and work on it. When done, mark it complete:

```bash
./fuel done fuel-a7f3            # Full ID
./fuel done a7f3                 # Partial ID works
./fuel done a7                   # Even shorter
```

### JSON Output

All commands support `--json` for programmatic use:

```bash
./fuel add "Task" --json         # Returns task object
./fuel ready --json              # Returns array of open tasks
./fuel done <id> --json          # Returns completed task
```

### Working Directory

All commands support `--cwd` to specify where `.fuel/tasks.jsonl` lives:

```bash
./fuel add "Task" --cwd /path/to/project
./fuel ready --cwd /path/to/project
```

### Session Completion

Before ending a work session:
1. Mark completed tasks done: `./fuel done <id>`
2. Add tasks for remaining work: `./fuel add "Follow-up work"`
3. Verify task state: `./fuel ready`

### Parallel Execution

The **primary agent** coordinates parallel work - subagents do NOT pick tasks themselves:

1. **Primary agent reviews** - Run `./fuel ready --json` and identify parallelizable tasks
2. **Primary agent assigns** - Spawn subagents with explicit task assignments:
   - Each subagent receives ONE specific task ID
   - Subagents work ONLY on their assigned task
3. **Subagents execute** - Complete the task and run `./fuel done <id>`
4. **Primary agent continues** - Check `./fuel ready` for newly unblocked tasks

**When spawning a subagent, include:**
- The specific task ID and title
- Instruction to read CLAUDE.md for project context
- Instruction to run `./fuel done <id>` upon completion

**Avoid parallel work on tasks that touch the same files** - use dependencies to enforce ordering.

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
- `app/Commands/` - CLI commands (AddCommand, ReadyCommand, DoneCommand)
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

Task fields: `id`, `title`, `status` (open/closed), `dependencies`, `created_at`, `updated_at`.

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
  "status": "open",
  "dependencies": [
    {"depends_on": "fuel-xxxx", "type": "blocks"}
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
| `done` | Returns completed task object |
| `dep:add` | Returns updated task object (with new dependency) |
| `dep:remove` | Returns updated task object (dependency removed) |

Error responses: `{"error": "Error message here"}`

### TaskService Method Signatures

```php
// CRUD
create(array $data): array              // Returns task
find(string $id): ?array                // Partial ID matching
markDone(string $id): array             // Returns updated task
ready(): Collection                     // Open tasks with no open blockers

// Dependencies
addDependency(string $taskId, string $dependsOnId, string $type = 'blocks'): array
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


## Landing the Plane (Session Completion)

**When ending a work session**, you MUST complete ALL steps below. Work is NOT complete until hand off succeeds.

**MANDATORY WORKFLOW:**

1. **File issues for remaining work** - Create issues for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **Clean up** - Clear stashes, prune remote branches
5. **Verify** - All changes added and committed 
6. **Hand off** - Provide context for next session

**CRITICAL RULES:**
- Work is NOT complete until fuel is updated and hand off succeeds
