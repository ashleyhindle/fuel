# Fuel Orchestrator v2 - Implementation Roadmap

*Created: 2026-01-10*
*Status: Active - Phase 1 in progress*

---

## Vision Summary

Transform Fuel from a task tracker with basic agent spawning into an **intelligent orchestration system** where:

1. **Primary agent** makes routing decisions, reviews work, manages retries
2. **Epics** group related tasks for coherent feature delivery
3. **Human inbox** provides consolidated review of completed work
4. **TUI** gives rich interactive control over the system

---

## Storage Strategy

**Tasks stay in JSONL** - Git-native, merge-friendly, human-readable. This is core to Fuel's design.

**Agent data goes in SQLite** (`.fuel/agent.db`) - Transient operational data that doesn't need to be committed:
- Process tracking (running/completed processes)
- Agent health metrics (failures, backoffs, success rates)
- Run history (durations, costs, exit codes)
- Routing decisions (for learning/analytics)

```
.fuel/
├── tasks.jsonl          # Git-tracked, merge-friendly
├── backlog.jsonl        # Git-tracked, merge-friendly
├── config.yaml          # Git-tracked
├── agent.db             # SQLite, .gitignore'd
└── processes/           # Temp output files, .gitignore'd
    └── {taskId}/
        ├── stdout.log
        └── stderr.log
```

**Why this split:**
- Tasks are the "source of truth" that humans and agents collaborate on → git
- Agent metrics are operational telemetry → SQLite for fast queries
- Process output is ephemeral debugging info → files, cleaned up periodically

---

## New Concepts (Beyond v2 Design Docs)

### Epics

A grouping of related tasks that together deliver a coherent outcome.

```
Epic: "Add user preferences"
├── Status: review_pending (all tasks done, awaiting human)
├── Tasks: f-001 (closed), f-002 (closed), f-003 (closed)
├── Agents used: claude-opus, claude-sonnet, cursor
├── Total cost: $0.47
├── Combined diff: +342 / -28 lines
└── Primary's summary: "Added preferences API, UI, and tests. All passing."
```

**Epic lifecycle:**
- `planning` → Primary decomposing into tasks
- `in_progress` → Tasks being worked
- `review_pending` → All tasks done, awaiting human
- `approved` → Human signed off
- `changes_requested` → Human requested fixes, back to in_progress

### Human Inbox

A review queue where completed epics land for human sign-off.

```
┌─────────────────────────────────────────────────────────────┐
│                     Human Inbox                              │
│                                                              │
│  📬 Ready for Review (2)                                     │
│  ├── [E-001] Add user preferences (5 tasks, $0.47)          │
│  │   └── "View diff" | "Approve" | "Request changes"        │
│  └── [E-002] Fix auth bug (1 task, $0.02)                   │
│      └── "View diff" | "Approve" | "Request changes"        │
│                                                              │
│  ✅ Approved Today (3)                                       │
│  🔄 Changes Requested (1)                                    │
└─────────────────────────────────────────────────────────────┘
```

**"Request changes"** creates a follow-up task linked to the epic, epic goes back to `in_progress`.

---

## Implementation Phases

Each phase is a shippable increment. Earlier phases are prerequisites for later ones.

### Phase 1: Process Management Foundation ⬅️ CURRENT
**Goal:** Robust subprocess handling before adding intelligence

- [ ] `ProcessManager` service - spawn, track, kill agent processes
- [ ] `Process` value object - represents a running/completed process
- [ ] Capture stdout/stderr per process to files
- [ ] Track exit codes, duration, basic success/failure
- [ ] Graceful shutdown (SIGTERM handling, wait for processes)
- [ ] Tests for all of the above

**Why first:** Everything else depends on reliable process management. Current consume is fragile.

**Key interfaces:**
```php
interface ProcessManagerInterface {
    public function spawn(string $taskId, string $agent, string $command, string $cwd): Process;
    public function isRunning(string $taskId): bool;
    public function kill(string $taskId): void;
    public function getOutput(string $taskId): ProcessOutput;
    public function getRunningCount(): int;
    public function waitForAny(int $timeoutMs): ?ProcessResult;
    public function shutdown(): void;
}
```

---

### Phase 2: Agent Health & Retries
**Goal:** Handle failures gracefully

- [ ] SQLite schema for agent data (`.fuel/agent.db`)
- [ ] `AgentHealthTracker` service
- [ ] Record failures with types (network, timeout, permission, crash)
- [ ] Exponential backoff per agent
- [ ] Retry logic with configurable max attempts
- [ ] Surface "agent X is unhealthy" in output

**Why now:** Can't trust agents to succeed; need resilience before scaling up.

