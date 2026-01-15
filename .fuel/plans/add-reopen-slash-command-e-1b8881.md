# Epic: Add reopen slash command (e-1b8881)

## Plan

Add a `/reopen` command to the consume TUI command palette that allows reopening failed or completed tasks directly from the interface.

### Goal
Users can reopen tasks without leaving the TUI. The command autocompletes with:
1. Failed in-progress tasks (consumed but process died)
2. Last 5 done tasks (recently completed)

### Acceptance Criteria
- [x] `/reopen` shows in command list when pressing `/`
- [x] Typing `reopen ` shows failed tasks + last 5 done tasks
- [x] Tab completes task ID into input
- [x] Enter reopens the selected task

### Approach

**File 1: ConsumeIpcClient.php** - Add IPC method
- Add `use App\Ipc\Commands\TaskReopenCommand;` import
- Add `sendTaskReopen(string $taskId): void` method (pattern: copy from `sendTaskDone`)

**File 2: ConsumeCommand.php** - Wire up command palette
1. Add `'reopen' => 'Reopen a closed or failed task'` to `PALETTE_COMMANDS` constant (~line 3038)
2. Update `updateCommandPaletteSuggestions()` (~line 3080) to route `reopen ` prefix
3. Add `updateReopenTaskSuggestions(string $searchTerm): void` method:
   - Get failed tasks via `$this->taskService->failed()`
   - Get last 5 done tasks via `$this->taskService->all()->filter(status=Done)->sortByDesc('updated_at')->take(5)`
   - Combine and filter by search term
4. Update `acceptCurrentSuggestion()` (~line 3049) to handle `reopen` command
5. Add execution block in `executeCommandPalette()` (~line 3164) for `reopen <task-id>`

### Files to Modify
- `app/Services/ConsumeIpcClient.php` - Add sendTaskReopen method
- `app/Commands/ConsumeCommand.php` - Add command palette integration

### Testing Strategy
- Manual test: Run `fuel consume`, press `/`, type `reopen`, verify autocomplete shows failed/done tasks
- Tab complete a suggestion, verify input updates
- Execute reopen, verify task status changes to open

## Implementation Notes
- Iteration 1: Completed all acceptance criteria in a single iteration
- TaskReopenCommand IPC and sendTaskReopen() were pre-existing
- PALETTE_COMMANDS already had 'reopen' entry
- Added updateReopenTaskSuggestions() method - shows failed tasks first, then last 5 done
- Updated acceptCurrentSuggestion() to detect reopen vs close context
- Added /reopen handler in executeCommandPalette() with proper validation

## Progress Log
- Iteration 1: Implemented all remaining features (suggestions, tab complete, execution)

## Interfaces Created
- No new interfaces created - used existing TaskReopenCommand and ConsumeIpcClient.sendTaskReopen()
