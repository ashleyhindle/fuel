---
name: consume-the-fuel
description: Execute ready Fuel tasks efficiently. Use when asked to "consume the fuel", "work on tasks", or "do the work".
---

# Consume the Fuel

Execute ready Fuel tasks efficiently with proper coordination.

## When to Use
Invoke this skill when asked to "consume the fuel", "work on tasks", or "do the work".

## Workflow

### 1. Get Ready Tasks
```bash
fuel ready --json
```
Review available tasks. Prefer by priority: P0 > P1 > P2.

### 2. Claim Before Working
**Always start a task before working on it:**
```bash
fuel start <id>
```
This prevents conflicts and tracks who is working on what.

### 3. Do the Work
- Read the task description carefully - it should contain explicit instructions
- Implement the change
- Run tests if code was modified
- Run linter/formatter if code was modified

### 4. Complete the Task
```bash
git add <files>
git commit -m "feat/fix: description"
fuel done <id> --commit=<hash>
```
Use the commit hash from the git output (e.g., `[main abc1234]`).

### 5. Check for Newly Unblocked Work
```bash
fuel ready
```
Completing a task may unblock dependent tasks.

## Parallel Execution (Primary Agent)

When coordinating subagents:

1. **Primary claims all tasks** - Run `fuel start <id>` for each task BEFORE spawning subagents
2. **Spawn with explicit IDs** - Tell each subagent exactly which task ID to work on
3. **Subagents run `fuel done`** - Each subagent marks their task complete
4. **Primary reviews** - Verify tests pass, requirements met, no debug code left
5. **File issues if needed** - `fuel add "Fix X from f-xxxx" --blocked-by=...`

Avoid parallel work on tasks touching the same files - use dependencies instead.

## Task Completion Checklist
```
[ ] Task started with fuel start
[ ] Implementation complete
[ ] Tests pass (if code changed)
[ ] Linter clean (if code changed)
[ ] Changes committed
[ ] Task marked done with commit hash
[ ] Discovered work filed as new tasks
```
