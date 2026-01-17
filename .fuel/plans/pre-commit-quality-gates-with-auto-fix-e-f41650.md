# Epic: Pre-commit quality gates with auto-fix (e-f41650)

## Problem

Quality gates in prompts are suggestions agents can ignore. No enforcement mechanism exists. Each prompt (selfguided, work, merge, review) duplicates quality gate lists, which are generic rather than project-specific.

## Solution

Enforce quality gates via git pre-commit hook with automatic formatting and re-staging. Agents get immediate feedback when commits fail, creating a self-correcting loop.

## Design Rationale

### Why Pre-commit Hooks > Prompt Instructions

From AI coding agent feedback loop research: "AI agents don't get frustrated by repetition. When code fails type checking or tests, the agent simply tries again. This makes feedback loops (and pre-commit hooks, especially) incredibly powerful for AI-driven development."

| Approach | Enforcement | Project-specific | Config burden |
|----------|-------------|------------------|---------------|
| Prompt instructions | None (suggestions) | No (generic lists) | None |
| Pre-commit hooks | Hard (commit fails) | Yes (agent detects tools) | Zero (auto-generated) |

### Why `fuel qc` Not `fuel pre-commit`

The command will be used in multiple contexts:
- Pre-commit hook (primary)
- Review tasks (verify quality before approval)
- Manual runs (`fuel qc` to check before committing)
- CI pipelines (`fuel qc --check`)

Generic name allows broader use.

### Formatter Auto-fix Flow

Problem: Formatters (pint, prettier, rector) modify files. If we just run them, the staged files are now different from the working tree.

Solution: Capture staged file list upfront, run all quality gates, then re-add the originally staged files if everything passes. This catches formatter changes without accidentally staging unrelated work.

```
1. staged = $(git diff --cached --name-only)
2. Run .fuel/quality-gate (formatters fix, linters check)
3. If passed: git add $staged (re-add catches formatter changes)
4. Exit with quality-gate status
```

No pre-stage hook exists in git, so this is handled in pre-commit.

### Warn Don't Block When Unconfigured

