# Fuel

Lightweight task management for AI agents. Git-native, merge-friendly, zero config.

## Quickstart

```bash
# Install
curl -L addfuel.dev/install | sh

# Initialize in your project
cd your-project
fuel init

# Run your favourite agent and ask it to "Consume the fuel"
```

That's it. `fuel init` creates a `.fuel/` directory, adds workflow instructions to `AGENTS.md`, and creates your first task.

If you need to udpate CLAUDE.md run `fuel guidelines` and copy/paste.

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