**SQLite schema:**
```sql
CREATE TABLE runs (
    id TEXT PRIMARY KEY,
    task_id TEXT NOT NULL,
    agent TEXT NOT NULL,
    status TEXT DEFAULT 'running',  -- running, completed, failed, killed
    exit_code INTEGER,
    started_at TEXT,
    completed_at TEXT,
    duration_seconds INTEGER,
    session_id TEXT,
    error_type TEXT  -- network, permission, timeout, crash
);

CREATE TABLE agent_health (
    agent TEXT PRIMARY KEY,
    last_success_at TEXT,
    last_failure_at TEXT,
    consecutive_failures INTEGER DEFAULT 0,
    backoff_until TEXT,
    total_runs INTEGER DEFAULT 0,
    total_successes INTEGER DEFAULT 0
);

CREATE INDEX idx_runs_task ON runs(task_id);
CREATE INDEX idx_runs_agent ON runs(agent);
```

**Key interfaces:**
```php
interface AgentHealthTrackerInterface {
    public function recordSuccess(string $agent): void;
    public function recordFailure(string $agent, FailureType $type): void;
    public function isAvailable(string $agent): bool;
    public function getBackoffSeconds(string $agent): int;
    public function getHealthStatus(string $agent): AgentHealth;
}
```

---

### Phase 3: Primary Agent Routing
**Goal:** Intelligent task assignment instead of static mapping

- [ ] Primary agent prompt for task analysis
- [ ] Structured output: chosen agent + reasoning
- [ ] Fallback if primary's choice is unavailable/unhealthy
- [ ] Log routing decisions for learning

**Why now:** This is the core intelligence. Makes agent pool actually useful.

---

### Phase 4: Auto-Review of Completed Work
**Goal:** Primary reviews what agents produced

- [ ] Capture git diff per task on completion
- [ ] Primary reviews: does output match intent? Tests pass?
- [ ] Auto-create follow-up tasks if issues found
- [ ] Task status: `completed` → `review` → `closed` (or `needs_work`)

**Why now:** Without review, garbage can slip through. This is the quality gate.

---

### Phase 5: Epics
**Goal:** Group related tasks, track aggregate progress

- [ ] Epic data structure (JSONL - `.fuel/epics.jsonl`)
- [ ] `fuel epic:create "Title"` → creates epic
- [ ] `fuel add "..." --epic=e-xxxx` → links task to epic
- [ ] `fuel epic:status` → shows progress per epic
- [ ] Primary can auto-create epics when decomposing large tasks

**Why now:** Need grouping before human inbox makes sense.

**Data structure:**
```php
class Epic {
    public string $id;           // e-xxxxxx
    public string $title;
    public ?string $description;
    public EpicStatus $status;   // planning, in_progress, review_pending, approved, changes_requested
    public array $taskIds;       // linked task IDs
    public ?string $createdAt;
    public ?string $completedAt;
    public ?string $approvedAt;
    public ?string $approvedBy;  // human identifier
}
```

---

### Phase 6: Human Inbox
**Goal:** Humans review completed epics

- [ ] Epic status transitions for review workflow
- [ ] `fuel inbox` → shows epics awaiting review
- [ ] `fuel inbox:review e-xxxx` → shows consolidated diff, summary
- [ ] `fuel inbox:approve e-xxxx` → marks approved
- [ ] `fuel inbox:reject e-xxxx "reason"` → creates follow-up task

**Why now:** The payoff - human stays in the loop without micromanaging.

---

### Phase 7: TUI for Consume
**Goal:** Rich interactive interface

- [ ] Full TUI replacing current consume output
- [ ] Live task board (kanban style)
- [ ] Agent status panel (health, current task, cost)
- [ ] Human inbox integrated
- [ ] Pause/resume, manual intervention
- [ ] `fuel` (no args) → launches TUI in paused mode
- [ ] Deprecate/remove standalone `board` command

**Why last:** This is polish. The machinery has to work first.

---

## Bootstrap Strategy

Phases 1-2 must be built manually (current consume is too fragile).

Once Phase 3-4 are done, Fuel can build itself:
1. Add remaining phase tasks to Fuel
2. `fuel consume` with basic orchestration
3. Primary routes tasks to agents
4. Auto-review catches issues
5. Human reviews epics
6. Iterate

---

## Related Documents

- `fuel-orchestrator-v2.md` - Core design exploration
- `fuel-orchestrator-v2-multiproject.md` - Multi-repo and worktree concepts
- `FUEL-PLAN-ORIGINAL.md` - Original design spec (historical)

---

## Decision Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-01-10 | Phase 1 first (ProcessManager) | Everything depends on reliable process handling |
| 2026-01-10 | Epics before Human Inbox | Need grouping concept before review makes sense |
| 2026-01-10 | TUI last | Polish after machinery works |
| 2026-01-10 | SQLite for agent data only | Tasks/epics are collaborative (git), agent metrics are operational (SQLite) |
