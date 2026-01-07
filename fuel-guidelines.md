## Fuel Task Management
This project uses **Fuel** for lightweight task tracking. Tasks are stored in `.ai/fuel.jsonl`.

### Quick Reference

```bash
{{ $assist->artisanCommand('fuel:ready') }}                  # Show tasks ready to work on
{{ $assist->artisanCommand('fuel:add "Task title"') }}       # Add a new task
{{ $assist->artisanCommand('fuel:list') }}                   # List all tasks
{{ $assist->artisanCommand('fuel:done <id>') }}              # Mark task as complete
```

### Starting a Session

Always begin by checking for available work:

```bash
{{ $assist->artisanCommand('fuel:ready --json') }}
```

If tasks are available, select one and begin working. Prefer higher priority tasks (P0 > P1 > P2 etc.) unless you have a specific reason to choose otherwise.

### Working on Tasks

1. **Pick a task** from `fuel:ready` output
2. **Work on the task** - implement, test, verify
3. **Discover new work?** Add tasks with `fuel:add` and link dependencies with `--blocked-by`
4. **Complete the task** with `fuel:done <id> --reason="Brief description"`

When you discover work that should be done but isn't the current focus, **always create a task for it**. Don't rely on memory - if it's not tracked, it will be forgotten.

### Session Completion

Before ending a work session, you MUST complete these steps:

1. **Close completed tasks** - Every task you finished must be marked done:
   ```bash
   {{ $assist->artisanCommand('fuel:done <id> --reason="What was accomplished"') }}
   ```

2. **File tasks for incomplete work** - Any unfinished work or follow-ups need tasks:
   ```bash
   {{ $assist->artisanCommand('fuel:add "Remaining work description" --priority=2') }}
   ```

3. **Verify task state** - Check nothing is left dangling:
   ```bash
   {{ $assist->artisanCommand('fuel:list --status=open') }}
   ```

**Important**: Work is not complete until tasks reflect reality. A finished task with no `fuel:done` appears as unfinished work. An untracked follow-up will be lost.

### Parallel Execution

Tasks from `fuel:ready` have no blockers and can be worked on simultaneously. The **primary agent** coordinates parallel work:

1. **Primary agent reviews tasks** - Run `fuel:ready --json` and identify tasks suitable for parallel work
2. **Primary agent assigns tasks** - Spawn subagents with explicit task assignments:
   - Tell each subagent their specific task ID and title
   - Each subagent works ONLY on their assigned task
   - Subagents must NOT pick tasks themselves
3. **Subagents execute and close** - Each subagent completes their task and runs `fuel:done <id>`
4. **Primary agent checks for unblocked work** - After subagents complete, run `fuel:ready` to find newly unblocked tasks

**Subagent instructions should include:**
- The specific task ID and title
- Path to the fuel binary (e.g., `./fuel` or absolute path)
- Instruction to read CLAUDE.md for project context
- Instruction to run `fuel:done <id>` upon completion

This pattern works well for independent tasks like:
- Creating separate model/migration pairs
- Building unrelated UI components
- Writing tests for different features

**Note**: Avoid parallel work on tasks that touch the same files - let dependencies enforce ordering instead.

### Contract-First Pattern for Shared Interfaces

When parallel tasks share an interface (method signatures, JSON output, schema), use **`--description` to define the contract**:

1. **Create a contract task** with `--description` specifying the exact interface:
   ```bash
   fuel:add "Add UserService" --description="Schema: {id, name, email, role (admin|user), created_at}. Methods: create(array): array, find(string): ?array, update(string, array): array"
   ```

2. **Create dependent tasks** that reference the contract task:
   ```bash
   fuel:add "Add create user endpoint - use contract from fuel-xxxx" --blocked-by=fuel-xxxx
   fuel:add "Add update user endpoint - use contract from fuel-xxxx" --blocked-by=fuel-xxxx
   fuel:add "Add list users endpoint - use contract from fuel-xxxx" --blocked-by=fuel-xxxx
   ```

3. **Subagents read the parent task** to see the interface they must implement

**Why this works:** The contract lives IN the task's `--description`. When an agent picks up a dependent task, they read the parent task to see the exact interface. No separate documentation needed - the task system IS the documentation.

**Tip:** Keep titles short, put details in `--description`. Agents can view full task details with `fuel:show <id>`.

### Common Mistakes to Avoid

- **Finishing work without closing the task** - Always run `fuel:done` when work is complete
- **Discovering work and not tracking it** - If you notice something that needs doing, `fuel:add` it immediately
- **Creating tasks for already-completed work** - Tasks are for future work, not history. Git commits provide history.
- **Leaving sessions with stale task state** - Task list should accurately reflect what's done and what remains
- **Ignoring blocked tasks** - If a blocker is resolved, the dependent task becomes ready automatically

### Task Options

When adding tasks:
- `--type=` bug, feature, task, epic, or chore
- `--priority=` 0 (critical) to 4 (backlog), default 2
- `--blocked-by=` comma-separated task IDs this depends on
- `--labels=` comma-separated labels for categorization
- `--json` for programmatic output

### Dependencies

Tasks can block other tasks. Blocked tasks won't appear in `fuel:ready` until their blockers are closed:

```bash
{{ $assist->artisanCommand('fuel:add "Design API" --type=task') }}
{{ $assist->artisanCommand('fuel:add "Implement API" --blocked-by=fuel-a7f3') }}
```

When you close a blocking task, `fuel:done` will show you which tasks are now unblocked.
