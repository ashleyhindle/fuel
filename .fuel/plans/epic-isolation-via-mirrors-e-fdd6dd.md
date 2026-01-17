# Epic: Epic isolation via mirrors (e-fdd6dd)

## Plan

Isolate epics in separate directory mirrors so agents work without stepping on each other's toes. Each epic gets a copy-on-write clone of the project, tasks run in that mirror, and merge happens via git at epic completion.

### Goal

Currently, multiple epics and standalone tasks share the same working directory. This causes:
- Test failures from other agents' incomplete work
- Git state confusion during concurrent commits
- Agents wasting cycles fixing issues they didn't cause

This feature isolates each epic in its own directory clone, with shared database via symlink, and git-based merge at completion.

### Mirror Storage

Location: `~/.fuel/mirrors/{project-slug}/{epic-id}/`
- `project-slug` = `Str::slug(basename(realpath($projectPath)))` for human readability
- Predictable, manageable, survives reboots
- Symlink `.fuel/` back to original project's `.fuel/` (shared database)

### Database Schema Changes

Add columns to `epics` table:
```sql
ALTER TABLE epics ADD COLUMN mirror_path TEXT NULL;
ALTER TABLE epics ADD COLUMN mirror_status TEXT DEFAULT 'none';
ALTER TABLE epics ADD COLUMN mirror_branch TEXT NULL;
ALTER TABLE epics ADD COLUMN mirror_base_commit TEXT NULL;
ALTER TABLE epics ADD COLUMN mirror_created_at DATETIME NULL;
```

### New Enum: MirrorStatus

`app/Enums/MirrorStatus.php`:
- `None` - no mirror (standalone tasks, or mirror feature disabled)
- `Pending` - epic created, mirror creation queued
- `Creating` - mirror copy in progress
- `Ready` - mirror ready for work
- `Merging` - merge task in progress
- `MergeFailed` - merge failed, needs human attention
- `Merged` - successfully merged
- `Cleaned` - mirror directory removed

Helper methods:
- `isWorkable(): bool` - returns true for `Ready`
- `needsAttention(): bool` - returns true for `MergeFailed`

### New Service: ProcessSpawner

`app/Services/ProcessSpawner.php`:
- Centralized fire-and-forget background process spawning
- Mockable for tests (injected via container)
- Cross-platform: uses `nohup` with shell backgrounding
- Used by epic:add to spawn mirror creation

```php
class ProcessSpawner
{
    public function spawnBackground(string $command, array $args = []): void
    {
        $fullCommand = sprintf(
            'nohup %s %s %s > /dev/null 2>&1 &',
            PHP_BINARY,
            base_path('fuel'),
            implode(' ', array_map('escapeshellarg', array_merge([$command], $args)))
        );
        exec($fullCommand);
    }
}
```

### New Command: MirrorCreateCommand

`app/Commands/MirrorCreateCommand.php`:
- Signature: `mirror:create {epic}`
- Called as background process by epic:add
- Steps:
  1. Update epic mirror_status to 'creating'
  2. Create mirror directory: `~/.fuel/mirrors/{slug}/{epic-id}/`
  3. Copy project: `cp -cR` (macOS) or `cp --reflink=auto -R` (Linux)
  4. Remove `.fuel/` from mirror, symlink to original
  5. Create git branch: `git checkout -b epic/{epic-id}`
  6. Update epic: mirror_path, mirror_branch, mirror_status='ready', mirror_base_commit

Cross-platform copy:
```php
$cmd = PHP_OS_FAMILY === 'Darwin'
    ? sprintf('cp -cR %s %s', escapeshellarg($src), escapeshellarg($dst))
    : sprintf('cp --reflink=auto -R %s %s', escapeshellarg($src), escapeshellarg($dst));
```

### New Prompt: merge.md

`resources/prompts/merge.md`:
- Template for merge agent task
- Variables: `mirror_path`, `branch`, `epic_title`, `epic_id`, `project_path`, `quality_gates`
- Instructions for git fetch, merge, conflict resolution, quality gate verification
- Generic about test commands - pulls from reality.md quality gates or defaults

### New AgentTask: MergeEpicAgentTask

