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

### f-e15535: ReviewCommand migration
- ✅ Updated `app/Commands/ReviewCommand.php`:
  - Added `RunService` import at line 9
  - Added `RunService $runService` to `handle()` signature at line 30
  - Replaced `$task->commit_hash` with `$runService->getLatestCommitHash($task->short_id)` at line 70
  - Stored result in `$commitHash` variable, used throughout lines 70-91
  - Updated `displayTaskReview()` method signature to accept `?string $commitHash` parameter at line 122
  - Replaced references to `$task->commit_hash` with `$commitHash` in displayTaskReview at lines 158 and 166
- ✅ Updated `tests/Feature/Commands/ReviewCommandTest.php`:
  - Added `RunService` import
  - Updated tests to create runs with commit hashes via `createRun()` and `updateLatestRun()`
  - Pattern: `$runService->createRun($task->short_id, ['agent' => 'test-agent'])` followed by `$runService->updateLatestRun($task->short_id, ['commit_hash' => $commitHash])`
- All 9 ReviewCommand tests passing

### f-33e33c: ShowCommand migration
- ✅ Updated `app/Commands/ShowCommand.php`:
  - Added early retrieval of commit hash: `$commitHash = $runService->getLatestCommitHash($task->short_id)` at line 92
  - Replaced JSON output commit_hash (line 112): now uses `$commitHash` instead of `$task->commit_hash`
  - Updated hasCompletionInfo check (line 182): now uses `$commitHash` instead of `$task->commit_hash`
  - Updated Completion Info display (line 189): now uses `$commitHash` instead of `$task->commit_hash`
- ✅ Updated `tests/Feature/Commands/ShowCommandTest.php`:
  - Modified "shows commit hash when present" test to create run with commit hash
  - Modified "includes commit hash in JSON output when present" test to create run with commit hash
  - Both tests now use `RunService::logRun()` and `updateLatestRun()` to set commit on run
- Pattern: Get commit hash once early, reuse the variable throughout to avoid multiple queries
- All commit hash related ShowCommand tests passing

### f-7ad374: EpicShowCommand migration
- ✅ Updated `app/Commands/EpicShowCommand.php`:
  - `RunService` already imported and injected in handle() signature (line 31)
  - Replaced `$task->commit_hash ?? null` with `$runService->getLatestCommitHash($task->short_id)` at line 77
  - Change made in JSON output section within array_map closure
- ✅ No test updates required - no existing tests check commit_hash in EpicShowCommandTest.php
- ✅ Smoketest confirmed: `fuel epic:show e-99f74e --json` works correctly

### f-10b29c: EpicReviewCommand migration
- ✅ Updated `app/Commands/EpicReviewCommand.php`:
  - Removed fallback to `$task->commit_hash` at lines 175-176
  - Changed logic at lines 173-189 to only use commits from runs (via `$commits` array)
  - Now finds commit hash for each task by searching through `$commits` array (populated by `getCommitsFromRuns()` at line 437)
  - No longer reads `task.commit_hash` - only uses data from runs table
- Pattern: The commit display in the tasks table now exclusively uses commits retrieved from runs, maintaining consistency with the epic's migration goal

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
