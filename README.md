> [!WARNING]
> This is a work in progress (WiP) and has only been tested by one person. Use at your own risk.

# Fuel

Lightweight AI agent task orchestrator and management. Git-native, merge-friendly, zero config.

## Quickstart

```bash
# Install
curl -fsSL https://addfuel.dev/install | sh

# Initialize in your project
cd your-project
fuel init

# Run your favourite agent and ask it to "Consume the fuel"
```

That's it. `fuel init` creates a `.fuel/` directory, adds workflow instructions to `AGENTS.md`, and creates your first task.

If you need to udpate CLAUDE.md run `fuel guidelines` and copy/paste.

## Orchestration

Fuel can automatically spawn AI agents to work through your task queue. Configure which agents handle different complexity levels, then let Fuel orchestrate.

```bash
# Watch agents consume tasks automatically
fuel consume
```

This displays a live Kanban board and spawns agents for each ready task based on the complexity routing in `.fuel/config.yaml`.

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

Blocked tasks won't appear in `fuel ready` until blockers are closed.

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

  simple:
    agent: cursor-agent
    model: composer-1

  moderate:
    agent: claude
    model: sonnet-4.5

  complex:
    agent: claude
    model: opus-4.5
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
