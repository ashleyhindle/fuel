# Fuel Orchestrator v2 - Multi-Project & Worktrees Addendum

*Addendum to fuel-orchestrator-v2.md*

---

## The Multi-Repo Productivity Thesis

### Why This Matters for Human Impact

The biggest productivity multiplier isn't faster single-repo work—it's **eliminating the coordination tax** across repositories.

**Real-world scenarios:**

1. **Feature spanning frontend + backend + shared types**
   - Human today: Work on backend API → commit → switch to types repo → update → commit → switch to frontend → consume new types → commit
   - With multi-repo orchestration: "Add user preferences feature" → agents work all three repos in parallel, coordinated

2. **Library update across consumers**
   - Human today: Update library → publish → manually update 8 consumer repos one by one
   - With orchestration: "Bump auth-lib to v2.3 across all projects" → parallel PRs everywhere

3. **Infrastructure + application changes**
   - Human today: Update Terraform → wait for apply → update app config → deploy
   - With orchestration: Coordinated changes with dependency awareness

4. **Monorepo-like workflow without monorepo problems**
   - Keep repos separate (ownership, CI, deploy independence)
   - But orchestrate work as if they were one codebase

### The Coordination Tax

Studies on developer productivity consistently show:
- **Context switching** costs 15-25 minutes to regain focus
- **Cross-team coordination** is the #1 bottleneck in large orgs
- **Waiting for dependencies** creates idle time

Multi-repo orchestration attacks all three:
- Agents don't lose context when switching repos
- No human coordination needed for mechanical changes
- Parallel execution eliminates waiting

---

## Cross-Repository Dependency Patterns

### Pattern 1: Shared Library Consumer

```
┌─────────────────┐
│   shared-lib    │ (types, utilities, components)
└────────┬────────┘
         │ consumed by
    ┌────┴────┬────────┬────────┐
    ▼         ▼        ▼        ▼
┌───────┐ ┌───────┐ ┌───────┐ ┌───────┐
│ app-1 │ │ app-2 │ │ app-3 │ │ app-4 │
└───────┘ └───────┘ └───────┘ └───────┘
```

**Orchestration approach:**
1. Primary detects shared-lib change
2. Triggers update tasks in all consumers
3. Consumers can run in parallel (independent)
4. Each consumer: update dep → run tests → PR

### Pattern 2: API Provider/Consumer

```
┌─────────────────┐
│  backend-api    │ (provides endpoints)
└────────┬────────┘
         │ called by
    ┌────┴────┬────────┐
    ▼         ▼        ▼
┌───────┐ ┌───────┐ ┌───────┐
│web-app│ │mobile │ │ cli   │
└───────┘ └───────┘ └───────┘
```

**Orchestration approach:**
1. API change task creates schema/contract first
2. Consumers blocked until contract is stable
3. Once contract ready, consumers work in parallel
4. Integration tests span repos

### Pattern 3: Infrastructure + Application

```
┌─────────────────┐     ┌─────────────────┐
│   terraform     │────▶│   application   │
│   (infra)       │     │   (app config)  │
└─────────────────┘     └─────────────────┘
```

**Orchestration approach:**
1. Infra changes must apply before app changes
2. Sequential dependency, but within each repo work can parallelize
3. Rollback coordination if either fails

### Pattern 4: Microservices Mesh

```
┌─────────┐     ┌─────────┐     ┌─────────┐
│service-a│◄───▶│service-b│◄───▶│service-c│
└─────────┘     └─────────┘     └─────────┘
     ▲               ▲               ▲
     └───────────────┴───────────────┘
              shared contracts
```

**Orchestration approach:**
- Contract-first: schema repo defines interfaces
- Services implement against contracts
- Breaking changes require coordinated rollout

---

## Multi-Repo Architecture Options (Deeper Dive)

### Option A: Workspace-Based Orchestration

**Concept:** Define a "workspace" that groups related repos

```yaml
# ~/.fuel/workspaces/my-platform.yaml
workspace: my-platform

repositories:
  - path: ~/code/platform-api
    role: backend
    primary_agent: claude-opus

  - path: ~/code/platform-web
    role: frontend
    primary_agent: claude-sonnet

  - path: ~/code/platform-mobile
    role: mobile
    primary_agent: claude-sonnet

  - path: ~/code/shared-types
    role: library
    primary_agent: claude-opus
    consumers: [platform-api, platform-web, platform-mobile]

cross_repo_rules:
  - when: shared-types changes
    then: update all consumers

  - when: platform-api adds endpoint
    then: notify frontend and mobile

agents:
  # Shared pool across workspace
  claude-opus:
    max_concurrent: 2  # Expensive, limit it
  claude-sonnet:
    max_concurrent: 6
  cursor-agent:
    max_concurrent: 10
```

