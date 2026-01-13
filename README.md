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
```

Run your favourite agent and ask it to "Consume the fuel and land the plane".

That's it. `fuel init` creates a `.fuel/` directory, adds workflow instructions to both `AGENTS.md` and `CLAUDE.md`, installs agent skills, and creates your first task.

## Orchestration

Fuel automatically spawns AI agents to work through your task queue. It works with any CLI coding agent you configure in `.fuel/config.yaml`.

Configure which agents handle different complexity levels, then let Fuel orchestrate.

```bash
# Watch agents consume tasks automatically
fuel consume
```

This displays a live Kanban board and spawns agents for each ready task based on the complexity routing in `.fuel/config.yaml`.

### Agent Permissions

**All drivers include autonomous mode by default** - they skip permission prompts to enable headless execution during `fuel consume`. This includes:

- **claude**: `--dangerously-skip-permissions`
- **cursor-agent**: `--force`
- **opencode**: `OPENCODE_PERMISSION={"permission":"allow"}`
- **amp**: `--dangerously-allow-all`
- **codex**: `--dangerously-bypass-approvals-and-sandbox`

> [!CAUTION]
> Autonomous mode allows agents to modify files and run commands without approval. Use in trusted environments only.

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

Fuel uses `.fuel/config.yaml` with a driver-based approach: define agents once, reference them by name.

```yaml
# Primary agent for orchestration/decision-making (required)
primary: claude-opus
review: claude-opus

# Map complexity levels to agent names
complexity:
  trivial: cursor-composer
  simple: cursor-composer
  moderate: claude-sonnet
  complex: claude-opus

# Define agents once with drivers
agents:
  cursor-composer:
    driver: cursor-agent
    model: composer-1
    max_concurrent: 3
    max_attempts: 3

  claude-sonnet:
    driver: claude
    model: sonnet
    max_concurrent: 2
    max_attempts: 3

  claude-opus:
    driver: claude
    model: opus
    max_concurrent: 3
    max_attempts: 5
```

### Drivers

Built-in drivers handle CLI invocation and provide sensible defaults:

- **claude** - Claude Code CLI (`claude`)
- **cursor-agent** - Cursor Agent CLI (`cursor-agent`)
- **opencode** - OpenCode CLI (`opencode`)
- **amp** - Amp CLI (`amp`)
- **codex** - Codex CLI (`codex`)

Drivers automatically set up the correct CLI args and environment. Use `extra_args` or `extra_env` to extend defaults:

```yaml
agents:
  claude-sonnet-auto:
    driver: claude
    model: sonnet
    extra_args:
      - "--dangerously-skip-permissions"
    max_concurrent: 2
    max_attempts: 3
```

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
