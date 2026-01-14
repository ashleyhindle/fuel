<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class ReviewCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'review
        {id : The task/epic/review ID (supports partial matching)}
        {--diff : Show full diff instead of just stats}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--raw : Show raw stdout output instead of truncated (for review IDs)}
        {--no-prompt : Skip the reviewed prompt (for epic IDs)}';

    protected $description = 'Show review information for a task, epic, or review (routes based on ID)';

    public function handle(TaskService $taskService): int
    {
        $id = $this->argument('id');

        // Route to appropriate command based on ID prefix
        if (str_starts_with($id, 'e-')) {
            // Epic review - shows all tasks with diffs
            return $this->call('epic:review', [
                'epicId' => $id,
                '--diff' => $this->option('diff'),
                '--no-prompt' => $this->option('no-prompt'),
                '--cwd' => $this->option('cwd'),
                '--json' => $this->option('json'),
            ]);
        }

        if (str_starts_with($id, 'r-') || str_starts_with($id, 'review-')) {
            // Review details
            return $this->call('review:show', [
                'id' => $id,
                '--cwd' => $this->option('cwd'),
                '--json' => $this->option('json'),
                '--raw' => $this->option('raw'),
            ]);
        }

        // Default to task review
        try {
            $task = $taskService->find($id);

            if (! $task instanceof Task) {
                return $this->outputError(sprintf("Task '%s' not found", $id));
            }

            // Get git information if task has a commit
            $gitStats = null;
            $gitDiff = null;
            $commitMessage = null;
            $commitSubject = null;

            if (isset($task->commit_hash) && is_string($task->commit_hash) && $task->commit_hash !== '') {
                // Get commit subject (first line)
                $commitSubject = $this->getCommitSubject($task->commit_hash);

                // Get full commit message
                $commitMessage = $this->getCommitMessage($task->commit_hash);

                // Get diff stats
                $gitStats = $this->getGitStats($task->commit_hash);

                // Get full diff if requested
                if ($this->option('diff')) {
                    $gitDiff = $this->getGitDiff($task->commit_hash);
                }
            }

            // JSON output
            if ($this->option('json')) {
                $output = [
                    'task' => $task->toArray(),
                    'commit_hash' => $task->commit_hash ?? null,
                    'commit_subject' => $commitSubject,
                    'commit_message' => $commitMessage,
                    'git_stats' => $gitStats,
                ];

                if ($this->option('diff')) {
                    $output['git_diff'] = $gitDiff;
                }

                $this->outputJson($output);

                return self::SUCCESS;
            }

            // Text output
            $this->displayTaskReview($task, $commitSubject, $commitMessage, $gitStats, $gitDiff);

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        } catch (\Exception $e) {
            return $this->outputError('Failed to review task: '.$e->getMessage());
        }
    }

    /**
     * Display the task review in text format.
     */
    private function displayTaskReview(
        Task $task,
        ?string $commitSubject,
        ?string $commitMessage,
        ?string $gitStats,
        ?string $gitDiff
    ): void {
        // Task header
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info(sprintf('Task Review: %s', $task->short_id));
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        // Task details
        $this->line(sprintf('<fg=cyan>Title:</> %s', $task->title ?? 'Untitled'));
        $this->line(sprintf('<fg=cyan>Status:</> %s', $task->status->value));

        if (isset($task->description) && $task->description !== null) {
            $this->newLine();
            $this->line('<fg=cyan>Description:</>');
            // Handle multiline descriptions
            $descriptionLines = explode("\n", $task->description);
            foreach ($descriptionLines as $line) {
                $this->line('  '.$line);
            }
        }

        if (isset($task->type)) {
            $this->line(sprintf('<fg=cyan>Type:</> %s', $task->type));
        }

        if (isset($task->priority)) {
            $this->line(sprintf('<fg=cyan>Priority:</> %s', $task->priority));
        }

        // Git information
        if ($task->commit_hash !== null) {
            $this->newLine();
            $this->line('<fg=cyan>Commit Information</>');
            $this->newLine();

            if ($commitSubject !== null) {
                $this->line(sprintf('  <fg=green>%s</>', $commitSubject));
            } else {
                $this->line(sprintf('  <fg=green>%s</>', $task->commit_hash));
            }

            if ($commitMessage !== null && $commitMessage !== $commitSubject) {
                $this->newLine();
                $this->line('<fg=cyan>Commit Message:</>');
                $this->newLine();
                // Indent all lines of commit message
                $messageLines = explode("\n", $commitMessage);
                foreach ($messageLines as $line) {
                    $this->line('  '.$line);
                }
            }

            // Git stats
            if ($gitStats !== null) {
                $this->newLine();
                $this->line('<fg=cyan>Diff Stats:</>');
                $this->newLine();
                // Indent all lines of git stats output
                $statsLines = explode("\n", $gitStats);
                foreach ($statsLines as $line) {
                    $this->line('  '.trim($line));
                }
            }

            // Full diff if requested
            if ($gitDiff !== null) {
                $this->newLine();
                $this->line('<fg=cyan>Full Diff:</>');
                $this->newLine();
                $this->getOutput()->writeln($gitDiff, OutputInterface::OUTPUT_RAW);
            }
        } else {
            $this->newLine();
            $this->line('<fg=yellow>No commit associated with this task.</>');
        }

        $this->newLine();
    }

    /**
     * Get git diff stats for a single commit.
     */
    private function getGitStats(string $commit): ?string
    {
        try {
            $process = new Process(['git', 'show', '--stat', $commit]);
            $process->run();

            if ($process->isSuccessful()) {
                $output = trim($process->getOutput());
                // Remove the commit message part, just keep the stats
                $lines = explode("\n", $output);
                $statsStarted = false;
                $statsLines = [];

                foreach ($lines as $line) {
                    // Stats typically start after an empty line following the commit message
                    if ($statsStarted && (str_contains($line, '|') || str_contains($line, 'changed'))) {
                        $statsLines[] = $line;
                    } elseif (trim($line) === '' && ! $statsStarted && count($lines) > 5) {
                        $statsStarted = true;
                    }
                }

                return implode("\n", $statsLines) ?: $output;
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get full git diff for a single commit.
     */
    private function getGitDiff(string $commit): ?string
    {
        try {
            $process = new Process(['git', 'show', '--color=always', $commit]);
            $process->run();

            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get commit message for a single commit.
     */
    private function getCommitMessage(string $commit): ?string
    {
        try {
            $process = new Process(['git', 'log', '-1', '--pretty=format:%B', $commit]);
            $process->run();

            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get commit subject line (first line) for a single commit.
     */
    private function getCommitSubject(string $commit): ?string
    {
        try {
            $process = new Process(['git', 'log', '-1', '--pretty=format:%h %s', $commit]);
            $process->run();

            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}
