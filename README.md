> [!WARNING]
> This is a work in progress (WIP) and has only been tested by 1.2 people. Use at your own risk.
> Windows is not supported.

# Fuel

Lightweight AI agent task orchestrator and management. 

## Quickstart

```bash
# Install
curl -fsSL https://addfuel.dev/install | sh

# Initialize in your project
cd your-project
fuel init

# Add guidelines to your agent's instructions
fuel guidelines --add          # Auto-manages in AGENTS.md (recommended)
# or: fuel guidelines >> CLAUDE.md      # For Claude Code
# or: fuel guidelines >> .cursorrules   # For Cursor
```

Run your favourite agent and ask it to "Consume the fuel and land the plane".

That's it. `fuel init` creates a `.fuel/` directory, adds workflow instructions to `AGENTS.md`, and creates your first task.

**Important:** The `fuel guidelines` step teaches your agent the task workflow. Without it, agents won't know how to use fuel commands properly. Use `--add` for automatic management in `AGENTS.md`, or manually add to whichever file your agent reads (`CLAUDE.md`, `.cursorrules`, etc).

## Orchestration

Fuel automatically spawns AI agents to work through your task queue. It works with any CLI coding agent you configure in `.fuel/config.yaml`.

Configure which agents handle different complexity levels, then let Fuel orchestrate.

```bash
# Watch agents consume tasks automatically
fuel consume
```

This displays a live Kanban board and spawns agents for each ready task based on the complexity routing in `.fuel/config.yaml`.

### Agent Permissions

By default, agents prompt for permission before editing files or running commands. This blocks `fuel consume` from running autonomously since it spawns agents in headless mode.

**Option 1: Autonomous mode (unattended)**

Enable auto-approve flags in `.fuel/config.yaml`:

```yaml
moderate:
  agent: claude
  model: sonnet
  args:
    - "--dangerously-skip-permissions"

simple:
  agent: cursor-agent
  model: composer-1
  args:
    - "--force"
```

> [!CAUTION]
> Autonomous mode allows agents to modify files and run commands without approval. Use in trusted environments only.

**Option 2: Interactive mode (supervised)**

Run your agent interactively and let it work through tasks:

```bash
claude
# Then say: "Work through all the remaining fuel"
```

When prompted for permissions, select "Always allow" to build up a trusted toolset. Once configured, `fuel consume` will work smoothly since permissions persist across sessions.

## Why Fuel?

AI agents forget. Context windows compact. Sessions end. **Fuel persists.**

- Tasks survive across sessions
- Dependencies block work until ready
- Parallel agents coordinate without conflicts
- JSONL format merges cleanly in git

## Commands
Typically you don't need to run anything, let your AI agent use fuel directly, and run `fuel board` or `fuel consume` to keep an eye on things.

```bash
fuel ready                    # Show tasks ready to work on
fuel add "Task title"         # Add a new task
fuel start <id>               # Claim a task (in_progress)
fuel done <id>                # Mark task complete
fuel show <id>                # View task details
fuel board                    # Kanban view
```

### Task Options

```bash
fuel add "Title" \
  --description="Details" \
  --type=bug|feature|task|chore \
  --priority=0-4 \
  --blocked-by=fuel-xxxx \
  --labels=api,urgent
```

### Dependencies

```bash
fuel add "Design API"
fuel add "Implement API" --blocked-by=fuel-xxxx
```

Blocked tasks won't appear in `fuel ready` until blockers are done.

### Backlog

The backlog is a simplified storage for future ideas and deferred work. Unlike tasks, backlog items only store a title and optional description—no priority, type, complexity, labels, or dependencies. This keeps the backlog lightweight for capturing rough ideas that may be refined later.

**Backlog vs Tasks:**
- **Backlog items** (`b-xxxxxx`): Title and description only, stored in `.fuel/backlog.jsonl`
- **Tasks** (`f-xxxxxx`): Full task metadata (priority, type, complexity, labels, dependencies, status), stored in `.fuel/tasks.jsonl`

```bash
# Add an idea to the backlog (ignores other task options)
fuel add "Future enhancement idea" --someday
fuel add "Explore new feature" --someday --description="Initial thoughts..."

# List all backlog items
fuel backlog

# Promote a backlog item to a full task (with task options)
fuel promote b-xxxxxx --priority=2 --type=feature --complexity=moderate
fuel promote b-xxxxxx --priority=1 --labels=api,urgent

# Move a task to the backlog (simplifies it, removes metadata)
fuel defer f-xxxxxx

# Remove a backlog item
fuel remove b-xxxxxx
```

**Examples:**

```bash
# Capture a rough idea
fuel add "Add dark mode support" --someday

# Later, promote it to a task when ready to work on it
fuel promote b-a1b2c3 --priority=2 --type=feature --complexity=moderate

# If a task becomes less urgent, defer it to backlog
fuel defer f-d4e5f6

# View all backlog items
fuel backlog
```

Backlog items are stored in `.fuel/backlog.jsonl` with a simplified format:

```json
{"id":"b-a1b2c3","title":"Add dark mode support","description":"User-requested feature","created_at":"2026-01-07T10:00:00Z"}
```

## For Agents

Fuel includes workflow instructions that teach agents the task lifecycle:

1. `fuel ready` - Find work
2. `fuel start <id>` - Claim it
3. Do the work
4. `fuel done <id>` - Complete it

Run `fuel guidelines` to see the full agent instructions.

## Storage

Single file: `.fuel/tasks.jsonl` - one JSON object per line, sorted by ID.

```json
{"id":"fuel-a7f3","title":"Add login","status":"open","priority":2,"created_at":"2026-01-07T10:00:00Z"}
{"id":"fuel-b4c2","title":"Write tests","status":"open","blocked_by":["fuel-a7f3"]}
```

Commit it. Branch it. Merge it. Git handles the rest.

## Configuration

Fuel uses `.fuel/config.yaml` to route tasks to different agents based on complexity.

```yaml
complexity:
  trivial:
    agent: cursor-agent
    model: composer-1
    # args:
    #   - "--force"

  simple:
    agent: cursor-agent
    model: composer-1
    # args:
    #   - "--force"

  moderate:
    agent: claude
    model: sonnet
    # args:
    #   - "--dangerously-skip-permissions"

  complex:
    agent: claude
    model: opus
    # args:
    #   - "--dangerously-skip-permissions"
```

Each complexity level maps to:
- `agent` - The CLI command to spawn (e.g., `claude`, `cursor-agent`, `opencode`)
- `model` - Model name passed via `--model` flag
- `args` - Optional extra arguments (e.g., `["--mcp-server", "github"]`)

All agents use `-p` for prompts and `--model` for model selection.

## Deployment

Deploy the public site to Cloudflare Pages:

```bash
./deploy.sh
```

The script will:
- Verify authentication (prompts login if needed)
- Deploy `public/` directory to Cloudflare Pages using `npx wrangler`

After deployment, configure the custom domain `addfuel.dev` in the Cloudflare Dashboard.

## License

MIT
