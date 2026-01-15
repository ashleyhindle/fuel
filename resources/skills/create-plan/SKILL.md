---
name: create-plan
description: Plan features using codebase context from reality.md. Use when designing implementations or entering plan mode.
---

# Create Plan

Design implementations with full codebase context before breaking into tasks.

## When to Use

Invoke this skill when:
- Entering plan mode for a new feature
- Creating an epic or spec
- Designing an implementation approach
- You need architectural context before planning

**After plan approval**, use the `fuel-make-plan-actionable` skill to convert to tasks.

## Workflow

### 1. Read Reality for Context

Start by understanding the codebase architecture:

```bash
cat .fuel/reality.md
```

Look for:
- **Architecture** - Overall structure, patterns in use
- **Modules** - Where related functionality lives
- **Entry Points** - Where to hook new features
- **Patterns** - Conventions to follow
- **Recent Changes** - Related work that might inform design

### 2. Explore Related Code

Use reality.md to identify relevant files, then explore:
- Similar implementations to follow as patterns
- Interfaces your feature should implement
- Tests that show expected behavior

### 3. Design the Solution

Write a clear plan that includes:
- **Goal** - What the feature achieves and why
- **Approach** - How you'll implement it
- **Files to modify** - Specific paths
- **New files needed** - With proposed locations
- **Edge cases** - Errors, validation, boundaries
- **Testing strategy** - How to verify it works

### 4. Create Epic (if multi-task)

For features requiring multiple tasks:

```bash
fuel epic:add "Feature name" --description="What and why"
```

Note the epic ID (e.g., `e-abc123`). A plan file is auto-created at `.fuel/plans/{title-kebab}-{epic-id}.md`.

### 5. Document the Plan

Write your plan to the epic's plan file:

```markdown
# Epic: Feature Name (e-abc123)

## Plan
[Your detailed implementation approach]

## Implementation Notes
<!-- Tasks update this as they work -->

## Interfaces Created
<!-- Tasks add interfaces/contracts they create -->
```

**Commit the plan file** - `.fuel/plans/` is tracked in git.

### 6. Exit Plan Mode

Once your plan is complete, exit plan mode for approval. After approval, use the `fuel-make-plan-actionable` skill to convert the plan into tasks.

## When Reality Doesn't Exist

If `.fuel/reality.md` is a stub or empty:
- Explore the codebase manually
- Focus on similar existing features
- Document what you learn in your plan for future reference

After the first epic completes, reality.md will be populated.

## Example Planning Session

1. User asks: "Add user notification preferences"
2. Read `.fuel/reality.md` - find existing UserPreference model, NotificationService
3. Explore `app/Services/NotificationService.php` - understand current flow
4. Design: extend UserPreference model, add preference check to NotificationService
5. Create epic: `fuel epic:add "User notification preferences"`
6. Write plan to `.fuel/plans/user-notification-preferences-e-xxxx.md`
7. Exit plan mode, await approval
8. On approval, invoke `fuel-make-plan-actionable` to create tasks

## Next: Convert to Tasks

Once your plan is approved, use the **fuel-make-plan-actionable** skill to:
- Break the plan into individual tasks with `fuel add --epic=e-xxxx`
- Set proper complexity and dependencies
- Create a mandatory review task
- Start execution with `fuel-consume-the-fuel`

The two skills form a complete workflow:
1. **fuel-create-plan** → Design with context
2. **fuel-make-plan-actionable** → Convert to executable tasks
