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

```
[ ] Run tests                     # Quality gate (if you changed code)
[ ] Run linter/formatter          # Fix formatting (if you changed code)
[ ] git add <files>               # Stage your changes
[ ] git commit -m "feat/fix:..."  # Commit - note the hash from output [main abc1234]
[ ] ./fuel done <id> --commit=<hash>  # Mark complete with commit hash from above
[ ] ./fuel add "..."              # File tasks for ANY incomplete/discovered work
[ ] ./fuel ready                  # Verify task state is correct
```

**Failure to complete these steps means your work is NOT done.**

Commit messages should follow conventional commits: `feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`

### Workflow

1. `./fuel ready` - Find available work if not provided a particular task (prefer P0 > P1 > P2)
2. `./fuel start <id>` - Claim task before starting
3. Do the work - implement, test, verify
4. Discover new work as you build? Add it `./fuel add "..." --blocked-by=<id>`
5. `./fuel done <id> --commit=<hash>` - Complete the task (hash from your git commit output)
6. Land the plane

### Task Options

```bash
./fuel add "Title" \
  --description="Details here" \
  --type=bug|feature|task|chore \
  --priority=0-4 \
  --blocked-by=fuel-xxxx,fuel-yyyy \
  --labels=api,urgent \
  --complexity=trivial|simple|moderate|complex
```

### Complexity

**Always set `--complexity` when adding tasks:** `trivial` (typos, string changes) | `simple` (clear requirements, single focus) | `moderate` (multiple steps/files) | `complex` (architectural, break into subtasks)

### Dependencies

```bash
./fuel add "Design API"
./fuel add "Implement API" --blocked-by=fuel-xxxx
```

Blocked tasks won't appear in `./fuel ready` until blockers are closed.

### Needs-Human Workflow

When blocked on credentials, decisions, verification, or manual steps:

1. Create needs-human task with clear description of what's needed:
   ```bash
   ./fuel add 'Provide Cloudflare API token' \
     --labels=needs-human \
     --description='Run npx wrangler login or set CLOUDFLARE_API_TOKEN'
   ```
2. Block current work: `./fuel dep:add <current-task-id> <needs-human-task-id>`
3. Human completes and runs `./fuel done <needs-human-task-id>`
4. Your blocked task reappears in `./fuel ready`

### Parallel Execution

Primary agent coordinates - subagents do NOT pick tasks:

1. Primary runs `./fuel ready --json`, identifies parallel work
2. Primary claims each task with `./fuel start <id>`
3. Primary spawns subagents with explicit task ID assignments
4. Subagents complete work and run `./fuel done <id>`
5. **Primary reviews subagent work** - verify tests added, check implementation, run tests
6. If issues found: create fix task referencing the original (e.g., `./fuel add "Fix X from fuel-xxxx"`)
7. Primary checks `./fuel ready` for newly unblocked work

**Subagent instructions must include:** task ID, task information, instruction to run `./fuel done <id>` after landing the plane.

**Review checklist for primary:**
- Did subagent add tests?
- Do all tests pass?
- Does the implementation match the task requirements?
- Any obvious bugs or issues in the code?

Avoid parallel work on tasks touching same files - use dependencies instead.
