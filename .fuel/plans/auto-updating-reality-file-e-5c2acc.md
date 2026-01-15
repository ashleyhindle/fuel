# Epic: Auto-updating reality file (e-5c2acc)

## Plan

Create `.fuel/reality.md` - a lean, auto-updated index of the codebase architecture. Updated automatically after solo task completion and epic approval. Runs in background (fire-and-forget).

## reality.md Structure

```markdown
# Reality

## Architecture
Brief 2-3 sentence overview of what this codebase is and how it's structured.

## Modules
| Module | Purpose | Entry Point |
|--------|---------|-------------|
| TaskService | Task CRUD and queries | app/Services/TaskService.php |

## Entry Points
**Add a command:** Copy `app/Commands/AddCommand.php`
**Add a service:** Create in `app/Services/`, inject via constructor or `app()`

## Patterns
- DI: Use `app(Class::class)` over constructor chains
- Tests: Pest syntax, not PHPUnit

## Recent Changes
- 2024-01-15: Added epic plan files

_Last updated: 2024-01-15 by UpdateReality_
```

## Config Structure

`reality` is a top-level key (like `primary` and `review`) referencing an agent name:

```yaml
primary: claude-opus
review: claude-opus
reality: claude-opus
```

## Hook Points

1. **Solo task completion**: `WorkAgentTask::onSuccess()` - if `task->epic_id === null`
2. **Epic approval**: `EpicService::approveEpic()` - after approval completes

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->
- UpdateRealityAgentTask uses a dummy Task when created from Epic (via fromEpic factory) since AbstractAgentTask requires a Task in constructor

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->
- `UpdateRealityAgentTask` - app/Agents/Tasks/UpdateRealityAgentTask.php
  - Static factories: `fromTask(Task, cwd)` and `fromEpic(Epic, cwd)`
  - Uses `getRealityAgent()` from ConfigService for agent selection
  - Task ID format: `reality-{task_or_epic_short_id}`
  - Process type: `ProcessType::Task` (fire-and-forget)
  - Lifecycle hooks are no-ops (fire-and-forget pattern)
