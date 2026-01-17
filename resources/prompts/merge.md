<fuel-prompt version="1" />

# Merge Epic: {{epic.title}}

You are merging an epic's work from its isolated mirror back into the main project.

**CRITICAL: You are NOT implementing anything. You are merging already-completed work.**

## Epic Details

**Epic ID:** {{epic.id}}
**Epic Title:** {{epic.title}}
**Mirror Path:** {{mirror.path}}
**Branch:** {{mirror.branch}}
**Base Commit:** {{mirror.base_commit}}

## Your Assignment

Merge the epic's branch from the mirror into the main project and verify it passes quality gates.

## Step 1: Understand the Epic

Read the epic plan to understand what was built:
- **Plan file:** .fuel/plans/{{epic.plan_file}}

Understanding the epic's intent is critical for intelligent conflict resolution.

## Step 2: Fetch the Epic Branch

You are working in the MAIN project directory: {{project.path}}

Fetch the epic's branch from the mirror:
```bash
git fetch {{mirror.path}} {{mirror.branch}}:{{mirror.branch}}
```

This brings the epic's commits into your local repository without checking them out.

## Step 3: Merge with No Fast-Forward

Create a merge commit to preserve the epic's history:
```bash
git merge {{mirror.branch}} --no-ff -m "Merge epic {{epic.id}}: {{epic.title}}"
```

## Step 4: Resolve Conflicts (If Any)

If conflicts occur:
1. **Read the epic plan** to understand the intent
2. **Examine both sides** of the conflict with `git diff`
3. **Resolve based on epic intent** - the epic's changes should generally win unless they break the main branch
4. **Preserve both changes** when possible (merge, don't replace)
5. **Test after resolution** to ensure nothing broke

Common conflict scenarios:
- **Epic modified file, main branch also modified it**: Merge both changes carefully
- **Epic added feature, main branch refactored**: Adapt epic's feature to new structure
- **Import/use statements**: Merge both, remove duplicates

After resolving conflicts:
```bash
git add <resolved-files>
git commit -m "Merge epic {{epic.id}}: {{epic.title}}"
```

## Step 5: Run Quality Gates

Verify the merge passes all quality checks:

{{quality_gates}}

**If any quality gate fails:**
1. Fix the issue (likely from conflict resolution)
2. Commit the fix with a clear message
3. Re-run quality gates until all pass

## Step 6: Verify Completeness

Sanity checks:
- Does the merged code match the epic's intent?
- Are all epic tasks' changes present?
- Do all tests pass?
- Is the code formatted correctly?

## FORBIDDEN

**DO NOT run fuel commands.** You are NOT working on tasks, you are merging completed work.

Specifically, do NOT run:
- `fuel start`, `fuel done`, `fuel add`
- `fuel status`, `fuel board`, `fuel tree`
- Any other fuel commands

Your job is git operations and quality verification ONLY.

## Success Criteria

The merge is complete when:
1. The epic's branch is merged into the main branch (current branch)
2. All conflicts are resolved
3. All quality gates pass
4. The code is committed

When done, simply exit. The system will handle cleanup.

## Working Directory

{{project.path}}
