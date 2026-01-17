> [!WARNING]
> Don't use this. This is a work in progress (WIP) and has only been tested by 1.2 people. 
> Windows is purposely not supported, life is complicated enough as it is.

# Fuel

Batteries included AI agent orchestrator and task management. 

## Quickstart

```bash
# Install
curl -fsSL https://addfuel.dev/install | sh

# Initialize in your project
cd your-project
fuel init
fuel consume
```

That's it. `fuel init` creates a `.fuel/` directory, adds workflow instructions to both `AGENTS.md` and `CLAUDE.md`, installs agent skills, and creates your first task.

## Orchestration

Fuel automatically spawns AI agents to work through your task queue. It works with any CLI coding agent you configure in `.fuel/config.yaml`.

Configure which agents handle different complexity levels, then let Fuel orchestrate.

```bash
# Watch agents consume tasks automatically
fuel consume
```

This displays a live Kanban board and spawns agents for each ready task based on the complexity routing in `.fuel/config.yaml`.

### Remote Visualization

Connect to a remote Fuel daemon with `fuel consume --ip=192.168.1.100 --port=9400` to view and control tasks running on another machine.

### Agent Permissions

**All drivers include autonomous mode by default** - they skip permission prompts to enable headless execution during `fuel consume`, for example with `--dangerously-skip-permissions`.

> [!CAUTION]
> Autonomous mode allows agents to modify files and run commands without approval. Use in trusted environments only.

**Backlog Examples:**

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

## Configuration

Fuel uses `.fuel/config.yaml` with a driver-based approach: define agents once, reference them by name.

```yaml
primary: claude-opus
review: claude-opus
reality: claude-opus

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

Drivers automatically set up the correct CLI args and environment. 

