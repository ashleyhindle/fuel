<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Run;
use App\Models\Task;

class TaskPromptBuilder
{
    public function __construct(
        private readonly RunService $runService
    ) {}

    public function build(Task $task, string $cwd): string
    {
        $taskId = $task->short_id;
        $taskDetails = $this->formatTaskForPrompt($task);

        return <<<PROMPT
IMPORTANT: You are being orchestrated. Trust the system.

== YOUR ASSIGNMENT ==
You are assigned EXACTLY ONE task: {$taskId}
You must ONLY work on this task. Nothing else.

== TASK DETAILS ==
{$taskDetails}

== TEAMWORK - YOU ARE NOT ALONE ==
You are ONE agent in a team working in parallel on this codebase.
Other teammates are working on other tasks RIGHT NOW. They're counting on you to:
- Stay in your lane (only work on YOUR assigned task)
- Not step on their toes (don't touch tasks assigned to others)
- Be a good teammate (log discovered work for others, don't hoard it)

Breaking these rules wastes your teammates' work and corrupts the workflow:

FORBIDDEN - DO NOT DO THESE:
- NEVER run `fuel start` on ANY task (your task is already started)
- NEVER run `fuel ready` or `fuel board` (you don't need to see other tasks)
- NEVER work on tasks other than {$taskId}, even if you see them
- NEVER "help" by picking up additional work - other agents will handle it

ALLOWED:
- `fuel add "..."` to LOG discovered work for OTHER agents to do later
- `fuel done {$taskId}` to mark YOUR task complete
- `fuel dep:add {$taskId} <other-task>` to add dependencies to YOUR task

== WHEN BLOCKED ==
If you need human input (credentials, decisions, file permissions):
1. ./fuel add 'What you need' --labels=needs-human --description='Exact steps for human'
2. ./fuel dep:add {$taskId} <needs-human-task-id>
3. Exit immediately - do NOT wait or retry

== CLOSING PROTOCOL ==
Before exiting, you MUST:
1. If you changed code: run tests and linter/formatter
2. Run `git status` to see modified files
3. Run `git add <files>` for each file YOU modified (not files from other agents)
4. VERIFY: `git diff --cached --stat` shows all YOUR changes are staged
5. git commit -m "feat/fix: description"
6. ./fuel done {$taskId} --commit=<hash>
7. ./fuel add "..." for any discovered/incomplete work (DO NOT work on these - just log them)

CRITICAL: If you skip git add, your work will be lost. Verify YOUR files are staged before commit.

⚠️  FILE COLLISION WARNING:
If you see files in `git status` that you did NOT modify, DO NOT stage them with `git add`.
Other agents may have modified those files while you were working. Only stage files YOU changed.

CRITICAL - If you worked on the same file as another agent:
- DO NOT remove, overwrite, or undo their changes
- DO NOT assume your version is correct and theirs is wrong
- Use `git diff <file>` to see ALL changes in the file
- Preserve ALL changes from both agents - merge them together if needed
- If you cannot safely merge, create a needs-human task and block yourself
- When in doubt, preserve other agents' work - it's easier to add than to recover deleted work

== CONTEXT ==
Working directory: {$cwd}
Task ID: {$taskId}
PROMPT;
    }

    private function formatTaskForPrompt(Task $task): string
    {
        $status = $task->status instanceof TaskStatus ? $task->status->value : $task->status;
        $lines = [
            'Task: '.$task->short_id,
            'Title: '.$task->title,
            'Status: '.$status,
        ];

        $this->appendEpicInfo($lines, $task);

        if (! empty($task->description)) {
            $lines[] = 'Description: '.$task->description;
        }

        if (! empty($task->type)) {
            $lines[] = 'Type: '.$task->type;
        }

        if (! empty($task->priority)) {
            $lines[] = 'Priority: P'.$task->priority;
        }

        if (! empty($task->labels)) {
            $lines[] = 'Labels: '.implode(', ', $task->labels);
        }

        if (! empty($task->blocked_by)) {
            $lines[] = 'Blocked by: '.implode(', ', $task->blocked_by);
        }

        $this->appendReviewIssues($lines, $task);
        $this->appendRetryInfo($lines, $task);

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function appendEpicInfo(array &$lines, Task $task): void
    {
        if (empty($task->epic_id)) {
            return;
        }

        $epic = $task->epic;
        if (! $epic instanceof Epic) {
            return;
        }

        $lines[] = '';
        $lines[] = '== EPIC CONTEXT ==';
        $lines[] = 'This task is part of a larger epic:';
        $lines[] = 'Epic: '.$epic->short_id;
        $lines[] = 'Epic Title: '.$epic->title;
        if (! empty($epic->description)) {
            $lines[] = 'Epic Description: '.$epic->description;
        }

        $lines[] = '';
        $lines[] = 'You are working on a small part of this larger epic. Understanding the epic context will help you build better solutions that align with the overall goal.';
        $lines[] = '';
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function appendReviewIssues(array &$lines, Task $task): void
    {
        if (empty($task->last_review_issues)) {
            return;
        }

        $lines[] = '';
        $lines[] = '⚠️ PREVIOUS ATTEMPT FAILED REVIEW';
        $lines[] = 'You ALREADY completed this task, but a reviewer found issues:';
        foreach ($task->last_review_issues as $issue) {
            $lines[] = '  - '.$issue;
        }

        $lines[] = '';
        $lines[] = 'DO NOT redo the entire task from scratch.';
        $lines[] = 'ONLY fix the specific issues listed above, then run the closing protocol again.';
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function appendRetryInfo(array &$lines, Task $task): void
    {
        $latestRun = $this->runService->getLatestRun($task->short_id);
        if (! $latestRun instanceof Run) {
            return;
        }

        if (! $latestRun->isFailed() && ($latestRun->exit_code === null || $latestRun->exit_code === 0)) {
            return;
        }

        $lines[] = '';
        $lines[] = 'PREVIOUS RUN FAILED';
        $lines[] = 'Run ID: '.$latestRun->short_id;

        if ($latestRun->error_type !== null) {
            $lines[] = 'Failure type: '.$latestRun->error_type->value;
        }

        if ($latestRun->exit_code !== null) {
            $lines[] = 'Exit code: '.$latestRun->exit_code;
        }

        if ($latestRun->ended_at !== null) {
            $lines[] = 'Ended at: '.$latestRun->ended_at->toIso8601String();
        }

        $lines[] = 'Check run output in .fuel/processes/'.$latestRun->short_id.'/stdout.log';
    }
}
