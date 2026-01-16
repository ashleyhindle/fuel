<fuel-prompt version="2" />

== SELF-GUIDED EPIC EXECUTION ==
Iteration {{ iteration }} of {{ max_iterations }}

You are executing an epic incrementally. Each iteration you assess progress, execute one task, and decide what to do next.

== CODEBASE CONTEXT ==
{{ reality }}

== EPIC PLAN ==
{{ plan }}

== YOUR TASK ==
**Task:** {{ task.short_id }}
**Title:** {{ task.title }}
**Epic:** {{ epic.short_id }}

== PREVIOUS PROGRESS ==
{{ progress_log }}

== INSTRUCTIONS ==

### 1. Assess Current State
Review the acceptance criteria in the epic plan above. Check off what's already complete.

### 2. Execute ONE Criterion
Pick the next unchecked criterion and implement it fully:
- Write code
- Run tests for affected code
- **Smoke test it yourself** - actually run/use what you built:
  - CLI command? Run it
  - Web feature? Load it in browser
  - API endpoint? Call it with curl
  - Figure out a safe way to use it
- Fix any issues found

You MUST execute only one task / criterion. Do not implement everything.

**WARNING: Unit tests passing ≠ feature works.** Tests may mock, stub, or early-return. You must verify the real thing works.

### 3. Commit Your Changes
```bash
git add <files>
git commit -m "feat({{ epic.short_id }}): [description of what you did]"
```

### 4. Update Plan File
Edit `{{ epic_plan_filename }}` to:
- Check off completed criterion: `- [x] Criterion text`
- Append to Progress Log section: `- Iteration {{ iteration }}: [what you did]`

### 5. Decide Next Action

**CRITICAL: You MUST run ONE of these commands before exiting. Do NOT exit without running one of these:**

**All acceptance criteria complete?**
```bash
fuel done {{ task.short_id }} --commit=[hash from step 3]
```

**More work to do?**
```bash
fuel selfguided:continue {{ task.short_id }} --notes='Completed X, next is Y'
```

**Stuck or need human input?**
```bash
fuel selfguided:blocked {{ task.short_id }} --reason='Why you are blocked'
```

**REMINDER: Your session will end after this. You MUST run one of the above commands NOW.**

== QUALITY GATES ==
Before marking any criterion complete:
- [ ] Code has no errors
- [ ] Relevant tests pass
- [ ] **You ran/used the feature and it actually works** (not just tests!)
- [ ] Other quality gates passed
- [ ] Formatters ran
- [ ] Style checkers ran
- [ ] Browser tests verified (if web page)
- [ ] No debug statements left (dd, var_dump, console.log)
- [ ] Changes committed with descriptive message

== RULES ==
- ONE criterion per iteration - don't try to do everything at once
- ALWAYS update the plan file to track progress
- ALWAYS commit before continuing or finishing
- If stuck on same criterion 3+ times, use selfguided:blocked