`app/Agents/Tasks/MergeEpicAgentTask.php`:
- Follows pattern of `UpdateRealityAgentTask`
- Static factory: `fromEpic(Epic $epic): self`
- `getCwd(): string` - override to return MAIN project path (not mirror)
- `buildPrompt()` - loads merge.md template, substitutes variables
- `getQualityGatesFromReality()` - parses quality gates table from reality.md
- `onSuccess()`:
  1. Remove mirror directory
  2. Mark epic mirror_status='cleaned'
  3. Mark task done
- `onFailure()`:
  1. Pause epic
  2. Set mirror_status='merge_failed'
  3. Surface in `fuel human`

### Modified: EpicAddCommand

`app/Commands/EpicAddCommand.php`:
- After creating epic, spawn mirror creation via ProcessSpawner
- Set mirror_status='pending' on new epic

### Modified: EpicService

`app/Services/EpicService.php`:
- Add `getProjectPath(): string` method (delegates to FuelContext)
- Add `setMirrorReady(Epic, path, branch, baseCommit)` method
- Add `updateMirrorStatus(Epic, MirrorStatus)` method
- Add `cleanupMirror(Epic)` method - rm -rf mirror path

### Modified: TaskSpawner

`app/Daemon/TaskSpawner.php`:
- When spawning task with epic:
  - Check epic.mirror_status
  - If `Ready` → cwd = epic.mirror_path
  - If `Pending`/`Creating` → skip task, try next (mirror not ready)
  - If `MergeFailed` → skip task (epic blocked)
- Standalone tasks (no epic): cwd = project directory (unchanged)

### Modified: Epic Model

`app/Models/Epic.php`:
- Add fillable: `mirror_path`, `mirror_status`, `mirror_branch`, `mirror_base_commit`, `mirror_created_at`
- Add cast: `mirror_status` => `MirrorStatus::class`
- Add helper: `hasMirror(): bool`
- Add helper: `isMirrorReady(): bool`

### Modified: HumanCommand

`app/Commands/HumanCommand.php`:
- Show orphaned/stale mirrors section
- Show epics with `mirror_status='merge_failed'`
- List mirrors for deleted/approved epics that weren't cleaned up

### Modified: EpicReviewedCommand

`app/Commands/EpicReviewedCommand.php` (or wherever epic:reviewed is):
- After verifying all tasks complete, create MergeEpicAgentTask
- Set epic mirror_status='merging'

### Modified: InitCommand

`app/Commands/InitCommand.php`:
- Add 'merge' to prompt names to copy
- Update PromptService::PROMPT_NAMES to include 'merge'

### Standalone Tasks During Merge

When any epic has `mirror_status='merging'`:
- Standalone tasks (no epic) are PAUSED
- Prevents git state conflicts during merge
- Other epic tasks continue in their own mirrors (isolated)

Check in TaskSpawner:
```php
if ($this->hasActiveMerge() && $task->epic_id === null) {
    // Skip standalone tasks during merge
    continue;
}
```

### File Summary

| File | Action |
|------|--------|
| `app/Enums/MirrorStatus.php` | New |
| `app/Services/ProcessSpawner.php` | New |
| `app/Commands/MirrorCreateCommand.php` | New |
| `app/Agents/Tasks/MergeEpicAgentTask.php` | New |
| `resources/prompts/merge.md` | New |
| `database/migrations/*_add_mirror_to_epics.php` | New |
| `app/Commands/EpicAddCommand.php` | Modify |
| `app/Services/EpicService.php` | Modify |
| `app/Services/PromptService.php` | Modify (add 'merge' to PROMPT_NAMES) |
| `app/Daemon/TaskSpawner.php` | Modify |
| `app/Models/Epic.php` | Modify |
| `app/Commands/HumanCommand.php` | Modify |
| `app/Commands/EpicReviewedCommand.php` | Modify |
| `app/Commands/InitCommand.php` | Modify |
| `tests/Unit/MirrorStatusTest.php` | New |
| `tests/Unit/ProcessSpawnerTest.php` | New |
| `tests/Feature/Commands/MirrorCreateCommandTest.php` | New |
| `tests/Unit/MergeEpicAgentTaskTest.php` | New |

### Edge Cases

1. **Mirror creation fails** (disk full, permissions):
   - Set mirror_status='failed' (add to enum if needed)
   - Surface in `fuel human`
   - Tasks blocked until resolved

