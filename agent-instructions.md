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
