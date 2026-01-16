# Epic: TUI for fuel human command (e-e7a9ec)

## Goal
Transform `fuel human` from a simple list output into an interactive TUI that:
1. Uses `ScreenBuffer` to track screen content cell-by-cell
2. Detects mouse left clicks on buttons
3. Shows actionable buttons under epics and tasks

## Buttons

**Epics (review_pending status):**
- Left side: `[ Copy review command ]` `[ Reviewed ]` `[ Approved ]`
- Right side: `[ Delete ]`

**Tasks (needs-human label):**
- `[ Copy command ]` - copies `fuel show <task-id>`

## Approach

### Architecture Pattern
Follow `ConsumeCommand`'s TUI pattern:
- Alternate screen mode (`\033[?1049h`)
- Raw terminal mode (`stty -icanon -echo`)
- Mouse reporting (`\033[?1003h`)
- Non-blocking input polling
- ScreenBuffer for content tracking + regions
- Differential rendering loop
- Clean shutdown with terminal restoration

### Key Differences from ConsumeCommand
- **Simpler**: No IPC, no daemon connection, no real-time updates
- **Static content**: Fetch data, render, wait for input, refresh on action
- **Focused**: Only epics pending review + needs-human tasks
- **Continuous**: After clicking a button, perform action, refresh, continue

### UI Layout

```
┌─────────────────────────────────────────────────────┐
│ Fuel: Human Review                         q: quit │
├─────────────────────────────────────────────────────┤
│                                                     │
│ EPICS PENDING REVIEW (2)                           │
│                                                     │
│ e-abc123 - Add user preferences (3 days ago)       │
│   Description text here...                          │
│   [ Copy review ] [ Reviewed ] [ Approved ]  [ Del ]│
│                                                     │
│ e-def456 - Fix auth flow (1 hour ago)              │
│   Another description...                            │
│   [ Copy review ] [ Reviewed ] [ Approved ]  [ Del ]│
│                                                     │
│ ─────────────────────────────────────────────────── │
│                                                     │
│ TASKS NEEDING HUMAN (1)                            │
│                                                     │
│ f-ghi789 - Get API credentials (2 hours ago)       │
│   Instructions for the human...                     │
│   [ Copy command ]                                  │
│                                                     │
└─────────────────────────────────────────────────────┘
```

### Button Actions

| Button | Region Type | Action |
|--------|-------------|--------|
| Copy review | `button-copy-review` | OSC 52 copy `fuel epic:review <id>` to clipboard, show toast |
| Reviewed | `button-reviewed` | Run `fuel epic:reviewed <id>`, refresh display |
| Approved | `button-approved` | Run `fuel epic:approve <id>`, refresh display |
| Del | `button-delete` | Run `fuel epic:delete <id>`, refresh display |
| Copy command | `button-copy-cmd` | OSC 52 copy `fuel show <id>` to clipboard, show toast |

### Implementation Tasks

1. **TUI Infrastructure** - Terminal setup, main loop, shutdown handling
2. **Render Logic** - ScreenBuffer content rendering with button regions
3. **Mouse Handling** - Click detection and button dispatch
4. **Button Handlers** - Execute actions (clipboard, Artisan commands)
5. **Toast Feedback** - Visual feedback after actions

## Files to Modify

- `app/Commands/HumanCommand.php` - Complete rewrite for TUI

## Files to Create

None - reuse existing TUI components:
- `app/TUI/ScreenBuffer.php` - already has region support
- `app/TUI/Toast.php` - for feedback messages

## Testing Strategy

1. Feature test: `fuel human --once` renders epics/tasks without TUI
2. Manual testing: verify mouse clicks trigger correct actions
3. Verify terminal restoration on `q` and Ctrl+C

## Edge Cases

- **No items**: Show "Nothing needs attention" message in TUI, user can still quit with `q`
- **Terminal too narrow**: Truncate descriptions, keep buttons visible
- **SIGWINCH**: Resize buffers and re-render
- **OSC 52 clipboard fail**: Toast shows action completed anyway (command still ran)
- **Delete safety**: No confirmation - user can reopen epics if needed

## Implementation Notes
<!-- Tasks update this as they work -->

## Interfaces Created
<!-- Tasks add interfaces/contracts they create -->
