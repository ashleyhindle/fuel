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
```

## Tips

- **Err toward smaller tasks** - Easier to parallelize, easier to review
- **Tests can often run parallel** to implementation if interface is defined first
- **Don't over-specify** - Trust the agent to make reasonable choices within scope
- **Do specify file paths** - Reduces ambiguity significantly
- **Consider the review** - Will the diff be reviewable? If touching 20 files, probably too big
