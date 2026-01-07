<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class GuidelinesCommand extends Command
{
    protected $signature = 'guidelines';

    protected $description = 'Output task management guidelines for CLAUDE.md';

    public function handle(): int
    {
        $this->line($this->getGuidelines());

        return self::SUCCESS;
    }

    private function getGuidelines(): string
    {
        return <<<'GUIDELINES'
## Fuel Task Management

This project uses **Fuel** for task tracking. Tasks are stored in `.fuel/tasks.jsonl`.

### Quick Reference

```bash
./fuel ready                      # Show tasks ready to work on
./fuel add "Task title"           # Add a new task
./fuel list                       # List all tasks
./fuel done <id>                  # Mark task as complete
./fuel board                      # Kanban view
```

### Starting a Session

Always begin by checking for available work:

```bash
./fuel ready --json
```

Pick a task and work on it. When done, mark it complete with `./fuel done <id>`.

### Working on Tasks

1. **Pick a task** from `./fuel ready` output
2. **Work on the task** - implement, test, verify
3. **Discover new work?** Add tasks with `./fuel add` and link dependencies with `--blocked-by`
4. **Complete the task** with `./fuel done <id>`

When you discover work that should be done but isn't the current focus, **always create a task for it**.

### Session Completion

Before ending a work session:

1. **Close completed tasks**: `./fuel done <id>`
2. **File tasks for incomplete work**: `./fuel add "Remaining work"`
3. **Verify task state**: `./fuel ready`

### Parallel Execution

The **primary agent** coordinates parallel work - subagents do NOT pick tasks themselves:

1. **Primary agent reviews** - Run `./fuel ready --json` and identify parallelizable tasks
2. **Primary agent assigns** - Spawn subagents with explicit task assignments
3. **Subagents execute** - Complete the task and run `./fuel done <id>`
4. **Primary agent continues** - Check `./fuel ready` for newly unblocked tasks

### Contract-First Pattern

When parallel tasks share an interface, use `--description` to define the contract:

```bash
# Create contract task
./fuel add "Add UserService" --description="Schema: {id, name, email}. Methods: create(array): array"

# Create dependent tasks
./fuel add "Add create endpoint - use contract from fuel-xxxx" --blocked-by=fuel-xxxx
./fuel add "Add update endpoint - use contract from fuel-xxxx" --blocked-by=fuel-xxxx
```

Subagents read the parent task's description to see the interface they must implement.

### Task Options

```bash
./fuel add "Title" \
  --description="Details" \
  --type=feature \        # bug|feature|task|epic|chore
  --priority=1 \          # 0 (critical) to 4 (backlog)
  --blocked-by=fuel-xxxx  # Dependencies
  --labels=api,backend    # Comma-separated labels
```

### JSON Output

All commands support `--json` for programmatic use.
GUIDELINES;
    }
}
