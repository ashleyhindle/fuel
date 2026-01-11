<?php

declare(strict_types=1);

namespace App\Prompts;

use App\Models\Task;

class ReviewPrompt
{
    /**
     * Maximum character limit for git diff before truncation.
     */
    private const MAX_DIFF_CHARS = 5000;

    /**
     * Generate the review prompt for a reviewing agent.
     *
     * @param  Task  $task  The task model
     * @param  string  $gitDiff  The git diff output
     * @param  string  $gitStatus  The git status output
     */
    public function generate(Task $task, string $gitDiff, string $gitStatus): string
    {
        $taskId = $task->id ?? 'unknown';
        $title = $task->title ?? 'Untitled task';
        $description = $task->description ?? 'No description provided';

        $truncatedDiff = $this->truncateDiff($gitDiff);

        return <<<PROMPT
# Review Task: {$taskId}
You are reviewing work. You MUST only review, you are not allowed to make any file edits, you MUST respond with one message with valid JSON as described below.

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

Complete each check and note any issues found.

### 1. CHECK UNCOMMITTED CHANGES

Look at the git status output above. Are there uncommitted changes (modified files, untracked files that should be committed)?

### 2. VERIFY RELEVANT TESTS

If the changes affect code that has tests, run those relevant tests to verify they pass.
Don't run the entire test suite - only tests related to the files that were changed.

### 3. CHECK TASK COMPLETION

Compare the git diff to the task description above.

- Does the change actually address what was asked?
- Are there any missing requirements from the description?
- Is the implementation complete?

---

## REQUIRED: Output Your Review Result

After completing your review, you MUST output a JSON block with your findings.
This is REQUIRED - the system parses this output to track review results.

**If ALL checks pass**, run:
```bash
fuel done {$taskId}
```

Then output:
```json
{"result": "pass", "issues": []}
```

**If ANY issues were found**, output (do NOT run fuel done):
```json
{"result": "fail", "issues": [{"type": "TYPE", "description": "DESCRIPTION"}]}
```

Issue types: `uncommitted_changes`, `tests_failing`, `incomplete`, `other`

Examples:

```json
{"result": "fail", "issues": [{"type": "uncommitted_changes", "description": "Modified files not committed: src/Service.php, src/Controller.php"}, {"type": "tests_failing", "description": "UserServiceTest::testCreate failed - expected 200, got 500"}]}
```

```json
{"result": "fail", "issues": [{"type": "tests_failing", "description": "UserServiceTest::testCreate failed - expected 200, got 500"}]}
```

```json
{"result": "fail", "issues": [{"type": "incomplete", "description": "Missing validation for email field as specified in requirements"}]}
```
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
