# Handoff: Fuel Orchestrator v2

## The Vision

We're transforming Fuel from a task tracker into an **intelligent orchestration system** where:
1. **Primary agent** makes routing decisions, reviews work, manages retries
2. **Epics** group related tasks for coherent feature delivery
3. **Human inbox** provides consolidated review of completed work
4. **TUI** gives rich interactive control

## Master Plan

**Read `fuel-orchestrator-v2-roadmap.md`** - This is the source of truth for all phases.

### Phase Overview
| Phase | Goal | Status |
|-------|------|--------|
| **Phase 1** | ProcessManager foundation | ✅ Complete |
| **Phase 2** | Agent Health & Retries + SQLite | Tasks created (f-5fc60a → f-2d6ed7 → f-c6cb42 → f-004613) |
| **Phase 3** | Primary Agent Routing | Not started |
| **Phase 4** | Auto-Review of Completed Work | Not started |
| **Phase 5** | Epics | Not started |
| **Phase 6** | Human Inbox | Not started |
| **Phase 7** | TUI for Consume | Not started |

### Is the Roadmap Up to Date?

**Yes, mostly.** Key decisions documented:
- Storage strategy: JSONL for tasks (git-native), SQLite for agent metrics (`.fuel/agent.db`)
- Phase 2 includes SQLite schema for `runs` and `agent_health` tables
- Epics go in JSONL (`.fuel/epics.jsonl`)

**Discovered but not in roadmap yet:**
- Output should stream to disk continuously (not just at completion) - backlog item `b-35135f`
- Need `--force` flag for `fuel remove` - backlog item `b-8eaf1e`
- Need `--backlog` alias for `--someday` - backlog item `b-967034`
- Add 'refactor' as valid task type - backlog item `b-402ee3`

---

## Phase 1 Complete ✅

Fixed the spawn() consolidation issue by making spawn() standalone without ConfigService dependency:
- spawn() now creates output directory, starts process, and tracks in activeAgentProcesses directly
- Updated unit tests to use full commands ('sleep 2' instead of '2')
- All 484 tests pass, including 18 ProcessManager tests

Committed: `a093619 fix: make spawn() standalone without ConfigService dependency`

---

## What's Next After Phase 1

### Phase 2: Agent Health & Retries

Create fuel tasks for:
1. **SQLite schema** - Create `.fuel/agent.db` with `runs` and `agent_health` tables (schema in roadmap)
2. **AgentHealthTracker service** - Record success/failure, calculate backoffs
3. **Retry logic** - Exponential backoff, max attempts
4. **Surface health in output** - Show "agent X is unhealthy"

### Key Design Decisions Made
- SQLite for agent data only (not tasks)
- Output files go to `.fuel/processes/{taskId}/` (we fixed path from `storage/` to `getcwd()`)
- Failure types: network, timeout, permission, crash

---

## Uncommitted Changes

```bash
git status  # Many files modified
```

Includes:
- Path fix: `storage_path()` → `getcwd()` in ProcessManager
- Directory creation in InitCommand and ConsumeCommand
- `declare(strict_types=1)` added to value objects
- Broken spawn() consolidation (needs fixing)
- Agent prompt improvements (task poaching prevention)

---

## Fuel Commands

```bash
./fuel board --once     # See task board
./fuel ready            # See available tasks
./fuel backlog          # See future ideas
./fuel show <id>        # Task details
./fuel consume          # Run orchestrator (after fixing tests!)
```

---

## Key Files

| File | Purpose |
|------|---------|
| `fuel-orchestrator-v2-roadmap.md` | Master plan - READ THIS |
| `app/Services/ProcessManager.php` | Process spawning/tracking (broken) |
| `app/Contracts/ProcessManagerInterface.php` | Interface to satisfy |
| `app/Commands/ConsumeCommand.php` | Main orchestration loop |
| `.fuel/config.yaml` | Agent definitions |

---

## Summary

1. Fix the 17 broken tests (spawn() consolidation issue)
2. Commit Phase 1 work
3. Create Phase 2 tasks from roadmap
4. Continue building toward the vision
