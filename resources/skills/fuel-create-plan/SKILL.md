---
name: fuel-create-plan
description: Plan features using codebase context from reality.md. Use when building something across multiple tasks, designing implementations, entering plan mode, or exiting plan mode. This must be used when planning.
---

# Create Plan

Design implementations with full codebase context before breaking into tasks.

## When to Use

Invoke this skill when:
- Entering plan mode for a new feature
- Creating an epic or spec
- Designing an implementation approach
- You need architectural context before planning
- Requirements are unclear and you need to interview the user

**After plan approval**, use the `fuel-make-plan-actionable` skill to convert to tasks.

## Workflow

### 0. Interview (Optional)

If the user's request is unclear or missing key details, use the AskUserQuestion tool to gather requirements. Ask about:

- **Goal** - What should this feature achieve? What problem does it solve?
- **Scope** - What's in scope vs out of scope? What's the MVP?
- **User Impact** - Who uses this? What's their workflow?
- **Constraints** - Performance requirements, compatibility needs, deadlines?
- **Integration** - What existing features/services does this interact with?
- **Success Criteria** - How will we know this is complete and working?

Keep questions focused and specific. Aim for 1-4 questions per round. Use the gathered answers to inform your planning.

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

### 5. Execution Mode Choice

After creating the epic, ask the user how they want it executed:

**Parallel (default)**: Use fuel-make-plan-actionable skill to break into tasks.
- Best when: Requirements are clear, tasks are independent, want faster execution
- Allows cheaper models on simpler tasks
- Tasks run concurrently

**Self-guided**: Add --selfguided flag to epic:add
- Best when: Exploratory work, requirements may evolve, need to learn as you go
- Single task iterates until all acceptance criteria met
- Always uses capable model (more expensive)
- Cannot parallelize

Example:
```bash
fuel epic:add 'Feature' --selfguided --description='Criteria: 1)... 2)...'
```

### 6. Document the Plan

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

### 7. Exit Plan Mode

Once your plan is complete, exit plan mode for approval. After approval, use the `fuel-make-plan-actionable` skill to convert the plan into tasks.

## When Reality Doesn't Exist

If `.fuel/reality.md` is a stub or empty:
- Explore the codebase manually
- Focus on similar existing features
- Document what you learn in your plan for future reference

After the first epic completes, reality.md will be populated.

## Example Planning Session

### Example 1: Clear Requirements
1. User asks: "Add user notification preferences"
2. Read `.fuel/reality.md` - find existing UserPreference model, NotificationService
3. Explore `app/Services/NotificationService.php` - understand current flow
4. Design: extend UserPreference model, add preference check to NotificationService
5. Create epic: `fuel epic:add "User notification preferences"`
6. Write plan to `.fuel/plans/user-notification-preferences-e-xxxx.md`
7. Exit plan mode, await approval
8. On approval, invoke `fuel-make-plan-actionable` to create tasks

### Example 2: Unclear Requirements (Interview First)
1. User asks: "Make the app faster"
2. Interview: Ask questions to clarify scope
   - "Which part of the app feels slow? API responses, page loads, or background jobs?"
   - "What's the current performance baseline and target?"
   - "Are there specific user workflows affected?"
3. User responds: "API responses take 2-3 seconds, target is <500ms, affects dashboard load"
4. Read `.fuel/reality.md` - find API architecture, caching strategy
5. Profile: Explore dashboard API endpoints, check for N+1 queries
6. Design: add query optimization, implement response caching, add indexes
7. Create epic: `fuel epic:add "Optimize dashboard API performance"`
8. Write plan to `.fuel/plans/optimize-dashboard-api-performance-e-xxxx.md`
9. Exit plan mode, await approval
10. On approval, invoke `fuel-make-plan-actionable` to create tasks

## Next: Convert to Tasks

Once your plan is approved, use the **fuel-make-plan-actionable** skill to:
- Break the plan into individual tasks with `fuel add --epic=e-xxxx`
- Set proper complexity and dependencies
- Create a mandatory review task
- Start execution with `fuel-consume-the-fuel`

The two skills form a complete workflow:
1. **fuel-create-plan** → Design with context
2. **fuel-make-plan-actionable** → Convert to executable tasks