**How it works:**
1. `fuel consume --workspace=my-platform`
2. Single process manages all repos
3. Tasks can have cross-repo dependencies
4. Shared agent pool with workspace-level limits

**Task format with cross-repo support:**
```json
{
  "id": "f-abc123",
  "repo": "platform-api",
  "title": "Add /users/preferences endpoint",
  "cross_repo_tasks": [
    {
      "repo": "shared-types",
      "title": "Add UserPreferences type",
      "must_complete_first": true
    },
    {
      "repo": "platform-web",
      "title": "Add preferences UI",
      "blocked_by": ["f-abc123"]
    }
  ]
}
```

### Option B: Federation Model

**Concept:** Each repo has its own Fuel instance, they communicate

```
┌─────────────────────────────────────────────────────────────┐
│                    Fuel Federation Hub                       │
│              (Lightweight coordinator service)               │
└─────────────────────────────────────────────────────────────┘
        │              │              │              │
   ┌────┴────┐    ┌────┴────┐    ┌────┴────┐    ┌────┴────┐
   │ Fuel    │    │ Fuel    │    │ Fuel    │    │ Fuel    │
   │ repo-1  │    │ repo-2  │    │ repo-3  │    │ repo-4  │
   └─────────┘    └─────────┘    └─────────┘    └─────────┘
```

**Communication:**
- Hub tracks cross-repo dependencies
- Repos publish "events" (task completed, API changed)
- Hub routes events to dependent repos
- Each repo's Primary decides how to respond

**Pros:**
- Decentralized, resilient
- Each repo is independently deployable
- Natural team boundaries
- Can work offline (queue events)

**Cons:**
- More infrastructure (need the hub)
- Eventual consistency issues
- Harder to reason about state

### Option C: Git-Based Coordination (No Central Hub)

**Concept:** Use git repos themselves for coordination

```
# Special repo: cross-repo-tasks
cross-repo-tasks/
  └── tasks/
      ├── add-preferences-feature.yaml
      └── upgrade-auth-lib.yaml
```

```yaml
# add-preferences-feature.yaml
feature: user-preferences
status: in_progress

tasks:
  - repo: git@github.com:org/shared-types
    task: Add UserPreferences type
    status: completed
    commit: abc123

  - repo: git@github.com:org/platform-api
    task: Add /users/preferences endpoint
    status: in_progress
    depends_on: [shared-types]

  - repo: git@github.com:org/platform-web
    task: Add preferences UI
    status: pending
    depends_on: [platform-api]
```

**Each Fuel instance:**
1. Watches the coordination repo
2. Pulls tasks relevant to its repo
3. Updates status when complete
4. Pushes status back

**Pros:**
- No extra infrastructure
- Git provides history, audit trail
- Works with existing git workflows
- Natural for teams already using git

**Cons:**
- Polling-based (not real-time)
- Git conflicts on status updates
- More complex git operations

---

## Git Worktrees: Parallel Work on Same Repo

### What Are Worktrees?

Git worktrees allow multiple working directories from the same repository:

```bash
# Main checkout
~/code/my-repo/  (on branch: main)

# Create worktrees for parallel work
git worktree add ../my-repo-feature-a feature-a
git worktree add ../my-repo-feature-b feature-b
git worktree add ../my-repo-bugfix bugfix-123

# Now you have:
~/code/my-repo/           (main)
~/code/my-repo-feature-a/ (feature-a)
~/code/my-repo-feature-b/ (feature-b)
~/code/my-repo-bugfix/    (bugfix-123)
```

**Key properties:**
- All share the same `.git` directory (in the main worktree)
- Each can be on a different branch
- Changes in one don't affect others (until merged)
- Lightweight: only working files are duplicated, not git history

### Why Worktrees Matter for Fuel

