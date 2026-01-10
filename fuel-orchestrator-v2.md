# Fuel Orchestrator v2 - Design Exploration

## Current State (v1)

Static complexity-to-agent mapping:
```yaml
complexity:
  trivial: cursor-composer
  simple: claude-sonnet
  moderate: claude-opus
  complex: claude-opus
```

**Limitations:**
- Human must assess complexity upfront (often wrong)
- No dynamic routing based on task content
- No failover when agents fail
- Single repository focus
- JSONL storage doesn't scale well for querying

---

## Vision: Intelligent Agent Orchestration

### Core Concept

Replace static mapping with **primary agent decision-making**:

1. Primary agent reads task description + codebase context
2. Primary decides which agent is best suited (or breaks task down first)
3. Work distributes across available agents dynamically
4. Primary reviews completed work
5. System adapts to agent availability and failure patterns

---

## Key Design Questions

### 1. Should Primary Break Down Tasks?

**Option A: Primary as Task Decomposer**
```
User adds: "Implement user authentication"
Primary breaks into:
  - f-001: Design auth schema (blocked-by: none) → claude-opus
  - f-002: Implement JWT service (blocked-by: f-001) → claude-sonnet
  - f-003: Add login endpoint (blocked-by: f-002) → cursor-composer
  - f-004: Add registration endpoint (blocked-by: f-002) → cursor-composer
  - f-005: Write auth tests (blocked-by: f-003, f-004) → claude-sonnet
```

**Pros:**
- Enables true parallelism
- Cheaper agents handle simpler subtasks
- Primary maintains architectural vision
- Dependencies are explicit

**Cons:**
- Primary needs deep codebase understanding first
- Decomposition itself costs tokens
- May over-decompose simple tasks
- Harder to track "original intent"

**Option B: Primary as Router Only**
```
User adds: "Implement user authentication"
Primary routes entire task → claude-opus (complex enough to need it)
```

**Pros:**
- Simpler mental model
- Single agent owns entire feature
- Less coordination overhead

**Cons:**
- No parallelism within large tasks
- Expensive agents do everything
- No review step

**Option C: Hybrid - Decompose Above Threshold**
```
Primary assesses task complexity:
  - Simple: route directly
  - Complex: decompose first, then route subtasks
```

**Recommendation:** Option C with configurable threshold. Primary should have guidelines like:
- "If task touches >3 files, consider decomposition"
- "If task has multiple distinct outcomes, decompose"
- "If task can be parallelized, decompose"

---

### 2. How Should Primary Choose Agents?

**Current thinking:** Agent definitions include capability descriptions

```yaml
agents:
  cursor-composer:
    command: cursor-agent
    capabilities:
      - "Fast at simple refactors"
      - "Good at file operations"
      - "Limited context window (8k)"
      - "Cannot run tests"
    cost_per_1k_tokens: 0.001

  claude-opus:
    command: claude
    model: opus
    capabilities:
      - "Excellent at complex reasoning"
      - "Can hold large context (200k)"
      - "Good at architectural decisions"
      - "Expensive"
    cost_per_1k_tokens: 0.075

  opencode-deepseek:
    command: opencode
    model: deepseek-coder
    capabilities:
      - "Specialized for code generation"
      - "Very fast"
      - "Good at boilerplate"
      - "May miss edge cases"
    cost_per_1k_tokens: 0.0001
```

**Primary's decision prompt:**
```
Given these available agents and their capabilities:
[agent definitions]

And this task:
[task title + description]

Which agent should handle this? Consider:
1. Task complexity vs agent capability
2. Cost efficiency
3. Current agent load/availability
4. Recent failure patterns
```

**Alternative: Learning from Outcomes**

Track which agents succeed/fail at which task types:
```sql
SELECT agent, task_type,
       COUNT(*) as attempts,
       SUM(success) as successes,
       AVG(duration_seconds) as avg_time,
       AVG(cost_usd) as avg_cost
FROM runs
GROUP BY agent, task_type
```

Primary could use this data to inform routing decisions.

---

### 3. Live Config Reloading

