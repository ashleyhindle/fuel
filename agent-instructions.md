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

**Always set `--complexity` when adding tasks.** This helps agents understand the scope and effort required.

**Complexity levels:**
- `trivial` - Quick fixes, simple changes (e.g., updating a string, fixing a typo)
- `simple` - Straightforward work with clear requirements (e.g., adding a new field, simple refactoring)
- `moderate` - Requires multiple steps or touches several files (e.g., adding a new command, implementing a feature with tests)
- `complex` - Large scope, architectural changes, or unclear requirements

**Important:** Tasks marked as `complex` should often be broken down into smaller subtasks. If a task feels too large or uncertain, consider:
1. Creating a parent task for planning/design
2. Breaking it into smaller, more focused tasks
3. Using dependencies to sequence the work

**Example:**
```bash
# Complex task - should be broken down
./fuel add "Refactor authentication system" \
  --complexity=complex \
  --description="This is too large - break into subtasks"

# Better: Break it down
./fuel add "Design new auth architecture" --complexity=moderate
./fuel add "Implement OAuth provider" --complexity=moderate --blocked-by=fuel-xxxx
./fuel add "Add session management" --complexity=moderate --blocked-by=fuel-xxxx
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
