# Fuel Orchestrator v2 - Implementation Roadmap

*Created: 2026-01-10*
*Updated: 2026-01-10*
*Status: Active - Phases 1-4 complete, Phase 5 next*

---

## Vision Summary

Transform Fuel from a task tracker with basic agent spawning into an **intelligent orchestration system** where:

1. **Primary agent** makes routing decisions, reviews work, manages retries
2. **Epics** group related tasks for coherent feature delivery
3. **Human inbox** provides consolidated review of completed work
4. **TUI** gives rich interactive control over the system

---

## Storage Strategy

**SQLite** (`.fuel/agent.db`) - Primary data store for all structured data:
- Tasks (migrated from JSONL for faster queries, joins, reliable locking)
- Epics (cross-task grouping and status tracking)
- Reviews (review process tracking)
- Agent health metrics (failures, backoffs, success rates)
- Runs (agent run history) - *currently in JSONL, migration pending*

**JSONL** - Git-tracked, merge-friendly:
- Backlog only (`backlog.jsonl`) - rough ideas and future work

```
.fuel/
├── backlog.jsonl        # Git-tracked, merge-friendly
├── config.yaml          # Git-tracked, agent definitions
├── agent.db             # SQLite, .gitignore'd (tasks, epics, reviews, runs, health)
└── processes/           # Temp output files, .gitignore'd
    └── {taskId}/
        ├── stdout.log
        └── stderr.log
```

**Why SQLite for tasks:**
- Fast queries, joins, aggregations
- Reliable locking (no file lock complexity)
- Easy cross-entity queries (tasks ↔ epics ↔ reviews)
- Auto-migration handled on init

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

### Phase 1: Process Management Foundation ✅ COMPLETE
**Goal:** Robust subprocess handling before adding intelligence

- [x] `ProcessManager` service - spawn, track, kill agent processes
- [x] `Process` value object - represents a running/completed process
- [x] Capture stdout/stderr per process to files
- [x] Track exit codes, duration, basic success/failure
- [x] Graceful shutdown (SIGTERM handling, wait for processes)
- [x] Tests for all of the above

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

### Phase 2: Agent Health & Retries ✅ COMPLETE
**Goal:** Handle failures gracefully

- [x] SQLite schema for agent data (`.fuel/agent.db`)
- [x] `AgentHealthTracker` service
- [x] Record failures with types (network, timeout, permission, crash)
- [x] Exponential backoff per agent
- [ ] Retry logic with configurable max attempts *(partial - backoff implemented, retry count not enforced)*
- [ ] Surface "agent X is unhealthy" in output *(partial - data tracked, no CLI display yet)*

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

### Phase 3: Auto-Review of Completed Work ✅ COMPLETE
**Goal:** Quality gate before work is accepted

- [x] Capture git diff + git status per task on completion
- [x] Check: Are there uncommitted changes? Missing commits?
- [x] Check: Do tests pass? Any new test failures?
- [x] Check: Does the change match the task description?
- [x] Auto-create follow-up tasks if issues found
- [x] Task status flow: `in_progress` → `review` → `closed` (or `needs_work`)
- [x] Store last review issues on task for retry feedback
- [ ] Code duplication detection - deferred (low priority)

**Review checks implemented:**
1. **Uncommitted changes** - Did agent leave dirty working tree?
2. **Tests pass** - Run test suite, compare before/after
3. **Task match** - Does diff align with task intent?

---

### Phase 4: Epics ✅ COMPLETE
**Goal:** Group related tasks, track aggregate progress, enable delegation

- [x] Epic SQLite schema in `agent.db`
- [x] `fuel epic:add "Title"` → creates epic
- [x] `fuel add "..." --epic=e-xxxx` or `-e e-xxxx` → links task to epic
- [x] `fuel epics` → list all epics with status
- [x] `fuel epic:show e-xxxx` → shows epic details + linked tasks
- [x] Tasks get `epic_id` field
- [x] Epic status derived from task states (planning → in_progress → review_pending → reviewed)
- [x] `fuel epic:review e-xxxx` → shows commits, diffs, and stats for human review
- [x] `fuel epic:reviewed e-xxxx` → marks epic as human-reviewed
- [x] Include epic context in consume prompts for tasks with epic_id

**Completed epics:**
- e-666e54: Migrate tasks from JSONL to SQLite (9 tasks)
- e-ee402e: Add epic:review command (3 tasks)

---

### Phase 5: Human Inbox ⬅️ NEXT
**Goal:** Consolidated view for human review and messages

- [ ] Inbox table in SQLite (type, title, body, related_epic_id, created_at, read_at, actioned_at)
- [ ] Integrate with existing `fuel human` command
- [ ] Show epics ready for review (review_pending status)
- [ ] Show arbitrary messages (agents can post updates)
- [ ] `fuel human` → unified view of needs-human tasks + inbox
- [ ] `fuel inbox:approve e-xxxx` → marks epic approved
- [ ] `fuel inbox:reject e-xxxx "reason"` → creates follow-up task, epic back to in_progress

**Why now:** The payoff - human stays in the loop without micromanaging.

---

### Phase 6: TUI for Consume
**Goal:** Rich interactive interface

- [ ] Full TUI replacing current consume output
- [ ] Live task board (kanban style)
- [ ] Agent status panel (health, current task, cost)
- [ ] Human inbox integrated
- [ ] Click to view commits/diffs/rework from review agent
- [ ] Pause/resume, manual intervention
- [ ] `fuel` (no args) → launches TUI in paused mode

**Why last:** This is polish. The machinery has to work first.

---

### Phase 7: Primary Agent Routing (Deprioritized)
**Goal:** Intelligent task assignment instead of static mapping

- [ ] Primary agent prompt for task analysis
- [ ] Structured output: chosen agent + reasoning
- [ ] Fallback if primary's choice is unavailable/unhealthy
- [ ] Log routing decisions for learning

**Why deprioritized:** Complexity-based routing works well enough. Agents setting up tasks can set complexity. Most models are capable. Marginal gains vs epics/inbox.

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
| 2026-01-10 | Swap Phase 3↔4: Auto-Review before Routing | Agents often miss tests, forget commits, duplicate code - need quality gate before scaling |
| 2026-01-10 | Deprioritize Primary Routing (now Phase 7) | Complexity routing works fine. Agents can set complexity. Most models capable. Marginal gains vs epics. |
| 2026-01-10 | Epics in SQLite from start | Heading to SQLite anyway, bypass JSONL complexity. Easier cross-task queries. |
| 2026-01-10 | Human decomposition for now | Keep it simple. Use prompts/breakdown.md. Future: dedicated decomposition agent in config. |
| 2026-01-10 | Integrate inbox with fuel human | Don't fragment commands. One place for human attention needed. |
| 2026-01-10 | Tasks migrated to SQLite | Completed - eliminates file locking, enables joins with epics/reviews |
| 2026-01-10 | Runs migrated to SQLite | Completed - RunService now uses SQLite; enables run↔review linking |

### Run Scoring (Phase 5+)
- Score/rate task runs to track agent quality
- Aggregate stats by agent/model
- Inform routing decisions
- Backlog: b-c86743

### Extract Repositories from DatabaseService
- DatabaseService is ~1200 lines and growing
- Extract per-model repositories: TaskRepository, RunRepository, EpicRepository, ReviewRepository
- Each handles CRUD + queries for its entity
- DatabaseService becomes thin connection/migration manager
- Improves testability and separation of concerns

