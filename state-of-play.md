# Fuel - State of Play

*Last updated: 2026-01-10*

Fuel is an AI agent orchestration system built on Laravel Zero. It manages tasks, spawns agents to work on them, reviews their output, and surfaces results for human review.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              HUMAN                                           │
│                                                                              │
│   fuel add "..."     fuel epic:add "..."     fuel consume     fuel human    │
└──────────┬───────────────────┬────────────────────┬──────────────┬──────────┘
           │                   │                    │              │
           ▼                   ▼                    ▼              ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            CLI COMMANDS                                      │
│                                                                              │
│  AddCommand    EpicAddCommand    ConsumeCommand    HumanCommand    ...      │
└──────────┬───────────────────────────┬──────────────────────────────────────┘
           │                           │
           ▼                           ▼
┌─────────────────────┐    ┌─────────────────────────────────────────────────┐
│     SERVICES        │    │              CONSUME LOOP                        │
│                     │    │                                                  │
│  TaskService ───────┼───▶│   1. Get ready tasks (fuel ready)               │
│  EpicService ───────┼───▶│   2. Route to agent by complexity               │
│  ConfigService ─────┼───▶│   3. Spawn agent process                        │
│  ReviewService ─────┼───▶│   4. Monitor completion                         │
│  ProcessManager ────┼───▶│   5. Trigger review                             │
│  AgentHealthTracker─┼───▶│   6. Handle success/failure                     │
│  DatabaseService ───┼───▶│   7. Check for newly unblocked tasks            │
│                     │    │   8. Loop                                        │
└─────────────────────┘    └─────────────────────────────────────────────────┘
           │                           │
           ▼                           ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           DATA STORAGE                                       │
│                                                                              │
│   .fuel/                                                                     │
│   ├── agent.db           ← SQLite: tasks, epics, reviews, runs, health      │
│   ├── backlog.jsonl      ← Future ideas (git-tracked)                       │
│   ├── config.yaml        ← Agent definitions, routing (git-tracked)         │
│   └── processes/         ← Live stdout/stderr per task (.gitignored)        │
│       └── {taskId}/                                                          │
│           ├── stdout.log                                                     │
│           └── stderr.log                                                     │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Core Concepts

### Tasks

The fundamental unit of work. Stored in SQLite (`agent.db`).

```
┌─────────────────────────────────────────────────────────────────┐
│ TASK                                                             │
├─────────────────────────────────────────────────────────────────┤
│ id: f-xxxxxx          (6 hex chars)                             │
│ title: "Add login endpoint"                                     │
│ description: "Create POST /auth/login in routes/api.php..."     │
│ status: open | in_progress | review | closed | cancelled        │
│ type: bug | fix | feature | task | chore | docs | test | ...    │
│ priority: 0 (P0) | 1 (P1) | 2 (P2) | 3 | 4                      │
│ complexity: trivial | simple | moderate | complex                │
│ labels: ["api", "auth"]                                          │
│ blocked_by: ["f-abc123"]                                         │
│ epic_id: e-xxxxxx (optional)                                     │
└─────────────────────────────────────────────────────────────────┘
```

### Task Lifecycle

```
         fuel add
             │
             ▼
         ┌──────┐
         │ open │
         └──┬───┘
            │ fuel start / consume picks up
            ▼
     ┌─────────────┐
     │ in_progress │ ◄─────────────────────────────┐
     └──────┬──────┘                               │
            │ agent completes (fuel done)          │
            ▼                                      │
       ┌────────┐                                  │
       │ review │ ← review agent checks work       │
       └───┬────┘                                  │
           │                                       │
     ┌─────┴─────┐                                 │
     │           │                                 │
  passes      fails                                │
     │           │                                 │
     ▼           ▼                                 │
 ┌────────┐   creates follow-up task ──────────────┘
 │ closed │
 └────────┘
```

### Epics

Group related tasks. Stored in SQLite (`agent.db`). Status derived from task states.

```
┌─────────────────────────────────────────────────────────────────┐
│ EPIC                                                             │
├─────────────────────────────────────────────────────────────────┤
│ id: e-xxxxxx                                                     │
│ title: "Add user preferences"                                    │
│ description: "Full preferences system with API and UI"          │
│ status: (computed from tasks)                                    │
│ reviewed_at: timestamp (set by epic:reviewed)                    │
└─────────────────────────────────────────────────────────────────┘

Epic Status Derivation:
┌───────────────────┬─────────────────────────────────────────────┐
│ planning          │ Epic has no linked tasks                    │
│ in_progress       │ Any linked task is open or in_progress      │
│ review_pending    │ All tasks closed, reviewed_at is NULL       │
│ reviewed          │ reviewed_at is set (human looked at it)     │
└───────────────────┴─────────────────────────────────────────────┘
```

### Epic Lifecycle

