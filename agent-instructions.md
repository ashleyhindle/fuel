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

### Parallel Execution

Primary agent coordinates - subagents do NOT pick tasks:

1. Primary runs `./fuel ready --json`, identifies parallel work
2. Primary claims each task with `./fuel start <id>`
3. Primary spawns subagents with explicit task ID assignments
4. Subagents complete work and run `./fuel done <id>`
5. Primary checks `./fuel ready` for newly unblocked work

**Subagent instructions must include:** task ID, task information, instruction to run `./fuel done <id>` after landing the plane.

Avoid parallel work on tasks touching same files - use dependencies instead.
