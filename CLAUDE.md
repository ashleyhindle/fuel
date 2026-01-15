'rg' is much faster and better than 'grep'. Prefer 'rg'.
Be extremely concise. Sacrifice grammar for the sake of concision.

## Project Overview

Fuel is a standalone CLI task management and execution system for AI agents, built with Laravel Zero. It provides task tracking via SQLite (`.fuel/agent.db`) for fast queries and reliable storage.

<fuel>
## Fuel Task Management

This project uses **Fuel** for lightweight task tracking. Tasks live in `.fuel/agent.db`.

### Quick Reference

```bash
fuel ready                      # Show tasks ready to work on
fuel add "Task title" [--blocked-by=f-id]           # Add a new task
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

```
[ ] Run tests                     # Quality gate (if you changed code)
[ ] Run linter/formatter          # Fix formatting (if you changed code)
[ ] git add <files>               # Stage your changes
[ ] git commit -m "feat/fix:..."  # Conventional commit - remember the hash
[ ] fuel done <id> --commit=<hash># Mark complete with commit hash
[ ] fuel add "..."                # File tasks for ANY remaining or discovered work
[ ] Hand off                      # Provide context for next session
```

### Workflow to work on one task

1. `fuel ready` - Find available work (prefer P0 > P1 > P2)
2. `fuel start <id>` - Claim task before starting
3. Do the work - implement, test, verify
4. Discover new work? `fuel add "..." --blocked-by=<id>`
5. `fuel done <id> --commit=<hash>` - Complete with commit hash
6. Land the plane

### Exiting Plan Mode & Epic planning
**Immediately after exiting plan mode**, or when required, convert your approved plan into well-defined Fuel tasks.

**Always use epics for any feature or change requiring multiple tasks.** Epics group related tasks and trigger a combined review when all tasks complete.

### Plan Files

Plans are stored in `.fuel/plans/{epic-title-kebab}-{epic-id}.md` and committed to git.

**When planning an epic:**
1. Create epic first: `fuel epic:add "Feature name"` to get the ID
2. Write plan to `.fuel/plans/{title-kebab}-{epic-id}.md`
3. Tasks read the plan for context
4. Tasks update the plan with discoveries (interfaces created, decisions made)
5. Plan becomes implementation record

**Plan structure (suggested):**
```markdown
# Epic: Feature Name (e-xxxxxx)

## Plan
Original design intent...

## Implementation Notes
<!-- Tasks append discoveries/decisions -->

## Interfaces Created
<!-- Tasks add these as they create them -->
```

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

When parallel tasks share an interface, define it in a parent task's description. Avoid parallel work on tasks touching same files - use dependencies instead.
**Always use epics for multi-task work.** Standalone tasks are fine for single-file fixes.

**On epic approval**, you may be asked to squash the epic's commits into cleaner logical commits using `git rebase -i`.

### Task Options

```bash
fuel add "Title" --description="..." --type=bug|fix|feature|task|epic|chore|docs|test|refactor --priority=0|1|2|3|4 --blocked-by=f-xxxx --labels=api,urgent --complexity=trivial|simple|moderate|complex --epic=e-xxxx
```
**Always set `--complexity`:** `trivial` (typos) | `simple` (single focus) | `moderate` (multiple files) | `complex` (multiple files, requires judgement or careful coordination)

### Writing Good Descriptions

Descriptions should be explicit enough for a less capable agent to complete without guessing. Include: files to modify (exact paths), what to change (methods, patterns), expected behavior, and patterns to follow. **Give one clear solution, not options—subagents execute, they don't decide.**

**Bad**: "Fix the ID display bug"
**Good**: "BoardCommand.php:320 uses substr($id, 5, 4) for old format. Change to substr($id, 2, 6) for f-xxxxxx format."


### Backlog Management

The backlog is for **rough ideas and future work** that isn't ready to implement yet. Tasks are for **work ready to implement now**.

**When to use backlog vs tasks:**

- **Backlog (`fuel add --someday`)**: Rough ideas, future enhancements, "nice to have" features, exploratory concepts, work that needs more thought before implementation
- **Tasks (`fuel add`)**: Work that's ready to implement now, has clear requirements, can be started immediately

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

### Testing Visual Changes with Browser
Use the fuel browser testing skill. If you don't have the skill, run fuel --help | grep -i browser.

fuel includes a browser daemon for testing webpages:

**Tips:**
- Screenshots are saved to `/tmp` by default, specify custom paths as needed
- Browser daemon auto-manages lifecycle, no manual cleanup needed</fuel>

## Development Commands

```bash
./vendor/bin/pest --parallel --compact                              # Run all tests (only on epic complete, takes a long time)
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

### Data Storage
- `.fuel/agent.db` - SQLite database (tasks, epics, reviews, runs, agent health)
- `.fuel/config.yaml` - Agent configuration
- `.fuel/plans/` - Epic plan files (committed to git)

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

## Dependency Injection

### Prefer `app()` Over Passing Dependencies

When you see code passing multiple dependencies through constructors or method parameters, consider using Laravel's service container instead.

**Instead of this (manual dependency passing):**
```php
// Verbose - every caller needs to know all dependencies
$service = new MyService(
    $taskService,
    $configService,
    $runService,
    $promptBuilder
);
```

**Do this (container resolution):**
```php
// Clean - container auto-injects registered dependencies
$service = app(MyService::class);

// Or with runtime parameters mixed in:
$task = app(WorkAgentTask::class, [
    'task' => $task,
    'reviewEnabled' => true,
    'agentOverride' => 'sonnet',
]);
```

### Key Files

- **`app/Providers/AppServiceProvider.php`** - Register singletons and bindings here
- **`app/Agents/Tasks/WorkAgentTask.php`** - Example of class designed for `app()` with runtime params

### When to Use Each Pattern

| Scenario | Approach |
|----------|----------|
| Singletons (TaskService, ConfigService) | Register in AppServiceProvider, inject via constructor |
| Transient objects with DI + runtime params | Use `app(Class::class, ['param' => $value])` |
| Deep code needing occasional service | `app(Service::class)` is acceptable |
| Domain/business logic | Prefer explicit constructor injection |

### Acceptable Refactoring

If while working on your task you encounter verbose dependency passing that could be simplified with `app()`, it is acceptable to make this refactor as part of your work - provided it doesn't expand scope significantly. Log larger refactoring opportunities with `fuel add "..." --someday`.
