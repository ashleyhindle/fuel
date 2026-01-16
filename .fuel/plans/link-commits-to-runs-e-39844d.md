# Epic: Link Commits to Runs (e-39844d)

## Problem
Currently `commit_hash` is stored only on the Task model. For selfguided tasks that loop through multiple iterations, each iteration may produce commits, but only the final commit (from `fuel done --commit`) is recorded. This loses valuable history about which commits were made in which run.

## Goal
Track `commit_hash` per **run** so that each iteration of a selfguided task can record its commit. This enables:
- Full commit history across selfguided iterations
- Ability to correlate runs with their commits
- Better auditability of what was done in each execution

## Approach

### 1. Database: Add `commit_hash` to runs table ✅
- ✅ Create migration adding `commit_hash VARCHAR(40) NULLABLE` to `runs` table
- 40 chars = full SHA-1 hash length
- Migration file: `database/migrations/2026_01_16_102931_add_commit_hash_to_runs_table.php`
- Migration tested and verified working

### 2. RunService: Support commit_hash updates ✅
- ✅ Add `commit_hash` to the fields handled by `updateRun()` and `updateLatestRun()`
- No changes needed to `createRun()` (commits happen at end of run)
- Implementation: Added commit_hash handling in both update methods after output truncation

### 3. SelfGuidedContinueCommand: Add --commit flag
- Add `--commit=` option to capture the commit made during this iteration
- Before reopening the task, update the latest run with the commit_hash
- This records the commit for the iteration that just completed

### 4. DoneCommand: Record commit on run (not just task)
- When `fuel done --commit=<hash>` is called, also update the latest run's commit_hash
- Keep existing behavior of storing on task for backward compatibility

### 5. Update selfguided.md prompt
- Update the prompt to instruct agents to use `--commit` with `selfguided:continue`
- Format: `fuel selfguided:continue <id> --commit=<hash> --notes='Progress'`

## Files to Modify

| File | Change |
|------|--------|
| `database/migrations/2026_01_16_102931_add_commit_hash_to_runs_table.php` | ✅ Migration created and tested |
| `app/Services/RunService.php` | ✅ Handle commit_hash in update methods |
| `app/Commands/SelfGuidedContinueCommand.php` | Add --commit flag, update run |
| `app/Commands/DoneCommand.php` | Also store commit_hash on latest run |
| `resources/prompts/selfguided.md` | Update prompt with new flag usage |
| `tests/Unit/Services/RunServiceTest.php` | Test commit_hash handling |
| `tests/Feature/Commands/SelfGuidedContinueCommandTest.php` | Test --commit flag |

## Edge Cases
- No run exists when `done` is called (standalone task without daemon) - skip run update gracefully
- Multiple commits in one iteration - only the last one passed to `--commit` is recorded (acceptable limitation, can extend later)

## Testing Strategy
1. Unit test: RunService accepts and stores commit_hash
2. Feature test: `selfguided:continue --commit=abc123` stores on run
3. Feature test: `done --commit=abc123` stores on both task and run
4. Manual: Run a selfguided epic, verify commits appear on runs

## Implementation Notes

### Migration: Add commit_hash to runs table (f-c76d8e)
- Migration adds `commit_hash` column as `string(40)->nullable()` to `runs` table
- Column is nullable to support existing runs and runs that don't have commits
- Migration includes proper `down()` method to drop the column on rollback
- Migration tested successfully with `./fuel migrate`

## Interfaces Created
<!-- Tasks add interfaces/contracts they create -->

### RunService commit_hash handling (f-d41bf8)
- `updateRun(string $runId, array $data)` - Now accepts `commit_hash` in $data array
- `updateLatestRun(string $taskId, array $data)` - Now accepts `commit_hash` in $data array
- Both methods handle commit_hash the same way as other optional fields (session_id, cost_usd, etc.)
- Pattern: Check if key exists in $data, then add to update fields
- Location: `app/Services/RunService.php` lines ~179 and ~257
