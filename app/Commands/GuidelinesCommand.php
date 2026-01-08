<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class GuidelinesCommand extends Command
{
    protected $signature = 'guidelines
        {--add : Inject guidelines into AGENTS.md}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Output task management guidelines for CLAUDE.md';

    public function handle(): int
    {
        $content = $this->getGuidelinesContent();

        if ($this->option('add')) {
            return $this->injectIntoAgentsMd($content);
        }

        $this->line($content);

        return self::SUCCESS;
    }

    protected function injectIntoAgentsMd(string $content): int
    {
        $cwd = $this->option('cwd') ?: getcwd();
        $agentsMdPath = $cwd.'/AGENTS.md';

        $fuelSection = "<fuel>\n{$content}</fuel>\n";

        if (file_exists($agentsMdPath)) {
            $existing = file_get_contents($agentsMdPath);

            // Replace existing <fuel>...</fuel> section or append
            if (preg_match('/<fuel>.*?<\/fuel>/s', $existing)) {
                $updated = preg_replace('/<fuel>.*?<\/fuel>\n?/s', $fuelSection, $existing);
            } else {
                $updated = rtrim($existing)."\n\n".$fuelSection;
            }

            file_put_contents($agentsMdPath, $updated);
            $this->info('Updated AGENTS.md with Fuel guidelines');
        } else {
            file_put_contents($agentsMdPath, "# Agent Instructions\n\n".$fuelSection);
            $this->info('Created AGENTS.md with Fuel guidelines');
        }

        return self::SUCCESS;
    }

    protected function getGuidelinesContent(): string
    {
        return <<<'MARKDOWN'
## Fuel Task Management

This project uses **Fuel** for lightweight task tracking. Tasks live in `.fuel/tasks.jsonl`.

### Quick Reference

```bash
fuel ready                      # Show tasks ready to work on
fuel add "Task title"           # Add a new task
fuel add "Idea" --someday       # Add to backlog (future work)
fuel start <id>                 # Claim a task (in_progress)
fuel done <id>                  # Mark task complete
fuel show <id>                  # View task details
fuel board --once               # Kanban view
fuel tree                       # Tree view
fuel backlog                    # List backlog items
fuel promote <b-id>             # Promote backlog item to task
fuel defer <f-id>               # Move task to backlog
fuel dep:add <id> <blocker>     # Add dependency
fuel dep:remove <id> <blocker>  # Remove dependency
```

### TodoWrite vs Fuel

Use **TodoWrite** for single-session step tracking. Use **fuel** for work that outlives the session (multi-session, dependencies, discovered work for future). When unsure, prefer fuel.

### 🚨 MANDATORY: Session Close Protocol - Land The Plane
**YOU MUST COMPLETE EVERY STEP BELOW BEFORE EXITING. NO EXCEPTIONS.**

```
[ ] Run tests                     # Quality gate (if you changed code)
[ ] Run linter/formatter          # Fix formatting (if you changed code)
[ ] git add <files>               # Stage your changes
[ ] git commit -m "feat/fix:..."  # Conventional commit - note the hash from output [main abc1234]
[ ] fuel done <id> --commit=<hash>  # Mark complete with commit hash from above
[ ] fuel add "..."              # File tasks for ANY remaining or discovered work

[ ] Hand off - Provide context for next session
```


Commit messages: `feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`

### Workflow to work on one task

1. `fuel ready` - Find available work (prefer P0 > P1 > P2)
2. `fuel start <id>` - Claim task before starting
3. Do the work - implement, test, verify
4. Discover new work? `fuel add "..." --blocked-by=<id>`
5. `fuel done <id> --commit=<hash>` - Complete with commit hash
6. Land the plane

### Task Options

```bash
fuel add "Title" --description="..." --type=bug|feature|task|chore --priority=0|1|2|3|4 --blocked-by=f-xxxx --labels=api,urgent --complexity=trivial|simple|moderate|complex
```

### Writing Good Descriptions

Descriptions should be explicit enough for a less capable agent to complete without guessing. Include: files to modify (exact paths), what to change (methods, patterns), expected behavior, and patterns to follow.

**Bad**: "Fix the ID display bug"
**Good**: "BoardCommand.php:320 uses substr($id, 5, 4) for old format. Change to substr($id, 2, 6) for f-xxxxxx format."

### Complexity

**Always set `--complexity`:** `trivial` (typos) | `simple` (single focus) | `moderate` (multiple files) | `complex` (break into subtasks)

### Dependencies

```bash
fuel add "Implement API" --blocked-by=f-xxxx
```

Blocked tasks won't appear in `fuel ready` until blockers are closed.

### Backlog Management

The backlog (`.fuel/backlog.jsonl`) is for **rough ideas and future work** that isn't ready to implement yet. Tasks are for **work ready to implement now**.

**When to use backlog vs tasks:**

- **Backlog (`fuel add --someday`)**: Rough ideas, future enhancements, "nice to have" features, exploratory concepts, work that needs more thought before implementation
- **Tasks (`fuel add`)**: Work that's ready to implement now, has clear requirements, can be started immediately

**Backlog commands:**

```bash
fuel add "Future idea" --someday          # Add to backlog (ignores other options)
fuel backlog                              # List all backlog items
fuel promote <b-id>                      # Promote backlog item to task (adds --priority, --type, etc.)
fuel defer <f-id>                         # Move a task to backlog
fuel remove <b-id>                        # Delete a backlog item
```

**Promoting backlog to tasks:**

When a backlog item is ready to work on:
1. Review the backlog: `fuel backlog`
2. Promote with task metadata: `fuel promote <b-id> --priority=2 --type=feature --complexity=moderate`
3. The backlog item is removed and a new task is created with the same title/description

**Deferring tasks:**

If a task isn't ready to work on (needs more planning, blocked externally, wrong priority):
1. `fuel defer <f-id>` - Moves task to backlog, preserving title and description
2. Later, promote it back when ready: `fuel promote <b-id> --priority=...`

**Backlog format:**

Backlog items are simplified (title + description only). When promoted, you add task metadata (priority, type, complexity, etc.). This keeps the backlog lightweight for ideas, while tasks have full structure for execution.

### Needs-Human Workflow

When blocked on credentials, decisions, or manual steps:
1. `fuel add 'What you need' --labels=needs-human --description='Instructions'`
2. `fuel dep:add <your-task> <needs-human-task>` - Block your work
3. Human runs `fuel done <needs-human-task>`, your task reappears in `fuel ready`

### Parallel Execution to consume the fuel
Do this when asked to consume the fuel

Primary agent coordinates - subagents do NOT pick tasks:

1. Primary runs `fuel ready --json`, identifies parallel work
2. Primary claims each task with `fuel start <id>` before spawning
3. Primary spawns subagents with explicit task IDs and instructions to run `fuel done <id>`
4. Primary reviews subagent work (tests added? tests pass? matches requirements?)
5. If issues found: `fuel add "Fix X from f-xxxx"`
6. Check `fuel ready` for newly unblocked work

When parallel tasks share an interface, define it in a parent task's description. Avoid parallel work on tasks touching same files - use dependencies instead.
MARKDOWN;
    }
}