**Current limitation:** One agent per repo at a time (they'd conflict)

**With worktrees:** Multiple agents can work on same repo simultaneously

```
┌─────────────────────────────────────────────────────────────┐
│                      my-repo (git)                          │
│                                                             │
│  ┌───────────────┐  ┌───────────────┐  ┌───────────────┐   │
│  │ worktree-1    │  │ worktree-2    │  │ worktree-3    │   │
│  │ branch: feat-a│  │ branch: feat-b│  │ branch: fix-1 │   │
│  │               │  │               │  │               │   │
│  │ Agent: opus   │  │ Agent: sonnet │  │ Agent: cursor │   │
│  │ Task: f-001   │  │ Task: f-002   │  │ Task: f-003   │   │
│  └───────────────┘  └───────────────┘  └───────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

### Worktree Challenges & Solutions

#### Challenge 1: Shared Git State

**Problem:** All worktrees share:
- `.git/index` (staging area) - Actually NO, each worktree has its own index
- `.git/HEAD` - Each worktree has its own HEAD
- `.git/refs` - Shared, but branches are independent
- `.git/objects` - Shared (good, saves space)
- `.git/hooks` - Shared (important!)

**Actually less problematic than expected.** Git designed worktrees for exactly this use case.

**Real issue:** Pre-commit hooks run in each worktree but may assume single-worktree setup.

**Solution:** Fuel-managed hooks that are worktree-aware:
```bash
#!/bin/bash
# .git/hooks/pre-commit (worktree-aware)
WORKTREE_ROOT=$(git rev-parse --show-toplevel)
# Run linting only on files in THIS worktree
cd "$WORKTREE_ROOT" && npm run lint
```

#### Challenge 2: Branch Conflicts

**Problem:** Two agents might try to work on same branch

**Solution:** Fuel manages branch assignment
```php
class WorktreeManager {
    public function acquireBranch(string $repo, string $branch): ?string {
        // Check if branch is already checked out in any worktree
        $worktrees = $this->listWorktrees($repo);
        foreach ($worktrees as $wt) {
            if ($wt['branch'] === $branch) {
                return null;  // Branch in use
            }
        }

        // Create or reuse worktree for this branch
        return $this->getOrCreateWorktree($repo, $branch);
    }
}
```

#### Challenge 3: Worktree Cleanup

**Problem:** Completed tasks leave worktrees around

**Solution:** Lifecycle management
```php
class WorktreeManager {
    public function onTaskComplete(string $worktreePath, string $taskId): void {
        // Merge or PR the branch
        $this->createPullRequest($worktreePath);

        // Clean up worktree after PR merged (or immediately if configured)
        if ($this->config['cleanup_on_complete']) {
            $this->removeWorktree($worktreePath);
        } else {
            $this->markForCleanup($worktreePath, $taskId);
        }
    }

    public function cleanupStaleWorktrees(): void {
        // Run periodically
        // Remove worktrees for merged/closed branches
    }
}
```

#### Challenge 4: Resource Limits

**Problem:** Too many worktrees = disk space + inode exhaustion

**Solution:** Pool with limits
```yaml
worktrees:
  max_per_repo: 5
  cleanup_after_hours: 24
  reuse_for_same_complexity: true  # Don't create new if existing worktree idle
```

#### Challenge 5: IDE/Tool Integration

**Problem:** Many tools assume single working directory

**Solutions:**
- Run each agent in isolated environment (its worktree is its cwd)
- Ensure `.fuel/` is per-worktree or shared intelligently
- LSP servers: one per worktree (isolated)
- Build caches: can share if careful

### Worktree-Aware Task Execution

```php
class ConsumeCommand {
    public function spawnTask(array $task): void {
        $repo = $task['repo'] ?? $this->defaultRepo;

        // Get or create worktree for this task's branch
        $branch = $this->determineBranch($task);
        $worktreePath = $this->worktreeManager->acquireBranch($repo, $branch);

        if ($worktreePath === null) {
            // Branch in use, queue task for later
            $this->queueForBranch($task, $branch);
            return;
        }

        // Spawn agent in worktree directory
        $this->processManager->spawn(
            task: $task,
            cwd: $worktreePath,
            // ...
        );
    }
}
```

### Worktree Strategy by Task Type

| Task Type | Branch Strategy | Worktree Strategy |
|-----------|-----------------|-------------------|
| Bug fix | `fix/{task-id}` | New worktree, cleanup on merge |
| Feature | `feat/{feature-name}` | Persistent worktree until feature complete |
| Refactor | `refactor/{scope}` | New worktree, may be long-lived |
| Chore | `chore/{task-id}` | Reuse idle worktree |
| Dependent tasks | Same branch | Same worktree (sequential) |

---

## Proposed Multi-Repo + Worktree Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                         Fuel Workspace                               │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐ │
│  │                    Workspace Primary                            │ │
│  │  - Understands all repos at high level                         │ │
│  │  - Decomposes cross-repo features                              │ │
│  │  - Manages cross-repo dependencies                             │ │
│  │  - Reviews completed cross-repo work                           │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                              │                                       │
│         ┌────────────────────┼────────────────────┐                 │
│         ▼                    ▼                    ▼                 │
│  ┌─────────────┐      ┌─────────────┐      ┌─────────────┐         │
│  │  Repo: api  │      │  Repo: web  │      │ Repo: types │         │
│  │             │      │             │      │             │         │
│  │ Worktrees:  │      │ Worktrees:  │      │ Worktrees:  │         │
│  │  ├─ main    │      │  ├─ main    │      │  ├─ main    │         │
│  │  ├─ feat-a  │      │  ├─ feat-a  │      │  └─ feat-a  │         │
│  │  └─ fix-123 │      │  └─ feat-b  │      │             │         │
│  │             │      │             │      │             │         │
│  │ Tasks: 3    │      │ Tasks: 2    │      │ Tasks: 1    │         │
│  └─────────────┘      └─────────────┘      └─────────────┘         │
│         │                    │                    │                 │
│         └────────────────────┴────────────────────┘                 │
│                              │                                       │
│                              ▼                                       │
│  ┌────────────────────────────────────────────────────────────────┐ │
│  │                      Agent Pool                                 │ │
│  │                                                                 │ │
│  │   claude-opus ───────┐                                          │ │
│  │   claude-sonnet ─────┼──► Assigned to worktrees dynamically     │ │
│  │   cursor-agent ──────┤                                          │ │
│  │   opencode ──────────┘                                          │ │
│  │                                                                 │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐ │
│  │                      SQLite Storage                             │ │
│  │   - workspace.db (cross-repo state)                            │ │
│  │   - Per-repo: .fuel/fuel.db (repo-specific)                    │ │
│  └────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
```

---

## SQLite Schema Additions for Multi-Repo

```sql
-- Workspace-level database (workspace.db)

CREATE TABLE repositories (
    id TEXT PRIMARY KEY,
    path TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    role TEXT,  -- backend, frontend, library, infra
    primary_agent TEXT,
    last_sync_at TEXT
);

CREATE TABLE cross_repo_features (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT,
    status TEXT DEFAULT 'planning',  -- planning, in_progress, review, completed
    created_at TEXT,
    completed_at TEXT
);

CREATE TABLE cross_repo_tasks (
    id TEXT PRIMARY KEY,
    feature_id TEXT,
    repo_id TEXT NOT NULL,
    local_task_id TEXT,  -- ID in repo's fuel.db
    sequence_order INTEGER,  -- For ordered execution
    status TEXT DEFAULT 'pending',
    depends_on TEXT,  -- JSON array of cross_repo_task IDs
    FOREIGN KEY (feature_id) REFERENCES cross_repo_features(id),
    FOREIGN KEY (repo_id) REFERENCES repositories(id)
);

CREATE TABLE worktrees (
    id TEXT PRIMARY KEY,
    repo_id TEXT NOT NULL,
    path TEXT NOT NULL,
    branch TEXT NOT NULL,
    created_at TEXT,
    last_used_at TEXT,
    current_task_id TEXT,
    status TEXT DEFAULT 'idle',  -- idle, in_use, cleanup_pending
    FOREIGN KEY (repo_id) REFERENCES repositories(id)
);
```

---

## Implementation Phases

### Phase 1: Single-Repo Worktree Support
- Add WorktreeManager service
- Spawn agents in worktrees instead of main checkout
- Basic cleanup on task completion
- Test with 3-4 parallel tasks on same repo

### Phase 2: Workspace Definition
- Workspace YAML config format
- Multi-repo task storage
- Cross-repo dependency tracking (manual)
- Single `fuel consume --workspace` command

### Phase 3: Workspace Primary
- Primary agent with workspace context
- Cross-repo feature decomposition
- Automated dependency detection
- Cross-repo review workflow

### Phase 4: Advanced Orchestration
- Dynamic agent routing across repos
- Cost optimization across workspace
- Learning from outcomes
- Federation support (multiple workspaces)

---

## Risk Analysis

| Risk | Impact | Mitigation |
|------|--------|------------|
| Git conflicts between worktrees | Medium | Branch isolation, merge queuing |
| Disk space exhaustion | High | Worktree limits, aggressive cleanup |
| Cross-repo deadlocks | Medium | Dependency cycle detection |
| Stale worktrees accumulate | Low | Periodic cleanup job |
| Primary context overload | High | Per-repo summaries, not full context |
| Network partition (multi-machine) | Medium | Local-first, sync when connected |

---

## Key Insight

The power of multi-repo orchestration isn't just parallelism—it's **treating distributed systems as a single coherent codebase** while maintaining the operational benefits of separation.

Humans shouldn't have to be the glue between repositories. That's exactly the kind of mechanical coordination work that agents can excel at.

---

*Addendum created: 2026-01-09*
*Status: Brainstorming - discuss tomorrow*
