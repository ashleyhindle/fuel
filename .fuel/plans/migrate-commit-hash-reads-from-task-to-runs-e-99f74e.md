# Epic: Migrate commit_hash reads from task to runs (e-99f74e)

## Plan

All places reading `task.commit_hash` should query `runs.commit_hash` instead. Keep writing to `task.commit_hash` but stop reading it - it becomes write-only "last commit" field.

### Approach

1. **Add helper method to RunService** - `getLatestCommitHash(string $taskId): ?string` that returns the most recent non-null commit_hash from runs for a task
2. **Update each command** to use `RunService::getLatestCommitHash()` instead of `task->commit_hash`

### Files to Modify

| File | Lines | Change |
|------|-------|--------|
| `app/Services/RunService.php` | new | Add `getLatestCommitHash($taskId)` method |
| `app/Commands/ReviewCommand.php` | 69-82 | Replace `$task->commit_hash` with `$runService->getLatestCommitHash()` |
| `app/Commands/ShowCommand.php` | 109, 181-188 | Replace `$task->commit_hash` with `$runService->getLatestCommitHash()` |
| `app/Commands/EpicShowCommand.php` | 77 | Replace `$task->commit_hash` with commit from runs |
| `app/Commands/EpicReviewCommand.php` | 175-176 | Remove task.commit_hash fallback (already queries runs at line 437) |
| `app/Commands/DoneCommand.php` | 103-104 | Replace display of `$task->commit_hash` with `$runService->getLatestCommitHash()` |

### RunService Method

```php
/**
 * Get the latest commit hash for a task from its runs.
 */
public function getLatestCommitHash(string $taskId): ?string
{
    $taskIntId = $this->resolveTaskId($taskId);
    if ($taskIntId === null) {
        return null;
    }

    return Run::where('task_id', $taskIntId)
        ->whereNotNull('commit_hash')
        ->where('commit_hash', '!=', '')
        ->latest('id')
        ->value('commit_hash');
}
```

### Testing

- Add unit test for `RunService::getLatestCommitHash()` in `tests/Unit/Services/RunServiceTest.php`
- Update existing tests in `tests/Feature/Commands/ReviewCommandTest.php` to set commit on run instead of task

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

### f-0188ea: RunService::getLatestCommitHash() method
- ✅ Added `getLatestCommitHash(string $taskId): ?string` method to `app/Services/RunService.php` at line 497
- ✅ Method added after `getTaskCost()` as specified in task description
- ✅ Returns latest commit hash from runs, or null if no commits/invalid task
- ✅ Uses `resolveTaskId()` to convert short_id to internal ID (consistent with other methods)
- ✅ Filters out null and empty string commit hashes
- ✅ Orders by `id` (using `latest('id')`) to get most recent run
- ✅ Added comprehensive unit tests in `tests/Unit/Services/RunServiceTest.php`:
  - Returns latest commit hash when multiple runs exist
  - Returns null when no commits exist for task
  - Returns null when task doesn't exist
  - Skips empty commit hashes and returns last valid one
- All 44 RunService tests passing

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->

### RunService::getLatestCommitHash()
**File:** `app/Services/RunService.php:497`

**Signature:**
```php
public function getLatestCommitHash(string $taskId): ?string
```

**Purpose:** Get the latest commit hash for a task from its runs table. This is the primary interface for reading commit hashes going forward (replacing direct reads of `task.commit_hash`).

**Returns:** Latest non-empty commit hash, or null if task not found or no commits exist.

**Usage example:**
```php
$runService = app(RunService::class);
$commitHash = $runService->getLatestCommitHash('f-abc123');
if ($commitHash !== null) {
    // Use commit hash
}
```
