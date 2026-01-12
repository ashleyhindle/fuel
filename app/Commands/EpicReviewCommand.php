<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\EpicStatus;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class EpicReviewCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:review
        {epicId : The epic ID (supports partial matching)}
        {--diff : Show full diff instead of just stats}
        {--no-prompt : Skip the reviewed prompt}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Show epic with commits and diffs';

    public function handle(FuelContext $context, DatabaseService $dbService, TaskService $taskService, EpicService $epicService): int
    {
        // Configure context with --cwd if provided
        $this->configureCwd($context, $dbService);

        try {
            // Load epic and validate it exists
            $epic = $epicService->getEpic($this->argument('epicId'));

            if (! $epic instanceof Epic) {
                return $this->outputError(sprintf("Epic '%s' not found", $this->argument('epicId')));
            }

            // Load all linked tasks
            $tasks = $epicService->getTasksForEpic($epic->id);

            // Collect commit hashes from tasks
            $commits = [];
            foreach ($tasks as $task) {
                if (isset($task->commit_hash) && is_string($task->commit_hash) && $task->commit_hash !== '') {
                    $commits[] = [
                        'hash' => $task->commit_hash,
                        'task_id' => $task->short_id,
                        'task_title' => $task->title,
                    ];
                }
            }

            // Get git information if we have commits
            $gitStats = null;
            $gitDiff = null;
            $commitMessages = [];
            $commitSubjects = [];

            if ($commits !== []) {
                // Sort commits by git timestamp to ensure correct chronological order
                $commits = $this->sortCommitsByTimestamp($commits);
                $commitHashes = array_column($commits, 'hash');
                $firstCommit = $commitHashes[0];
                $lastCommit = $commitHashes[count($commitHashes) - 1];

                // Get combined diff stats
                $gitStats = $this->getGitStats($firstCommit, $lastCommit);

                // Get full diff if requested
                if ($this->option('diff')) {
                    $gitDiff = $this->getGitDiff($firstCommit, $lastCommit);
                }

                // Get commit subjects (first line only) for table display
                $commitSubjects = $this->getCommitSubjects($commitHashes);

                // Get full commit messages
                $commitMessages = $this->getCommitMessages($commitHashes);
            }

            // JSON output
            if ($this->option('json')) {
                $output = [
                    'epic' => $epic->toArray(),
                    'tasks' => array_map(fn (Task $task): array => $task->toArray(), $tasks),
                    'commits' => $commits,
                    'git_stats' => $gitStats,
                    'commit_messages' => $commitMessages,
                ];

                if ($this->option('diff')) {
                    $output['git_diff'] = $gitDiff;
                }

                $output['commit_subjects'] = $commitSubjects;

                $this->outputJson($output);

                return self::SUCCESS;
            }

            // Get first run times for task ordering
            $taskIds = array_map(fn ($t) => $t->short_id, $tasks);
            $firstRunTimes = $this->getTaskFirstRunTimes($dbService, $taskIds);

            // Text output
            $this->displayEpicReview($epic, $tasks, $commits, $gitStats, $gitDiff, $commitSubjects ?? [], $firstRunTimes);

            // Prompt to mark as reviewed (unless --no-prompt is set or output is piped)
            if (! $this->option('no-prompt') && $this->input->isInteractive() && $this->confirm('Mark this epic as reviewed?', false)) {
                $epicService->markAsReviewed($epic->short_id);
                $this->info(sprintf('Epic %s marked as reviewed', $epic->short_id));
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        } catch (\Exception $e) {
            return $this->outputError('Failed to review epic: '.$e->getMessage());
        }
    }

    /**
     * Display the epic review in text format.
     *
     * @param  array<array<string, mixed>>  $tasks
     * @param  array<array<string, mixed>>  $commits
     * @param  array<string, string>  $commitSubjects  Hash => subject line
     * @param  array<int, string>  $firstRunTimes  Task ID => first run started_at
     */
    private function displayEpicReview(
        object $epic,
        array $tasks,
        array $commits,
        ?string $gitStats,
        ?string $gitDiff,
        array $commitSubjects,
        array $firstRunTimes = []
    ): void {
        // Epic header
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info(sprintf('Epic Review: %s', $epic->short_id));
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        // Epic details
        $this->line(sprintf('<fg=cyan>Title:</> %s', $epic->title ?? 'Untitled'));
        $this->line(sprintf('<fg=cyan>Status:</> %s', $epic->status ?? EpicStatus::Planning->value));

        if (isset($epic->description) && $epic->description !== null) {
            $this->newLine();
            $this->line('<fg=cyan>Description:</>');
            $this->line($epic->description);
        }

        // Task list
        $this->newLine();
        $this->line(sprintf('<fg=cyan>Tasks</> (%d total):', count($tasks)));
        $this->newLine();

        if ($tasks === []) {
            $this->line('  <fg=yellow>No tasks linked to this epic.</>');
        } else {
            // Sort tasks by first run time (oldest first), fallback to updated_at
            usort($tasks, function (Task $a, Task $b) use ($firstRunTimes): int {
                $timeA = $firstRunTimes[$a->short_id] ?? $a->updated_at ?? '';
                $timeB = $firstRunTimes[$b->short_id] ?? $b->updated_at ?? '';

                return $timeA <=> $timeB;
            });

            $termWidth = $this->getTerminalWidth();
            // Reserve space for ID (~10), Title (~40), Status (~12), borders/padding (~25)
            $commitMaxWidth = max(20, $termWidth - 87);

            $headers = ['ID', 'Title', 'Status', 'Commit'];
            $rows = array_map(function (Task $task) use ($commitSubjects, $commitMaxWidth): array {
                $commit = '-';
                if (isset($task->commit_hash) && $task->commit_hash !== '') {
                    $commit = $commitSubjects[$task->commit_hash] ?? $task->commit_hash;
                    if (strlen($commit) > $commitMaxWidth) {
                        $commit = substr($commit, 0, $commitMaxWidth - 3).'...';
                    }
                }

                return [
                    $task->short_id ?? '',
                    $task->title ?? '',
                    $task->status ?? TaskStatus::Open->value,
                    $commit,
                ];
            }, $tasks);

            $this->table($headers, $rows);
        }

        // Git information
        if ($commits !== []) {
            // Commits section
            $this->newLine();
            $this->line('<fg=cyan>Commits</>');
            $this->newLine();
            foreach ($commits as $commit) {
                $this->line(sprintf('  <fg=green>%s</> - <fg=blue>%s</>', $commit['hash'], $commit['task_title']));
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

                $this->newLine();
            }

            // Full diff if requested
            if ($gitDiff !== null) {
                $this->line('<fg=cyan>Full Diff:</>');
                $this->newLine();
                $this->output->writeln($gitDiff, OutputInterface::OUTPUT_RAW);
                $this->newLine();
            }
        } else {
            $this->newLine();
            $this->line('<fg=yellow>No commits associated with tasks in this epic.</>');
        }
    }

    /**
     * Get git diff stats for a range of commits.
     */
    private function getGitStats(string $firstCommit, string $lastCommit): string
    {
        try {
            $process = new Process(['git', 'diff', '--stat', $firstCommit.'^..'.$lastCommit]);
            $process->run();

            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }

            return sprintf('Failed to get git stats: %s', trim($process->getErrorOutput()));
        } catch (\Throwable $throwable) {
            return sprintf('Failed to get git stats: %s', $throwable->getMessage());
        }
    }

    /**
     * Get full git diff for a range of commits.
     */
    private function getGitDiff(string $firstCommit, string $lastCommit): string
    {
        try {
            $process = new Process(['git', 'diff', '--color=always', $firstCommit.'^..'.$lastCommit]);
            $process->run();

            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }

            return sprintf('Failed to get git diff: %s', trim($process->getErrorOutput()));
        } catch (\Throwable $throwable) {
            return sprintf('Failed to get git diff: %s', $throwable->getMessage());
        }
    }

    /**
     * Get commit messages for a list of commit hashes.
     *
     * @param  array<string>  $commitHashes
     * @return array<array<string, string>>
     */
    private function getCommitMessages(array $commitHashes): array
    {
        $messages = [];

        foreach ($commitHashes as $hash) {
            try {
                $process = new Process(['git', 'log', '-1', '--pretty=format:%B', $hash]);
                $process->run();

                if ($process->isSuccessful()) {
                    $messages[] = [
                        'hash' => $hash,
                        'message' => trim($process->getOutput()),
                    ];
                } else {
                    $messages[] = [
                        'hash' => $hash,
                        'message' => sprintf('[Error: %s]', trim($process->getErrorOutput())),
                    ];
                }
            } catch (\Throwable $e) {
                $messages[] = [
                    'hash' => $hash,
                    'message' => sprintf('[Error: %s]', $e->getMessage()),
                ];
            }
        }

        return $messages;
    }

    /**
     * Get the first run started_at time for each task.
     *
     * @param  array<int>  $taskIds
     * @return array<int, string> Task ID => first run started_at
     */
    private function getTaskFirstRunTimes(DatabaseService $dbService, array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $sql = "SELECT task_id, MIN(started_at) as first_started_at
                FROM runs
                WHERE task_id IN ({$placeholders})
                GROUP BY task_id";

        try {
            $results = $dbService->fetchAll($sql, $taskIds);

            $times = [];
            foreach ($results as $row) {
                $times[(int) $row['task_id']] = $row['first_started_at'];
            }

            return $times;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get terminal width from COLUMNS env var or stty size.
     */
    private function getTerminalWidth(): int
    {
        // Try COLUMNS env var first
        $columns = getenv('COLUMNS');
        if ($columns !== false && is_numeric($columns)) {
            return (int) $columns;
        }

        // Try stty size
        try {
            $process = new Process(['stty', 'size']);
            $process->run();
            if ($process->isSuccessful()) {
                $parts = explode(' ', trim($process->getOutput()));
                if (count($parts) === 2 && is_numeric($parts[1])) {
                    return (int) $parts[1];
                }
            }
        } catch (\Throwable) {
            // Ignore
        }

        // Default fallback
        return 120;
    }

    /**
     * Get commit subject lines (first line only) for a list of commit hashes.
     *
     * @param  array<string>  $commitHashes
     * @return array<string, string> Hash => subject line
     */
    private function getCommitSubjects(array $commitHashes): array
    {
        $subjects = [];

        foreach ($commitHashes as $hash) {
            try {
                $process = new Process(['git', 'log', '-1', '--pretty=format:%h %s', $hash]);
                $process->run();

                $subjects[$hash] = $process->isSuccessful()
                    ? trim($process->getOutput())
                    : $hash;
            } catch (\Throwable) {
                $subjects[$hash] = $hash;
            }
        }

        return $subjects;
    }

    /**
     * Sort commits by their git timestamp (oldest first).
     *
     * @param  array<array<string, mixed>>  $commits
     * @return array<array<string, mixed>>
     */
    private function sortCommitsByTimestamp(array $commits): array
    {
        // Get timestamps for each commit
        foreach ($commits as &$commit) {
            try {
                $process = new Process(['git', 'log', '-1', '--format=%ct', $commit['hash']]);
                $process->run();
                $commit['timestamp'] = $process->isSuccessful() ? (int) trim($process->getOutput()) : 0;
            } catch (\Throwable) {
                $commit['timestamp'] = 0;
            }
        }

        unset($commit);

        // Sort by timestamp ascending (oldest first)
        usort($commits, fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        return $commits;
    }
}
