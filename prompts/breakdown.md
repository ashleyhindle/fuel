# Epic Breakdown Prompt

Use this prompt when decomposing a feature/epic into tasks for Fuel.

---

## Context

I want to implement the following feature/epic:

**Title:** [EPIC TITLE]

**Description:** [WHAT IT SHOULD DO, WHY IT MATTERS]

**Constraints/Notes:** [ANY TECHNICAL CONSTRAINTS, PATTERNS TO FOLLOW, FILES TO AVOID]

---

## Instructions

Break this down into discrete, implementable tasks for Fuel. Each task should:

1. **Be independently completable** - A capable agent can finish it in one session
2. **Have clear scope** - Specific files, specific changes, specific outcome
3. **Include dependencies** - Which tasks must complete before others?
4. **Set complexity accurately:**
   - `trivial` - Single line or config change
   - `simple` - One file, one focused change
   - `moderate` - Multiple files, coherent change
   - `complex` - Should probably be broken down further

### Task Scope Test: "One Sentence Without 'And'"

Can you describe the task in one sentence without conjoining unrelated work?
- ✓ "Add user_id foreign key column to preferences table"
- ✗ "Create migration and model and add relationships" → 3 tasks

If you need "and" to describe it, split it into separate tasks.

### Parallelization Checklist

Before finalizing tasks, consider which can run in parallel:
- **Different files?** → Can run parallel (no conflicts)
- **Shared interface?** → Define interface first as blocking task, then parallelize implementations
- **Same file touched?** → Must use dependencies (--blocked-by), not parallel

Maximize parallel work by isolating tasks to separate files where possible.

### Include Verification Criteria

Each task description should include what success looks like:
- What observable outcome indicates completion?
- What tests should pass? (behavior, not implementation)
- What edge cases matter?

Example: "Validate timezone against timezone_identifiers_list(). Returns 422 with invalid timezone."

### Give One Clear Path

Subagents execute, they don't decide. Provide one clear solution, not options:
- ✗ "Could use Redis or file cache or in-memory"
- ✓ "Use Laravel's file cache driver in storage/framework/cache"

## Output Format

For each task, provide the `fuel add` command:

```bash
fuel add "Task title" \
  --description="Detailed description with file paths and expected behavior" \
  --type=feature|bug|refactor|test|chore \
  --priority=0|1|2 \
  --complexity=trivial|simple|moderate \
  --blocked-by=f-xxxxx  # if depends on another task
```

## Example Breakdown

**Epic:** "Add user preferences API"

```bash
# Task 1: Database schema
fuel add "Add user_preferences table migration" \
  --description="Create migration in database/migrations/ with columns: user_id (fk), theme (string), notifications_enabled (bool), timezone (string). Add index on user_id." \
  --type=feature \
  --priority=1 \
  --complexity=simple

# Task 2: Model (depends on schema)
fuel add "Create UserPreference model" \
  --description="Create app/Models/UserPreference.php with fillable fields, belongsTo User relationship. User model gets hasOne UserPreference." \
  --type=feature \
  --priority=1 \
  --complexity=simple \
  --blocked-by=f-xxxxx

# Task 3: API endpoint (depends on model)
fuel add "Add GET/PUT /api/user/preferences endpoint" \
  --description="Add routes in routes/api.php. Create PreferencesController with show() and update() methods. Return JSON. Validate timezone against timezone_identifiers_list()." \
  --type=feature \
  --priority=1 \
  --complexity=moderate \
  --blocked-by=f-xxxxx

# Task 4: Tests (depends on endpoint)
fuel add "Add tests for preferences API" \
  --description="Create tests/Feature/PreferencesApiTest.php. Test: get preferences, update preferences, validation errors, unauthenticated access returns 401." \
  --type=test \
  --priority=1 \
  --complexity=simple \
  --blocked-by=f-xxxxx

# Task 5: Epic review (depends on all tasks)
fuel add "Review: Add user preferences API" \
  --description="Verify epic is complete. Acceptance criteria: 1) Migration runs without error, 2) GET /api/user/preferences returns user prefs, 3) PUT /api/user/preferences updates prefs, 4) Invalid timezone returns 422, 5) Unauthenticated returns 401, 6) All tests pass. Run: php artisan migrate:fresh && vendor/bin/pest tests/Feature/PreferencesApiTest.php" \
  --type=chore \
  --priority=1 \
  --complexity=complex \
  --blocked-by=f-xxxxx
```

### Epic Review Tasks

Every epic MUST end with a review task. Review tasks:

- **Always use `--complexity=complex`** - Reviews verify multiple behaviors across multiple files
- **Block on ALL other epic tasks** - Only runs when everything else is done
- **List explicit acceptance criteria** - Numbered list of observable outcomes to verify
- **Include test commands** - Exact commands to run for verification
- **Type is `chore`** - It's verification work, not feature/test work

**Review tasks must verify THREE things:**

1. **Intent satisfied** - Does the implementation match the epic's description? Would the user be happy with what was delivered?
2. **Functional correctness** - Do all behaviors work? Do tests pass? Are edge cases handled?
3. **Code quality** - No debugging calls (dd(), console.log, var_dump), no useless comments, no dead code, follows project patterns

**Acceptance criteria format:**
```
1) [Intent] - "Epic goal achieved: user can now do X"
2) [Behavior] - "GET /endpoint returns expected JSON"
3) [Error handling] - "Invalid input returns 422"
4) [Tests] - "All tests pass: vendor/bin/pest path/to/tests"
5) [Quality] - "No debug calls, no commented code, follows existing patterns"
```

## Tips

- **Err toward smaller tasks** - Easier to parallelize, easier to review
- **Tests can often run parallel** to implementation if interface is defined first
- **Be clear what you want the agent to do** - You are passing tasks to a less-capable agent when using trivial, simple, or moderate complexities
- **Do specify file paths** - Reduces ambiguity significantly
- **Consider the review** - Will the diff be reviewable? If touching 20 files, probably too big
- **Don't assume not implemented** - Before creating a task, search the codebase. The functionality may already exist
- **Context is precious** - Tight, focused tasks keep agents in the "smart zone" of their context window
- **Placeholders waste effort** - Each task should be fully implementable, not a stub for later