2. **Epic abandoned** (never reviewed):
   - `fuel human` shows stale mirrors (7+ days untouched)
   - Manual cleanup via `rm -rf ~/.fuel/mirrors/{slug}/{epic-id}`

3. **Main branch diverges**:
   - Let merge task handle conflicts
   - Agent has context to resolve intelligently
   - Future: consider periodic rebase (out of scope for now)

4. **Multiple epics touch same files**:
   - First to merge wins
   - Second gets conflicts - merge agent resolves
   - This is acceptable - git handles it

### Testing Strategy

1. **Unit tests**:
   - MirrorStatus enum methods
   - ProcessSpawner (mock exec)
   - EpicService mirror methods

2. **Feature tests**:
   - MirrorCreateCommand - verify directory structure, symlinks, git branch
   - TaskSpawner routing - epic tasks go to mirror, standalone to project
   - Merge task creation on epic:reviewed

3. **Integration considerations**:
   - Cross-platform copy commands
   - Symlink creation
   - Git branch operations

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

### ✅ MirrorStatus Enum (f-f55a0d)
Created `app/Enums/MirrorStatus.php` following EpicStatus pattern:
- String-backed enum with 8 cases: None, Pending, Creating, Ready, Merging, MergeFailed, Merged, Cleaned
- Helper methods: `isWorkable()`, `needsAttention()`, `label()`
- Fully tested in `tests/Unit/MirrorStatusTest.php`
- Pattern: Use `match` expressions for status-based logic, exhaustive coverage

### ✅ ProcessSpawner Service (f-a58578)
Created `app/Services/ProcessSpawner.php` for fire-and-forget background process spawning:
- Single method: `spawnBackground(string $command, array $args = []): void`
- Uses `nohup` with shell backgrounding for cross-platform compatibility
- All output redirected to `/dev/null 2>&1 &`
- Registered as singleton in `AppServiceProvider`
- Fully tested in `tests/Unit/ProcessSpawnerTest.php` using mock subclass pattern
- **Mockability**: Tests extend ProcessSpawner and override the method to avoid actual exec calls
- **Usage**: `app(ProcessSpawner::class)->spawnBackground('mirror:create', ['e-abc123'])`

### ✅ Epic Mirrors Config Flag (f-7f3a16)
Added configuration flag to control epic mirrors feature in `app/Services/ConfigService.php`:
- New method: `getEpicMirrorsEnabled(): bool` - reads 'epic_mirrors' from config.yaml, defaults to false
- Updated `createDefaultConfig()` to include `epic_mirrors: false` with descriptive comment
- Fully tested in `tests/Unit/Services/ConfigServiceTest.php` with 4 test cases
- **Purpose**: Safe rollout flag for enabling/disabling mirror creation
- **Usage**: `app(ConfigService::class)->getEpicMirrorsEnabled()` in EpicAddCommand and TaskSpawner

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->

### ProcessSpawner Service
**File:** `app/Services/ProcessSpawner.php`

**Purpose:** Centralized, mockable service for spawning background processes

**Method:**
- `spawnBackground(string $command, array $args = []): void`
  - Spawns a detached background process using nohup
  - Command: The fuel command to execute (e.g., 'mirror:create')
  - Args: Command arguments (automatically escaped for shell)
  - Fire-and-forget: Process runs independently, no tracking

**Registration:** Singleton in `AppServiceProvider.php`

**Testing Pattern:** Extend class and override method to capture exec calls without running them

**Example:**
```php
app(ProcessSpawner::class)->spawnBackground('mirror:create', ['e-abc123']);
```

### MirrorStatus Enum
**File:** `app/Enums/MirrorStatus.php`

**Cases:**
- `None` - No mirror (standalone tasks, or feature disabled)
- `Pending` - Epic created, mirror creation queued
- `Creating` - Mirror copy in progress
- `Ready` - Mirror ready for work
- `Merging` - Merge task in progress
- `MergeFailed` - Merge failed, needs human attention
- `Merged` - Successfully merged
- `Cleaned` - Mirror directory removed

**Methods:**
- `isWorkable(): bool` - Returns true only for Ready status
- `needsAttention(): bool` - Returns true only for MergeFailed status
- `label(): string` - Human-readable label for display