```
  fuel epic:add "Feature"
             │
             ▼
      ┌──────────┐
      │ planning │  (no tasks yet)
      └────┬─────┘
           │ fuel add "..." --epic=e-xxx
           ▼
    ┌─────────────┐
    │ in_progress │ ◄──────────────────┐
    └──────┬──────┘                    │
           │ all tasks closed          │
           ▼                           │
   ┌────────────────┐                  │
   │ review_pending │                  │
   │                │ ─── creates ───▶ needs-human task
   └───────┬────────┘    with summary  │
           │                           │
           │ fuel epic:reviewed        │
           ▼                           │
      ┌──────────┐                     │
      │ reviewed │                     │
      └──────────┘                     │
           │                           │
     (if human wants changes,          │
      they add new tasks) ─────────────┘
```

---

## Agent System

### Configuration (`.fuel/config.yaml`)

```yaml
agents:
  claude-opus:
    command: "claude --model opus"
    prompt_args: ["--prompt"]

  claude-sonnet:
    command: "claude --model sonnet"
    prompt_args: ["--prompt"]

  cursor-composer:
    command: "cursor-agent"
    prompt_args: []

complexity:
  trivial: cursor-composer
  simple: claude-sonnet
  moderate: claude-opus
  complex: claude-opus

review_agent: claude-sonnet

max_concurrent: 3
poll_interval: 5
```

### Complexity-Based Routing

```
Task Complexity          Agent
─────────────────────────────────────
trivial (typo fix)   →   cursor-composer
simple (one file)    →   claude-sonnet
moderate (multi-file)→   claude-opus
complex (architectural)→ claude-opus
```

### Agent Health Tracking

Stored in SQLite. Prevents hammering failing agents.

```
┌─────────────────────────────────────────────────────────────────┐
│ agent_health table                                               │
├─────────────────────────────────────────────────────────────────┤
│ agent: "claude-opus"                                             │
│ consecutive_failures: 2                                          │
│ backoff_until: "2026-01-10T15:30:00Z"                           │
│ total_runs: 47                                                   │
│ total_successes: 45                                              │
└─────────────────────────────────────────────────────────────────┘

Exponential Backoff:
  1st failure: 30 seconds
  2nd failure: 1 minute
  3rd failure: 2 minutes
  4th failure: 4 minutes
  5th+ failure: 8 minutes (max)
```

---

## Review System

When an agent completes a task, a review agent evaluates the work.

```
┌─────────────────────────────────────────────────────────────────┐
│                     REVIEW FLOW                                  │
└─────────────────────────────────────────────────────────────────┘

Agent completes task
        │
        ▼
┌───────────────────┐
│ Capture context:  │
│ - git diff        │
│ - git status      │
│ - task description│
└────────┬──────────┘
         │
         ▼
┌───────────────────┐
│ Spawn review agent│ (configured via review_agent in config)
│ with ReviewPrompt │
└────────┬──────────┘
         │
         ▼
┌───────────────────┐
│ Review checks:    │
│ □ Uncommitted?    │
│ □ Tests pass?     │
│ □ Matches intent? │
└────────┬──────────┘
         │
    ┌────┴────┐
    │         │
 passes    fails
    │         │
    ▼         ▼
 close    create follow-up
 task     task with issues
```

### Review Storage (SQLite)

```
┌─────────────────────────────────────────────────────────────────┐
│ reviews table                                                    │
├─────────────────────────────────────────────────────────────────┤
│ id: r-xxxxxx                                                     │
│ task_id: f-abc123                                                │
│ agent: claude-sonnet                                             │
│ status: pending | passed | failed                                │
│ issues: ["uncommitted_changes", "tests_failing"]                 │
│ followup_task_ids: ["f-def456"]                                  │
│ started_at, completed_at                                         │
└─────────────────────────────────────────────────────────────────┘
```

---

## Human Touchpoints

### Needs-Human Tasks

When work is blocked on human input (credentials, decisions, approvals):

```bash
fuel add "Get API keys from client" --labels=needs-human
fuel dep:add f-mywork f-needskeys  # Block your work on the human task
```

```
┌───────────────┐     ┌───────────────┐
│ Your task     │────▶│ needs-human   │
│ (blocked)     │     │ task          │
└───────────────┘     └───────┬───────┘
                              │
                        human completes
                              │
                              ▼
                      ┌───────────────┐
                      │ Your task     │
                      │ (unblocked)   │
                      └───────────────┘
```

### fuel human Command

Shows everything needing human attention:
- Tasks with `needs-human` label
- Epic review notifications
- Any stuck or failed work

---

## Data Storage Split

```
┌─────────────────────────────────────────────────────────────────┐
│                    WHY THIS SPLIT?                               │
└─────────────────────────────────────────────────────────────────┘

SQLite (.fuel/agent.db)
├── Primary data store for active work
├── Fast queries, joins, aggregations
├── Tasks (the fundamental unit of work)
├── Epics (cross-task grouping)
├── Runs (agent execution history)
├── Reviews (transient process data)
├── Agent health (local telemetry)
└── Schema versioned with auto-migrations

JSONL (.fuel/backlog.jsonl)
├── Git-tracked, merge-friendly
├── Human-readable, editable
├── Rough ideas and future work
└── Survives across machines via git
```

