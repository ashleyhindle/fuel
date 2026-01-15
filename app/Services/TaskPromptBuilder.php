<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Run;
use App\Models\Task;
use Illuminate\Support\Str;

class TaskPromptBuilder
{
    public function __construct(
        private readonly RunService $runService,
        private readonly PromptService $promptService
    ) {}

    public function build(Task $task, string $cwd): string
    {
        $template = $this->promptService->loadTemplate('work');

        $variables = [
            'task' => [
                'id' => $task->short_id,
            ],
            'context' => [
                'task_details' => $this->formatTaskForPrompt($task),
                'closing_protocol' => $this->buildClosingProtocol($task, $task->short_id),
            ],
            'cwd' => $cwd,
        ];

        return $this->promptService->render($template, $variables);
    }

    /**
     * Build a prompt for the epic commit task.
     *
     * @param  array<int, Task>  $completedTasks
     */
    public function buildCommitPrompt(Task $commitTask, Epic $epic, array $completedTasks, string $cwd): string
    {
        $taskId = $commitTask->short_id;
        $epicId = $epic->short_id;
        $taskSummary = $this->formatTaskSummaryForCommit($completedTasks);

        return <<<PROMPT
IMPORTANT: You are being orchestrated. Trust the system.

== YOUR ASSIGNMENT ==
You are assigned the COMMIT task: {$taskId}
Your job is to organize and commit the staged changes for epic {$epicId}.

== EPIC CONTEXT ==
Epic: {$epicId}
Title: {$epic->title}
Description: {$epic->description}

== COMPLETED TASKS ==
{$taskSummary}

== YOUR INSTRUCTIONS ==

1. REVIEW STAGED CHANGES
   Run `git status` and `git diff --cached` to see what's staged.

   If NO changes are staged:
   - Check for unstaged changes with `git diff`
   - If there are unstaged changes, stage them with `git add`
   - If there are NO changes at all, run `./fuel done {$taskId} --reason="No changes to commit"` and exit

2. ORGANIZE INTO COMMITS
   Review the staged changes and organize them into meaningful conventional commits.

   Consider:
   - Group related changes together
   - Each commit should be atomic and self-contained
   - Use conventional commit messages: feat:, fix:, refactor:, docs:, test:, chore:
   - Reference the epic in commit messages where appropriate

   Create commits:
   ```bash
   git commit -m "feat: description of feature changes"
   git commit -m "fix: description of bug fixes"
   # etc.
   ```

3. VERIFY
   - Run tests to ensure nothing is broken: `./vendor/bin/pest --parallel --compact`
   - Run linter: `./vendor/bin/pint`
   - If tests fail, fix issues and amend commits as needed

4. COMPLETE
   Run: `./fuel done {$taskId} --commit=<last-commit-hash>`

== FORBIDDEN ==
- DO NOT start any other tasks
- DO NOT modify code beyond what's needed to fix test failures
- DO NOT push to remote (that's a separate step)

== CONTEXT ==
Working directory: {$cwd}
Task ID: {$taskId}
Epic ID: {$epicId}
PROMPT;
    }

    /**
     * Build the closing protocol based on whether task is part of an epic.
     */
    private function buildClosingProtocol(Task $task, string $taskId): string
    {
        $isEpicTask = ! empty($task->epic_id);

        if ($isEpicTask) {
            return $this->buildEpicTaskClosingProtocol($taskId);
        }

        return $this->buildStandaloneTaskClosingProtocol($taskId);
    }

    /**
     * Closing protocol for tasks that are part of an epic (stage only, no commit).
     */
    private function buildEpicTaskClosingProtocol(string $taskId): string
    {
        return <<<PROTOCOL
== CLOSING PROTOCOL (EPIC TASK) ==
Before exiting, you MUST:
1. If you changed code: run tests and linter/formatter
2. Run `git status` to see modified files
3. Run `git add <files>` for each file YOU modified (not files from other agents)
4. VERIFY: `git diff --cached --stat` shows all YOUR changes are staged
5. DO NOT commit - commits will be organized when the epic is approved
6. ./fuel done {$taskId}
7. ./fuel add "..." for any discovered/incomplete work (DO NOT work on these - just log them)

CRITICAL: Stage your changes with git add but DO NOT run git commit.
Your work is part of an epic - all changes will be committed together after epic approval.

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
PROTOCOL;
    }

    /**
     * Closing protocol for standalone tasks (full commit workflow).
     */
    private function buildStandaloneTaskClosingProtocol(string $taskId): string
    {
        return <<<PROTOCOL
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
PROTOCOL;
    }

    /**
     * Format task list for commit prompt.
     *
     * @param  array<int, Task>  $tasks
     */
    private function formatTaskSummaryForCommit(array $tasks): string
    {
        $lines = [];
        foreach ($tasks as $task) {
            $commit = $task->commit_hash ?? 'no commit';
            $lines[] = sprintf('- %s: %s [%s]', $task->short_id, $task->title, $commit);
        }

        return implode("\n", $lines);
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

        $planPath = '.fuel/plans/'.Str::kebab($epic->title).'-'.$epic->short_id.'.md';
        $lines[] = '';
        $lines[] = 'Plan file: '.$planPath;
        $lines[] = 'Read the plan for context. Update it to help subsequent agents/developers:';
        $lines[] = '  - Interfaces or contracts you created (with file paths)';
        $lines[] = '  - Patterns you established that others should follow';
        $lines[] = '  - Gotchas or mistakes to avoid';
        $lines[] = '  - Key decisions and why you made them';
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