**Concept:** Config changes take effect on next iteration without restart

```php
class ConsumeCommand {
    public function runLoop() {
        while (true) {
            // Reload config each iteration
            $this->configService->reload();

            $availableAgents = $this->configService->getAgentNames();

            // Don't assign work to removed agents
            // Existing processes continue until completion

            $this->processIteration($availableAgents);

            sleep($this->pollInterval);
        }
    }
}
```

**Use cases:**
- Add new agent without restart
- Remove failing agent immediately
- Adjust max_concurrent on the fly
- Swap API keys

**Considerations:**
- What happens to in-flight work for removed agents?
  - Option 1: Let it complete (current process finishes)
  - Option 2: Kill it (may leave task in bad state)
  - **Recommendation:** Let complete, don't assign new work

---

### 4. Agent Failure Handling & Circuit Breaker

**Pattern:** Track failures, implement exponential backoff per agent

```php
class AgentHealthTracker {
    private array $failures = [];  // agent => [timestamps]
    private array $backoffUntil = [];  // agent => timestamp

    public function recordFailure(string $agent): void {
        $this->failures[$agent][] = time();
        $this->pruneOldFailures($agent);

        $recentFailures = count($this->failures[$agent]);

        if ($recentFailures >= 3) {
            // Exponential backoff: 1min, 2min, 4min, 8min, max 30min
            $backoffMinutes = min(30, pow(2, $recentFailures - 3));
            $this->backoffUntil[$agent] = time() + ($backoffMinutes * 60);
        }
    }

    public function isAvailable(string $agent): bool {
        if (!isset($this->backoffUntil[$agent])) {
            return true;
        }
        return time() > $this->backoffUntil[$agent];
    }

    public function recordSuccess(string $agent): void {
        // Reset failure count on success
        unset($this->failures[$agent]);
        unset($this->backoffUntil[$agent]);
    }
}
```

**Failure types to track:**
- Exit code != 0
- Network errors (API unreachable)
- Rate limiting (429 responses)
- Timeout (task running too long)
- Permission blocked (needs human)

**Different handling per type:**
- Network error: Short backoff, likely transient
- Rate limiting: Longer backoff, respect API limits
- Repeated failures: Escalate to different agent or human

---

### 5. Multi-Repository Orchestration

**Big question:** Should Fuel work across repos?

#### Option A: Single Primary, Multiple Repos

```
fuel consume --repos=/path/to/repo1,/path/to/repo2,/path/to/repo3
```

```
┌─────────────────────────────────────────────────┐
│                 Primary Agent                    │
│  (Orchestrates across all repos)                │
└─────────────────────────────────────────────────┘
         │              │              │
    ┌────┴────┐    ┌────┴────┐    ┌────┴────┐
    │ Repo 1  │    │ Repo 2  │    │ Repo 3  │
    │ tasks   │    │ tasks   │    │ tasks   │
    └─────────┘    └─────────┘    └─────────┘
```

**Pros:**
- Single view of all work
- Can prioritize across projects
- Shared agent pool

**Cons:**
- Primary needs context of ALL codebases
- Cross-repo dependencies are complex
- Single point of failure
- Context window limitations

#### Option B: Primary Per Repo, Shared Agent Pool

```
┌──────────────────────────────────────────────────────────┐
│                    Agent Pool                             │
│  claude-opus(2)  claude-sonnet(4)  cursor(8)  opencode(6)│
└──────────────────────────────────────────────────────────┘
         │              │              │              │
    ┌────┴────┐    ┌────┴────┐    ┌────┴────┐    ┌────┴────┐
    │Primary 1│    │Primary 2│    │Primary 3│    │Primary 4│
    │ Repo 1  │    │ Repo 2  │    │ Repo 3  │    │ Repo 4  │
    └─────────┘    └─────────┘    └─────────┘    └─────────┘
```

**Pros:**
- Each primary deeply understands its repo
- Natural isolation
- Scales better
- Can still share expensive agents

**Cons:**
- No cross-repo task coordination
- Agent contention between repos
- Multiple processes to manage

