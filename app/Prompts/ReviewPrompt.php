<?php

declare(strict_types=1);

namespace App\Prompts;

class ReviewPrompt
{
    /**
     * Maximum character limit for git diff before truncation.
     */
    private const MAX_DIFF_CHARS = 5000;

    /**
     * Generate the review prompt for a reviewing agent.
     *
     * @param  array<string, mixed>  $task  The task data array
     * @param  string  $gitDiff  The git diff output
     * @param  string  $gitStatus  The git status output
     */
    public function generate(array $task, string $gitDiff, string $gitStatus): string
    {
        $taskId = $task['id'] ?? 'unknown';
        $title = $task['title'] ?? 'Untitled task';
        $description = $task['description'] ?? 'No description provided';

        $truncatedDiff = $this->truncateDiff($gitDiff);

        return <<<PROMPT
# Review Task: {$taskId}

You are reviewing the work done on task **{$taskId}**: {$title}

## Task Description

{$description}

## Git Status

```
{$gitStatus}
```

## Git Diff

```diff
{$truncatedDiff}
```

---

## Review Checklist

Complete each check in order. If any check fails, create the appropriate follow-up task and stop.

### 1. CHECK UNCOMMITTED CHANGES

Look at the git status output above. If there are uncommitted changes (modified files, untracked files that should be committed):

```bash
fuel add 'Commit uncommitted changes from {$taskId}' --blocked-by={$taskId} --priority=0 --complexity=trivial --labels=review-fix
```

### 2. VERIFY RELEVANT TESTS

If the changes affect code that has tests, run those relevant tests to verify they pass.
Don't run the entire test suite - only tests related to the files that were changed.

If relevant tests fail:

```bash
fuel add 'Fix failing tests from {$taskId}' --blocked-by={$taskId} --priority=0 --complexity=simple --labels=review-fix --description='[Describe which tests failed and why]'
```

### 3. CHECK TASK COMPLETION

Compare the git diff to the task description above.

- Does the change actually address what was asked?
- Are there any missing requirements from the description?
- Is the implementation complete?

If the task is incomplete, create a follow-up task with specific missing items:

```bash
fuel add 'Complete missing work from {$taskId}: [describe what is missing]' --blocked-by={$taskId} --priority=0 --complexity=simple --labels=review-fix --description='[Specific details of what is missing or incomplete]'
```

### 4. REPORT RESULT

**If ALL checks pass** (no uncommitted changes, tests pass, task is complete):

```bash
fuel done {$taskId}
```

**If ANY issues were found**: The follow-up tasks you created handle the issues. The original task stays in review status - do NOT mark it as done.

---

## Important Notes

- Only create follow-up tasks for actual issues found
- Be specific in follow-up task descriptions
- Use `--blocked-by={$taskId}` so follow-ups are properly linked
- Use `--labels=review-fix` to track review-created tasks
PROMPT;
    }

    /**
     * Truncate the diff if it exceeds the maximum character limit.
     */
    private function truncateDiff(string $diff): string
    {
        if (strlen($diff) <= self::MAX_DIFF_CHARS) {
            return $diff;
        }

        $truncated = substr($diff, 0, self::MAX_DIFF_CHARS);

        // Try to truncate at a newline to avoid cutting mid-line
        $lastNewline = strrpos($truncated, "\n");
        if ($lastNewline !== false && $lastNewline > self::MAX_DIFF_CHARS * 0.8) {
            $truncated = substr($truncated, 0, $lastNewline);
        }

        $remainingChars = strlen($diff) - strlen($truncated);

        return $truncated."\n\n... [TRUNCATED: {$remainingChars} more characters] ...";
    }
}
