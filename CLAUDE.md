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
fuel ready                      # Show tasks ready to work on
fuel add "Task title"           # Add a new task
fuel start <id>                 # Claim a task (in_progress)
fuel done <id>                  # Mark task complete
fuel show <id>                  # View task details
fuel board --once               # Kanban view
fuel tree                       # Tree view
fuel dep:add <id> <blocker>     # Add dependency
fuel dep:remove <id> <blocker>  # Remove dependency
```

### TodoWrite vs Fuel

Use **TodoWrite** for single-session step tracking. Use **fuel** for work that outlives the session (multi-session, dependencies, discovered work for future). When unsure, prefer fuel. It is better to over-persist than lose context.

### 🚨 MANDATORY: Session Close Protocol - Land The Plane

**YOU MUST COMPLETE EVERY STEP BELOW BEFORE EXITING. NO EXCEPTIONS.**

```
[ ] Run tests                     # Quality gate (if you changed code)
[ ] Run linter/formatter          # Fix formatting (if you changed code)
[ ] git add <files>               # Stage your changes
[ ] git commit -m "feat/fix:..."  # Commit - note the hash from output [main abc1234]
[ ] fuel done <id> --commit=<hash>  # Mark complete with commit hash from above
[ ] fuel add "..."              # File tasks for ANY incomplete/discovered work
```

**Failure to complete these steps means your work is NOT done.**

Commit messages should follow conventional commits: `feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`

### Workflow

1. `fuel ready` - Find available work if not provided a particular task (prefer P0 > P1 > P2)
2. `fuel start <id>` - Claim task before starting
3. Do the work - implement, test, verify
4. Discover new work as you build? Add it `fuel add "..." --blocked-by=<id>`
5. `fuel done <id> --commit=<hash>` - Complete the task (hash from your git commit output)
6. Land the plane

### Task Options

```bash
fuel add "Title" \
  --description="Details here" \
  --type=bug|feature|task|chore \
  --priority=0-4 \
  --blocked-by=fuel-xxxx,fuel-yyyy \
  --labels=api,urgent \
  --complexity=trivial|simple|moderate|complex
```

### Writing Good Descriptions

Descriptions should be explicit enough for a less capable agent to complete the task without guessing. Include:

- **Files to modify**: Exact paths (`app/Commands/FooCommand.php`)
- **What to change**: Specific methods, line numbers, or patterns
- **Expected behavior**: What success looks like
- **Patterns to follow**: Reference existing similar code (`see BarCommand.php`)

**Bad**: "Fix the ID display bug"
**Good**: "BoardCommand.php line 320 uses substr($id, 5, 4) for old fuel-xxxx format. Change to substr($id, 2, 6) for new f-xxxxxx format. Check RendersBoardColumns trait for same pattern."

### Complexity

**Always set `--complexity` when adding tasks:** `trivial` (typos, string changes) | `simple` (clear requirements, single focus) | `moderate` (multiple steps/files) | `complex` (architectural, break into subtasks)

### Dependencies

```bash
fuel add "Design API"
fuel add "Implement API" --blocked-by=fuel-xxxx
```

Blocked tasks won't appear in `fuel ready` until blockers are closed.

### Needs-Human Workflow

When blocked on credentials, decisions, verification, or manual steps:

1. Create needs-human task with clear description of what's needed:
   ```bash
   fuel add 'Provide Cloudflare API token' \
     --labels=needs-human \
     --description='Run npx wrangler login or set CLOUDFLARE_API_TOKEN'
   ```
2. Block current work: `fuel dep:add <current-task-id> <needs-human-task-id>`
3. Human completes and runs `fuel done <needs-human-task-id>`
4. Your blocked task reappears in `fuel ready`

### Contracts for Parallel Work

When parallel tasks share an interface, define it in a parent task's `--description`. Dependent tasks reference the parent to see the contract.

### Parallel Execution

Primary agent coordinates - subagents do NOT pick tasks:

1. Primary runs `fuel ready --json`, identifies parallel work
2. Primary claims each task with `fuel start <id>`
3. Primary spawns subagents with explicit task ID assignments
4. Subagents complete work and run `fuel done <id>`
5. **Primary reviews subagent work** - verify tests added, check implementation, run tests
6. If issues found: create fix task referencing the original (e.g., `fuel add "Fix X from fuel-xxxx"`)
7. Primary checks `fuel ready` for newly unblocked work

**Subagent instructions must include:** task ID, task information, instruction to run `fuel done <id>` after landing the plane.

**Review checklist for primary:**
- Did subagent add tests?
- Do all tests pass?
- Does the implementation match the task requirements?
- Any obvious bugs or issues in the code?

Avoid parallel work on tasks touching same files - use dependencies instead.
</fuel>

## Development Commands

```bash
./vendor/bin/pest                              # Run tests
./vendor/bin/pest tests/Unit/TaskServiceTest.php  # Single file
./vendor/bin/pest --filter="creates a task"    # Pattern match
./vendor/bin/pint                              # Code formatting
./fuel make:command CommandName                # Create command
```

## Architecture

**Laravel Zero CLI** - Micro-framework for console apps built on Laravel components.

### Directory Structure
- `app/Commands/` - CLI commands
- `app/Services/` - Core services (TaskService, RunService, ConfigService)
- `app/Enums/` - Enums (Agent)
- `tests/` - Pest tests (Feature and Unit)
- `fuel` - CLI entry point

### Key Services
- **TaskService** - JSONL task storage with atomic writes, file locking, partial ID matching
- **RunService** - Agent run history per task (`.fuel/runs/`)
- **ConfigService** - Agent routing by complexity (`.fuel/config.yaml`)

### Data Storage
- `.fuel/tasks.jsonl` - Task data (one JSON object per line, sorted by ID)
- `.fuel/runs/<task-id>.jsonl` - Run history per task
- `.fuel/config.yaml` - Agent configuration

## Testing Patterns

For command tests checking JSON output, use `Artisan::call()` + `Artisan::output()`:

```php
// CORRECT - captures output reliably
Artisan::call('command', ['--json' => true]);
$output = Artisan::output();
expect($output)->toContain('expected');

// INCORRECT - expectsOutputToContain() unreliable with JSON
$this->artisan('command', ['--json' => true])
    ->expectsOutputToContain('expected');  // May fail unexpectedly
```