#### Option C: Hierarchical - Meta-Primary + Repo Primaries

```
┌─────────────────────────────────────────────────┐
│              Meta-Primary Agent                  │
│  (High-level prioritization across repos)       │
└─────────────────────────────────────────────────┘
         │              │              │
    ┌────┴────┐    ┌────┴────┐    ┌────┴────┐
    │Primary 1│    │Primary 2│    │Primary 3│
    │ Repo 1  │    │ Repo 2  │    │ Repo 3  │
    └─────────┘    └─────────┘    └─────────┘
         │              │              │
    ┌────┴────┐    ┌────┴────┐    ┌────┴────┐
    │ Worker  │    │ Worker  │    │ Worker  │
    │ Agents  │    │ Agents  │    │ Agents  │
    └─────────┘    └─────────┘    └─────────┘
```

**Pros:**
- Best of both worlds
- Cross-repo prioritization possible
- Each repo has dedicated context
- Hierarchical review possible

**Cons:**
- Complex architecture
- More moving parts
- Higher coordination overhead

**Recommendation:** Start with Option B (Primary per repo, shared pool). It's simpler and provides most benefits. Can evolve to Option C if cross-repo coordination becomes necessary.

---

### 6. SQLite for Runs/Processes

**Current:** JSONL files
- `.fuel/tasks.jsonl`
- `.fuel/runs/<task-id>.jsonl`