If `.fuel/quality-gate` doesn't exist:
- Warn: "No .fuel/quality-gate configured"
- Exit 0 (don't block)

Rationale:
1. First commit needs to happen to create the quality-gate file
2. Projects with existing husky/lint-staged still get their quality gates (our warning is just noise)
3. Encourages but doesn't force configuration

### Agent Bypass Concern

Agents can use `git commit --no-verify` to bypass hooks. Mitigations:
1. Explicit FORBIDDEN in prompts and AGENTS.md
2. Agents generally respect explicit prohibitions
3. Accept as escape hatch for humans who know what they're doing
4. Can't fully prevent without wrapping git (fragile, not worth it)

### Verification vs Quality

Two separate concerns:
- **Quality/formatting**: `fuel qc` handles this (linting, tests, formatters)
- **Intent/correctness**: Review agent handles this (does it do what was asked?)

`fuel qc` doesn't verify the feature works - that's behavioral verification (verify.md prompt, review agent).

### Related Work

- Backlog `f-fa8e77`: Auto-detect common quality gate setups during init (typescript/eslint, php/phpstan/pest, go/vet)
- Future: pre-push hook for expensive test suites
- Future: `.fuel/quality-gate.d/` directory for multiple scripts

## Discussion Overview

Key decisions made during design:

1. **Append intelligently to existing hooks** - If user has husky or existing pre-commit, we append `fuel qc`. Their stuff still runs, we add ours. If unconfigured, we just warn - their existing quality gates (husky/lint-staged) provide the feedback loop anyway.

2. **Starter task generates quality-gate** - Like the reality.md starter task, agent explores the project (package.json, composer.json, Makefile, tool configs) and generates an executable `.fuel/quality-gate` script. Zero config for user, project-specific, self-healing if tooling changes.

3. **10x more useful than prompt instructions** because:
   - Enforced (not suggestions)
   - Project-specific (not generic)
   - Zero config for user (agent figures it out)
   - Self-healing (agent can update it later)
   - Works for any stack (agent understands context)

4. **Simplifies prompts** - Instead of verbose quality gate checklists in every prompt, just say "pre-commit will enforce quality, if it fails fix the issues". Keeps prompts focused on *what* to do, hooks handle *enforcement*.

5. **One thing to preserve from prompts** - The "smoke test it yourself" instruction. Pre-commit hooks run automated checks, but "actually use the feature" is behavioral - agents need prompting for that. That's the review agent's domain.

6. **Considered hooks**: pre-commit (main one), commit-msg (validate message format), pre-push (expensive tests), post-index-change (no pre-stage hook exists). pre-commit is the right choice for quality gates.

7. **Git commands checked**: `git diff --cached --name-only -z --diff-filter=ACMR` gets staged files. `git rev-parse --git-path hooks` resolves hooks path. `git commit --no-verify` or `-n` bypasses hooks. No way to prevent bypass without wrapping git.

## Plan

### Core Flow

```
git commit
    ↓
$(git rev-parse --git-path hooks)/pre-commit
    ↓
"$root/fuel" qc
    ↓
├─ Capture staged files: git diff --cached --name-only -z --diff-filter=ACMR
├─ Run .fuel/quality-gate script
├─ If passed: re-add staged files (catches formatter changes)
└─ Exit with quality-gate status
```

### Components

#### 1. QcCommand (`fuel qc`)

New command at `app/Commands/QcCommand.php`:

```php
protected $signature = 'qc {--check : Check only, no auto re-add}';
```

Behavior:
- Ensure inside git repo (`git rev-parse --is-inside-work-tree`); if not, error + exit 1
- Get staged files: `git diff --cached --name-only -z --diff-filter=ACMR`
- If no staged files, exit 0
- Check `.fuel/quality-gate` exists and is executable
  - If missing: warn "No .fuel/quality-gate configured" and exit 0 (don't block)
- Run `.fuel/quality-gate` script, capture exit code
- If exit 0 AND not `--check`: re-add originally staged files via `git add -- <files>` using null-delimited list
- Exit with quality-gate script's exit code

#### 2. InitCommand Hook Installation

Modify `app/Commands/InitCommand.php` to install pre-commit hook (respect `core.hooksPath` and avoid `exit 0` short-circuit):

```php
private function installPreCommitHook(string $cwd): void
{
    $hooksPath = trim(Process::run('git rev-parse --git-path hooks')->output());
    $hookPath = $hooksPath . '/pre-commit';
    $fuelBlock = "root=$(git rev-parse --show-toplevel)\n\"$root/fuel\" qc || exit $?\n";

    if (file_exists($hookPath)) {
        $content = file_get_contents($hookPath);
        if (str_contains($content, 'fuel" qc')) {
            return; // Already installed
        }
        // Insert before trailing exit 0 if present, else append
        if (preg_match('/\nexit 0\s*$/', $content)) {
            $content = preg_replace('/\nexit 0\s*$/', "\n" . $fuelBlock . "exit 0\n", $content);
            file_put_contents($hookPath, $content);
        } else {
            file_put_contents($hookPath, rtrim($content) . "\n" . $fuelBlock);
        }
    } else {
        // Create new hook
        file_put_contents($hookPath, "#!/bin/bash\n" . $fuelBlock);
        chmod($hookPath, 0755);
    }
}
```

#### 3. Quality Gate Starter Task

Add second starter task in InitCommand (after reality.md task):

```php
$qcTask = $taskService->create([
    'title' => 'Generate .fuel/quality-gate script for this project',
    'type' => 'task',
    'priority' => 2,
    'complexity' => 'simple',
    'description' => 'Explore the project to detect quality tools in use (package.json scripts, composer.json scripts, Makefile targets, tool configs like .eslintrc, phpstan.neon, pyproject.toml, etc.). Generate an executable .fuel/quality-gate script that runs the appropriate checks. Script should run formatters first (pint, prettier, black), then linters/type-checkers (phpstan, eslint, tsc, mypy), then tests if fast. Only include tools actually present in the project. Make script executable: chmod +x .fuel/quality-gate',
]);
```

#### 4. Prompt Updates

Update these prompts to reference pre-commit enforcement:

**selfguided.md** (version bump to 5):
- Remove verbose QUALITY GATES checklist
- Replace with:
```markdown
== COMMITS ==
Quality checks run automatically on commit via pre-commit hook.
If commit fails: read error output, fix issues, commit again.

FORBIDDEN: git commit --no-verify, git commit -n
```

**work.md** (version bump to 3):
- Add similar COMMITS section
- Add FORBIDDEN clause

**merge.md** (version bump to 2):
- Reference pre-commit enforcement in quality gates section
- Add FORBIDDEN clause

**review.md**: No change needed (reviews don't commit)

**verify.md**: No change needed (verification is behavioral)

#### 5. AGENTS.md / Guidelines Update

Add to GuidelinesCommand output:
```markdown
### Git Commits
- Quality gates enforced via pre-commit hook
- FORBIDDEN: `git commit --no-verify`, `git commit -n` (bypasses quality checks)
```

### Edge Cases

1. **No .fuel/quality-gate file**: Warn, don't block. Lets first commit happen (to create the file).

2. **quality-gate not executable**: Warn, don't block. Prompt user to `chmod +x`.

3. **Formatters modify files**: Re-add staged files after successful run catches changes.

4. **Existing pre-commit hook**: Insert `fuel qc` block before trailing `exit 0`, else append. Preserve user's setup.

5. **Hook already has fuel qc**: Skip installation (idempotent).

6. **Hooks path**: Use `git rev-parse --git-path hooks` (supports `core.hooksPath`/husky).

7. **Agent uses --no-verify**: Can't fully prevent, but explicit FORBIDDEN in prompts + AGENTS.md makes intent clear.

8. **Not a git repo**: `fuel qc` errors and exits 1.

9. **CI environment**: `fuel qc --check` mode for CI pipelines that just want to verify without re-staging.

### File Changes Summary

| File | Change |
|------|--------|
| `app/Commands/QcCommand.php` | NEW - Main qc command |
| `app/Commands/InitCommand.php` | Add hook installation + starter task |
| `resources/prompts/selfguided.md` | Simplify quality gates section |
| `resources/prompts/work.md` | Add commit enforcement section |
| `resources/prompts/merge.md` | Add commit enforcement section |
| `app/Commands/GuidelinesCommand.php` | Add --no-verify prohibition |
| `app/Services/PromptService.php` | Add 'qc-starter' to prompt names (if needed) |
| `tests/Feature/QcCommandTest.php` | NEW - Test qc command |
| `tests/Feature/InitCommandTest.php` | Update for hook installation |

### Testing Strategy

1. **QcCommand tests**:
   - No staged files → exit 0
   - No quality-gate file → warn, exit 0
   - quality-gate passes → exit 0, files re-added
   - quality-gate fails → exit non-zero
   - --check flag → no re-add behavior
   - Not a git repo → exit 1

2. **InitCommand tests**:
   - Creates pre-commit hook if missing
   - Appends to existing hook
   - Skips if already installed
   - Uses `git rev-parse --git-path hooks` (custom hooks path)
   - Creates quality-gate starter task

3. **Integration test**:
   - Full flow: stage files → commit → qc runs → formatter changes file → re-staged → commit succeeds

### Future Enhancements (out of scope)

- Auto-detect quality gates during init (backlog item f-fa8e77)
- pre-push hook for expensive tests
- `fuel qc:init` interactive setup wizard
- Support `.fuel/quality-gate.d/` directory for multiple scripts


## Acceptance Criteria

- [ ] QcCommand exists at `app/Commands/QcCommand.php` with signature `qc {--check}`
- [ ] `fuel qc` with no staged files exits 0 silently
- [ ] `fuel qc` outside a git repo errors and exits 1
- [ ] `fuel qc` with missing `.fuel/quality-gate` warns and exits 0 (doesn't block)
- [ ] `fuel qc` runs `.fuel/quality-gate` script and exits with its status code
- [ ] `fuel qc` re-adds originally staged files after successful quality-gate run (not with `--check`)
- [ ] QcCommand has Pest tests covering: no staged files, missing quality-gate, pass with re-add, fail, --check mode
- [ ] InitCommand installs pre-commit hook calling `fuel qc` (creates new or appends to existing)
- [ ] InitCommand creates quality-gate starter task when no tasks exist (after reality.md task)
- [ ] InitCommand hook installation is idempotent (skips if `fuel qc` already present)
- [ ] `resources/prompts/selfguided.md` version bumped, QUALITY GATES replaced with COMMITS section + FORBIDDEN clause
- [ ] `resources/prompts/work.md` version bumped, COMMITS section + FORBIDDEN clause added
- [ ] `resources/prompts/merge.md` version bumped, quality gates section updated + FORBIDDEN clause added
- [ ] GuidelinesCommand adds `--no-verify` prohibition to AGENTS.md output
- [ ] All existing tests pass (`./vendor/bin/pest --parallel --compact`)
- [ ] Manual test: stage a file, run `fuel qc`, verify it warns about missing quality-gate and exits 0

## Progress Log

<!-- Self-guided task appends progress entries here -->

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->