---

## Process Management

### ProcessManager

Handles spawning and tracking agent subprocesses.

```
┌─────────────────────────────────────────────────────────────────┐
│ ProcessManager                                                   │
├─────────────────────────────────────────────────────────────────┤
│ spawn(taskId, agent, command, cwd) → Process                    │
│ isRunning(taskId) → bool                                         │
│ kill(taskId) → void                                              │
│ getOutput(taskId) → ProcessOutput                                │
│ getRunningCount() → int                                          │
│ waitForAny(timeoutMs) → ?ProcessResult                          │
│ shutdown() → void (graceful SIGTERM)                            │
└─────────────────────────────────────────────────────────────────┘

Output stored in:
  .fuel/processes/{taskId}/stdout.log
  .fuel/processes/{taskId}/stderr.log
```

---

## Consume Loop

The main orchestration loop in `fuel consume`:

```
┌─────────────────────────────────────────────────────────────────┐
│                      CONSUME LOOP                                │
└─────────────────────────────────────────────────────────────────┘

while (running) {
    │
    ├─▶ Check for completed processes
    │   └─▶ For each completed:
    │       ├─▶ Record success/failure in agent health
    │       ├─▶ Trigger review (unless --skip-review)
    │       └─▶ Check epic completion
    │
    ├─▶ Get ready tasks (unblocked, not in progress)
    │
    ├─▶ For each ready task (up to max_concurrent):
    │   ├─▶ Check agent health (skip if in backoff)
    │   ├─▶ Route by complexity → agent
    │   ├─▶ Build command with task prompt
    │   ├─▶ Spawn process
    │   └─▶ Mark task in_progress
    │
    ├─▶ Display status (board view)
    │
    └─▶ Sleep(poll_interval)
}
```

---

## Key Commands

| Command | Purpose |
|---------|---------|
| `fuel add "..."` | Create a task |
| `fuel ready` | Show unblocked open tasks |
| `fuel start <f-id>` | Claim a task (in_progress) |
| `fuel done <f-id>` | Mark task complete |
| `fuel show <f-id/e-id/r-id>` | View task/epic/review details (delegates by ID prefix) |
| `fuel board` | Kanban view |
| `fuel consume` | Start orchestration loop |
| `fuel epic:add "..."` | Create an epic |
| `fuel epics` | List all epics |
| `fuel epic:show <e-id>` | View epic + linked tasks |
| `fuel epic:reviewed <e-id>` | Mark epic as human-reviewed |
| `fuel human` | Show needs-human tasks |
| `fuel health` | Show agent health status |
| `fuel review <f-id>` | Manually trigger review of a task |
| `fuel review:show <r-id>` | View review details + agent stdout |
| `fuel reviews` | List recent reviews |

---

## File Structure

```
fuel/
├── app/
│   ├── Commands/           ← CLI commands
│   │   ├── AddCommand.php
│   │   ├── ConsumeCommand.php
│   │   ├── EpicAddCommand.php
│   │   └── ...
│   ├── Services/           ← Core business logic
│   │   ├── TaskService.php      ← SQLite task CRUD
│   │   ├── EpicService.php      ← SQLite epic CRUD + status
│   │   ├── ConfigService.php    ← Agent routing config
│   │   ├── ReviewService.php    ← Review orchestration
│   │   ├── ProcessManager.php   ← Subprocess handling
│   │   ├── AgentHealthTracker.php
│   │   └── DatabaseService.php  ← SQLite + migrations
│   ├── Contracts/          ← Interfaces
│   └── Process/            ← Value objects
├── .fuel/                  ← Project data (created per-project)
├── prompts/                ← Reusable prompts
│   └── breakdown.md        ← Epic decomposition template
├── tests/
├── CLAUDE.md               ← Agent instructions
└── fuel                    ← CLI entry point
```

---

## Current Phase: 4 Complete, 5 Next

| Phase | Status | Description |
|-------|--------|-------------|
| 1. Process Management | ✅ | Robust subprocess handling |
| 2. Agent Health | ✅ | Failure tracking, backoff |
| 3. Auto-Review | ✅ | Quality gate on completion |
| 4. Epics | ✅ | Task grouping, combined review |
| 5. Human Inbox | 🔜 | Consolidated review queue |
| 6. TUI | 📋 | Rich interactive interface |
| 7. Primary Routing | 📋 | AI-driven task assignment (deprioritized) |

---

## Design Decisions

| Decision | Rationale |
|----------|-----------|
| SQLite for tasks | Fast queries, reliable storage, auto-migration from JSONL |
| SQLite for epics | Need cross-task queries, not collaborative |
| Complexity-based routing | Simple, predictable, agents can set it |
| Review on completion | Catch issues before they compound |
| Epics as containers | Status derived from tasks, not stored |
| No approve/reject | Work is committed; if bad, add new tasks |

---

*See also: `fuel-orchestrator-v2-roadmap.md` for detailed phase plans*