**Problems with JSONL at scale:**
- No indexing (full scan for queries)
- No joins (can't easily query "all runs for open tasks")
- File locking issues with concurrent access
- Hard to aggregate statistics

**SQLite schema proposal:**

```sql
-- Core tables
CREATE TABLE tasks (
    id TEXT PRIMARY KEY,
    title TEXT NOT NULL,
    description TEXT,
    status TEXT DEFAULT 'open',
    priority INTEGER DEFAULT 2,
    complexity TEXT DEFAULT 'simple',
    labels TEXT,  -- JSON array
    blocked_by TEXT,  -- JSON array of task IDs
    created_at TEXT,
    updated_at TEXT,
    closed_at TEXT,
    reason TEXT,
    commit_hash TEXT,
    repo_path TEXT  -- For multi-repo support
);

CREATE TABLE runs (
    id TEXT PRIMARY KEY,
    task_id TEXT NOT NULL,
    agent TEXT NOT NULL,
    status TEXT DEFAULT 'running',  -- running, completed, failed
    exit_code INTEGER,
    started_at TEXT,
    completed_at TEXT,
    duration_seconds INTEGER,
    session_id TEXT,
    cost_usd REAL,
    tokens_in INTEGER,
    tokens_out INTEGER,
    error_type TEXT,  -- network, permission, timeout, etc
    output_summary TEXT,
    FOREIGN KEY (task_id) REFERENCES tasks(id)
);

CREATE TABLE agent_health (
    agent TEXT PRIMARY KEY,
    last_success_at TEXT,
    last_failure_at TEXT,
    failure_count INTEGER DEFAULT 0,
    backoff_until TEXT,
    total_runs INTEGER DEFAULT 0,
    total_successes INTEGER DEFAULT 0,
    avg_duration_seconds REAL,
    avg_cost_usd REAL
);

-- Indexes for common queries
CREATE INDEX idx_tasks_status ON tasks(status);
CREATE INDEX idx_tasks_repo ON tasks(repo_path);
CREATE INDEX idx_runs_task ON runs(task_id);
CREATE INDEX idx_runs_agent ON runs(agent);
CREATE INDEX idx_runs_status ON runs(status);
```

**Benefits:**
- Fast queries: "Show all failed runs in last hour"
- Aggregations: "Average cost per agent"
- Joins: "All open tasks with their latest run"
- Concurrent access handled by SQLite
- Single file, easy to backup/restore

**Migration path:**
1. Add SQLite support alongside JSONL
2. Migrate existing data
3. Deprecate JSONL
4. Remove JSONL support

---

### 7. Review Workflow

**Concept:** Primary agent reviews completed work before marking tasks done

```
┌────────────────────────────────────────────────────────────┐
│                    Task Lifecycle                          │
│                                                            │
│  open → assigned → in_progress → review → closed          │
│                         │           │                      │
│                         │           └─→ needs_work ──┐     │
│                         │                            │     │
│                         └────────────────────────────┘     │
└────────────────────────────────────────────────────────────┘
```

**Review criteria:**
- Does the code compile/lint?
- Do tests pass?
- Does the change match the task description?
- Are there obvious issues (security, performance)?
- Is the commit message appropriate?

**Review prompt for Primary:**
```
Task: {title}
Description: {description}

Agent {agent} completed this task with:
- Exit code: {exit_code}
- Duration: {duration}
- Changes: {git diff summary}

Please review:
1. Does the implementation match the requirements?
2. Are there any obvious issues?
3. Should this be approved, or does it need more work?

If needs work, create a follow-up task with specific issues.
```

**Lightweight vs Thorough Review:**
- Simple tasks: Just check exit code + tests pass
- Complex tasks: Full Primary review
- Configurable per complexity level

---

## Architecture Sketch

```
┌─────────────────────────────────────────────────────────────────┐
│                         Fuel CLI                                 │
│                                                                  │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────┐  │
│  │   Commands  │  │   Services  │  │        Storage          │  │
│  │             │  │             │  │                         │  │
│  │  consume    │  │  Primary    │──│  SQLite                 │  │
│  │  add        │  │  Agent      │  │   - tasks               │  │
│  │  ready      │  │  Service    │  │   - runs                │  │
│  │  ...        │  │             │  │   - agent_health        │  │
│  │             │  │  Process    │  │                         │  │
│  │             │  │  Manager    │  │  Config (YAML)          │  │
│  │             │  │             │  │   - agents              │  │
│  │             │  │  Agent      │  │   - primary             │  │
│  │             │  │  Health     │  │   - settings            │  │
│  │             │  │  Tracker    │  │                         │  │
│  └─────────────┘  └─────────────┘  └─────────────────────────┘  │
│                           │                                      │
│                           ▼                                      │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │                    Agent Pool                             │   │
│  │                                                           │   │
│  │   claude-opus ──┐                                         │   │
│  │   claude-sonnet ├──► Spawned as separate processes        │   │
│  │   cursor-agent ─┤    with TTY or headless mode            │   │
│  │   opencode ─────┘                                         │   │
│  │                                                           │   │
│  └──────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

---

## Open Questions for Discussion

1. **How smart should Primary be?**
   - Just routing? Or full decomposition + review?
   - How much context does it need about the codebase?

2. **What's the right granularity for tasks?**
   - Should Primary enforce a max size?
   - Auto-decompose if too large?

3. **How to handle shared state between agents?**
   - What if two agents modify the same file?
   - Git conflicts, merge strategies?

4. **Cost optimization:**
   - Should we track cost per task and optimize?
   - Budget limits per day/week?

5. **Human in the loop:**
   - When should work pause for human review?
   - How to surface "stuck" tasks?

6. **Multi-repo coordination:**
   - Cross-repo dependencies (e.g., shared library)?
   - Monorepo support?

---

## Next Steps

1. **Prototype Primary agent decision-making** - Can we prompt a model to make good routing decisions?
2. **Implement SQLite storage** - Cleaner foundation for everything else
3. **Add agent health tracking** - Failure detection + circuit breaker
4. **Test multi-agent scenarios** - What breaks with 5+ agents?
5. **Design review workflow** - How lightweight can it be while still useful?

---

## Research Links

- [Circuit Breaker Pattern](https://martinfowler.com/bliki/CircuitBreaker.html)
- [SQLite as application file format](https://www.sqlite.org/appfileformat.html)
- [Agent orchestration patterns](https://www.anthropic.com/research/building-effective-agents)
- [LangChain Agent Executor](https://python.langchain.com/docs/modules/agents/)
- [CrewAI multi-agent framework](https://github.com/joaomdmoura/crewAI)

---

*Document created: 2026-01-09*
*Status: Brainstorming - discuss tomorrow*
