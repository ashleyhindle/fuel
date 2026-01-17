---
name: fuel-make-plan-actionable
description: Convert an approved plan into well-defined Fuel tasks with epics, dependencies, and review tasks. Use after exiting plan mode.
---

# Make Plan Actionable

Convert an approved plan into well-defined Fuel tasks with proper structure.

## When to Use
Invoke this skill immediately after exiting plan mode, when you have an approved implementation plan to convert into trackable tasks.

## Workflow

### 1. Create an Epic and Save Plan
Every multi-task plan needs an epic to group related work:
```bash
fuel epic:add "Feature name" --description="What this achieves and why"
```
Note the epic ID (e.g., `e-abc123`) for linking tasks.

**Save the plan file** to `.fuel/plans/{title-kebab}-{epic-id}.md`:
```markdown
# Epic: Feature Name (e-abc123)

## Plan
[Your approved plan goes here]

## Implementation Notes
<!-- Tasks update this as they work -->

## Interfaces Created
<!-- Tasks add interfaces/contracts they create -->
```

Tasks working on this epic will read the plan for context and update it with discoveries.

### 2. Check Execution Mode

First, check if this is a self-guided epic:
```bash
fuel epic:show [epic-id]
```

If the epic shows `self_guided: true`:
- The epic already has its implementation task
- Do NOT create additional tasks
- Just inform the user: "This is a self-guided epic. Run fuel consume to start iteration."

If `self_guided` is false (default), proceed with normal task breakdown.

### 3. Break Down into Tasks
Each task should have:
- **Single responsibility** - One clear thing to do
- **Explicit description** - Enough detail for a less capable agent to execute without guessing
- **Proper complexity** - `trivial`, `simple`, `moderate`, or `complex`
- **Dependencies** - What must be done first

```bash
fuel add "Task title" \
  --epic=e-xxxx \
  --complexity=simple \
  --priority=1 \
  --description="Exact file paths, what to change, expected behavior"
```

### 4. Order by Dependencies
Build foundational work first:
1. Models/data structures
2. Services/business logic
3. Commands/API endpoints
4. Tests

Use `--blocked-by` to enforce order:
```bash
fuel add "Implement service" --blocked-by=f-model-task --epic=e-xxxx
```

### 5. Create Review Task (Mandatory)
Every epic needs a final review task:
```bash
fuel add "Review: Feature name" \
  --epic=e-xxxx \
  --complexity=complex \
  --blocked-by=f-task1,f-task2,f-task3 \
  --description="Verify epic complete. Criteria: 1) Feature works end-to-end, 2) All tests pass, 3) No debug code, 4) Matches original intent"
```

### 6. Unpause Epic
Epics start paused to prevent tasks from being consumed before setup is complete. Once all tasks and dependencies are added, unpause to start execution:
```bash
fuel unpause e-xxxx
```

## Writing Good Descriptions

**Bad:** "Fix the bug"
**Good:** "In `app/Services/TaskService.php:145`, the `find()` method throws when ID not found. Change to return null and update callers in ShowCommand.php:68 and StartCommand.php:42 to handle null."

Include:
- Exact file paths
- Line numbers when relevant
- What to change (methods, patterns)
- Expected behavior
- Patterns to follow from existing code

## Complexity Guide
- **trivial** - Typos, single-line fixes
- **simple** - Single file, single focus
- **moderate** - Multiple files, clear scope
- **complex** - Multiple concerns, requires judgement

## Example Conversion

Plan: "Add user preferences with API and UI"

```bash
# Create epic
fuel epic:add "Add user preferences" --description="Allow users to set and retrieve preferences via API and UI"

# Break into tasks (note epic ID from above)
fuel add "Create UserPreference model and migration" --epic=e-xxxx --complexity=simple --priority=1
fuel add "Add preferences API endpoints" --epic=e-xxxx --complexity=moderate --priority=1 --blocked-by=f-model
fuel add "Add preferences UI component" --epic=e-xxxx --complexity=moderate --priority=1 --blocked-by=f-api
fuel add "Add preferences tests" --epic=e-xxxx --complexity=simple --priority=1 --blocked-by=f-api,f-ui
fuel add "Review: User preferences" --epic=e-xxxx --complexity=complex --blocked-by=f-model,f-api,f-ui,f-tests --description="Verify: 1) Preferences save and load correctly, 2) UI reflects saved state, 3) All tests pass, 4) No debug code"

# Unpause to start execution
fuel unpause e-xxxx
```
