# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Fuel is a standalone CLI task management and execution system for AI agents, built with Laravel Zero. It provides task tracking via SQLite (`.fuel/agent.db`) for fast queries and reliable storage.

The original design spec is archived in `FUEL-PLAN-ORIGINAL.md` (outdated - kept for historical reference). Reference implementation exists in `agent-resources/old-implementation-within-boost/`. Laravel Zero docs are in `agent-resources/laravel-zero-docs/`.

<fuel>
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
fuel board --once               # Kanban view
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

```
[ ] Run tests                     # Quality gate (if you changed code)
[ ] Run linter/formatter          # Fix formatting (if you changed code)
[ ] git add <files>               # Stage your changes
[ ] git commit -m "feat/fix:..."  # Conventional commit - note the hash from output [main abc1234]
[ ] fuel done <id> --commit=<hash>  # Mark complete with commit hash from above
[ ] fuel add "..."              # File tasks for ANY remaining or discovered work

[ ] Hand off - Provide context for next session
```


Commit messages: `feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`

### Workflow to work on one task

1. `fuel ready` - Find available work (prefer P0 > P1 > P2)
2. `fuel start <id>` - Claim task before starting
3. Do the work - implement, test, verify
4. Discover new work? `fuel add "..." --blocked-by=<id>`
5. `fuel done <id> --commit=<hash>` - Complete with commit hash
6. Land the plane

### Task Options

```bash
fuel add "Title" --description="..." --type=bug|fix|feature|task|epic|chore|docs|test|refactor --priority=0|1|2|3|4 --blocked-by=f-xxxx --labels=api,urgent --complexity=trivial|simple|moderate|complex
```

### Writing Good Descriptions

Descriptions should be explicit enough for a less capable agent to complete without guessing. Include: files to modify (exact paths), what to change (methods, patterns), expected behavior, and patterns to follow. **Give one clear solution, not options—subagents execute, they don't decide.**

**Bad**: "Fix the ID display bug"
**Good**: "BoardCommand.php:320 uses substr($id, 5, 4) for old format. Change to substr($id, 2, 6) for f-xxxxxx format."

### Complexity

**Always set `--complexity`:** `trivial` (typos) | `simple` (single focus) | `moderate` (multiple files) | `complex` (break into subtasks)

### Dependencies

```bash
fuel add "Implement API" --blocked-by=f-xxxx
```

Blocked tasks won't appear in `fuel ready` until blockers are closed.

### Epics

**Use epics for any feature or change requiring multiple tasks.** Epics group related tasks and trigger a combined review when all tasks complete.

**When to create an epic:**
- Feature with 2+ tasks (e.g., "Add user preferences" → API + UI + tests)
- Refactoring spanning multiple files/concerns
- Any work you'd want reviewed as a coherent whole

**Workflow:**
1. `fuel epic:add "Feature name" --description="What and why"`
2. Break down into tasks, linking each: `fuel add "Task" --epic=e-xxxx`
3. Create a final review task: `fuel add "Review: Feature name" --epic=e-xxxx --blocked-by=f-task1,f-task2,...`
4. Work tasks individually; review task auto-unblocks when all dependencies close
5. Complete review task to close out the epic

```bash
fuel epic:add "Add user preferences"    # Create epic (note the ID)
fuel add "Add preferences API" --epic=e-xxxx -e e-xxxx  # Link task
fuel add "Add preferences UI" --epic=e-xxxx             # Link another
fuel epics                               # List all epics with status
fuel epic:show <e-id>                   # View epic + linked tasks
fuel epic:reviewed <e-id>               # Mark as human-reviewed
```

**Always use epics for multi-task work.** Standalone tasks are fine for single-file fixes.

### Backlog Management

The backlog (`.fuel/backlog.jsonl`) is for **rough ideas and future work** that isn't ready to implement yet. Tasks are for **work ready to implement now**.

**When to use backlog vs tasks:**

- **Backlog (`fuel add --someday`)**: Rough ideas, future enhancements, "nice to have" features, exploratory concepts, work that needs more thought before implementation
- **Tasks (`fuel add`)**: Work that's ready to implement now, has clear requirements, can be started immediately

**Backlog commands:**

```bash
fuel add "Future idea" --someday          # Add to backlog (ignores other options)
fuel backlog                              # List all backlog items
fuel promote <b-id>                      # Promote backlog item to task (adds --priority, --type, etc.)
fuel defer <f-id>                         # Move a task to backlog
fuel remove <b-id>                        # Delete a backlog item
```

**Promoting backlog to tasks:**

When a backlog item is ready to work on:
1. Review the backlog: `fuel backlog`
2. Promote with task metadata: `fuel promote <b-id> --priority=2 --type=feature --complexity=moderate`
3. The backlog item is removed and a new task is created with the same title/description

**Deferring tasks:**

If a task isn't ready to work on (needs more planning, blocked externally, wrong priority):
1. `fuel defer <f-id>` - Moves task to backlog, preserving title and description
2. Later, promote it back when ready: `fuel promote <b-id> --priority=...`

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

When parallel tasks share an interface, define it in a parent task's description. Avoid parallel work on tasks touching same files - use dependencies instead.</fuel>

## Development Commands

```bash
./vendor/bin/pest                              # Run all tests (only on epic complete, takes a long time)
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
- **TaskService** - SQLite task storage with partial ID matching
- **RunService** - Agent run history (SQLite)
- **ConfigService** - Agent routing by complexity (`.fuel/config.yaml`)

### Data Storage
- `.fuel/agent.db` - SQLite database (tasks, epics, reviews, runs, agent health)
- `.fuel/config.yaml` - Agent configuration
- `.fuel/backlog.jsonl` - Backlog items (rough ideas, future work)

## Testing Patterns

**Tests must NEVER modify the real workspace.** Always use isolated temp directories:

```php
// CORRECT - use isolated temp directory
$this->testDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
mkdir($this->testDir.'/.fuel', 0755, true);

// INCORRECT - will delete real files when agents run tests!
$processDir = getcwd().'/.fuel/processes';
File::deleteDirectory($processDir);  // NEVER DO THIS
```

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



# Pest Tests

## Pest
- If you need to verify a feature is working, write or update a Unit / Feature test.

### Pest Tests
- All tests must be written using Pest
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files - these are core to the application.
- Tests should test all of the happy paths, failure paths, and weird paths.
- Tests live in the `tests/Feature` and `tests/Unit` directories.
- Pest tests look and behave like this:
<code-snippet name="Basic Pest Test Example" lang="php">
test('inspiring command', function () {
    $this->artisan('inspiring')
         ->expectsOutput('Simplicity is the ultimate sophistication.')
         ->assertExitCode(0);
});

it('is true', function () {
    expect(true)->toBeTrue();
});
</code-snippet>

### Running Tests
- Run the minimal number of tests using an appropriate filter before finalizing code edits.
- To run all tests: vendor/bin/pest --compact (only on epic complete, takes a long time)
- To run all tests in a file: vendor/bin/pest --compact tests/Feature/ExampleTest.php
- To filter on a particular test name: vendor/bin/pest --compact --filter=testName (recommended after making a change to a related file).

### Mocking
- Mocking can be very helpful when appropriate.

### Datasets
- Use datasets in Pest to simplify tests that have a lot of duplicated data. This is often the case when testing validation rules, so consider this solution when writing tests for validation rules.

<code-snippet name="Pest Dataset Example" lang="php">
it('has emails', function (string $email) {
    expect($email)->not->toBeEmpty();
})->with([
    'james' => 'james@laravel.com',
    'taylor' => 'taylor@laravel.com',
]);
</code-snippet>
