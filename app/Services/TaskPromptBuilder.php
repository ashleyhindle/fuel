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
                'closing_protocol' => $this->buildClosingProtocol($task->short_id),
            ],
            'cwd' => $cwd,
        ];

        return $this->promptService->render($template, $variables);
    }

    /**
     * Build the closing protocol (same for all tasks - each task commits).
     */
    private function buildClosingProtocol(string $taskId): string
    {
        return <<<PROTOCOL
== CLOSING PROTOCOL ==
Before exiting, you MUST:
1. If you changed code: you must ensure your changes pass all quality gates and is tested before moving on to the next step:
    - Linter
    - Formatter
    - Unit tests
    - Feature tests
    - Smoke test (use what you've built if possible)
    - Verify it works and does what's expected
2. Run `git status` to see modified files
3. Run `git add <files>` for each file YOU modified (not files from other agents)
4. VERIFY: `git diff --cached --stat` shows all YOUR changes are staged
5. git commit -m "feat/fix: description"
6. fuel done {$taskId} --commit=<hash>
7. fuel add "..." for any discovered/incomplete work (DO NOT work on these - just log them)

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
        $lines[] = '  - Mark off parts you completed';
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
