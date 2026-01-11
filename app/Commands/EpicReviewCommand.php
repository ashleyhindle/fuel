<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\EpicStatus;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
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

    public function handle(FuelContext $context, DatabaseService $dbService, TaskService $taskService): int
    {
        // Configure context with --cwd if provided
        $this->configureCwd($context);

        // Reconfigure DatabaseService if context path changed
        if ($this->option('cwd')) {
            $dbService->setDatabasePath($context->getDatabasePath());
        }

        $epicService = new EpicService($dbService, $taskService);

        try {
            // Load epic and validate it exists
            $epic = $epicService->getEpic($this->argument('epicId'));

            if ($epic === null) {
                return $this->outputError(sprintf("Epic '%s' not found", $this->argument('epicId')));
            }

            // Load all linked tasks
            $tasks = $epicService->getTasksForEpic($epic['id']);

            // Collect commit hashes from tasks
            $commits = [];
            foreach ($tasks as $task) {
                if (isset($task['commit_hash']) && is_string($task['commit_hash']) && $task['commit_hash'] !== '') {
                    $commits[] = [
                        'hash' => $task['commit_hash'],
                        'task_id' => $task['id'],
                        'task_title' => $task['title'],
                    ];
                }
            }

            // Get git information if we have commits
            $gitStats = null;
            $gitDiff = null;
            $commitMessages = [];

            if (! empty($commits)) {
                $commitHashes = array_column($commits, 'hash');
                $firstCommit = $commitHashes[0];
                $lastCommit = $commitHashes[count($commitHashes) - 1];

                // Get combined diff stats
                $gitStats = $this->getGitStats($firstCommit, $lastCommit);

                // Get full diff if requested
                if ($this->option('diff')) {
                    $gitDiff = $this->getGitDiff($firstCommit, $lastCommit);
                }

                // Get commit messages
                $commitMessages = $this->getCommitMessages($commitHashes);
            }

            // JSON output
            if ($this->option('json')) {
                $output = [
                    'epic' => $epic,
                    'tasks' => $tasks,
                    'commits' => $commits,
                    'git_stats' => $gitStats,
                ];

                if ($this->option('diff')) {
                    $output['git_diff'] = $gitDiff;
                }

                $output['commit_messages'] = $commitMessages;

                $this->outputJson($output);

                return self::SUCCESS;
            }

            // Text output
            $this->displayEpicReview($epic, $tasks, $commits, $gitStats, $gitDiff, $commitMessages);

            // Prompt to mark as reviewed (unless --no-prompt is set)
            if (! $this->option('no-prompt')) {
                if ($this->confirm('Mark this epic as reviewed?', false)) {
                    $epicService->markAsReviewed($epic['id']);
                    $this->info(sprintf('Epic %s marked as reviewed', $epic['id']));
                }
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
     * @param  array<string, mixed>  $epic
     * @param  array<array<string, mixed>>  $tasks
     * @param  array<array<string, mixed>>  $commits
     * @param  array<array<string, mixed>>  $commitMessages
     */
    private function displayEpicReview(
        array $epic,
        array $tasks,
        array $commits,
        ?string $gitStats,
        ?string $gitDiff,
        array $commitMessages
    ): void {
        // Epic header
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info(sprintf('Epic Review: %s', $epic['id']));
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        // Epic details
        $this->line(sprintf('<fg=cyan>Title:</> %s', $epic['title'] ?? 'Untitled'));
        $this->line(sprintf('<fg=cyan>Status:</> %s', $epic['status'] ?? EpicStatus::Planning->value));

        if (isset($epic['description']) && $epic['description'] !== null) {
            $this->newLine();
            $this->line('<fg=cyan>Description:</>');
            $this->line($epic['description']);
        }

        // Task list
        $this->newLine();
        $this->line(sprintf('<fg=cyan>Tasks</> (%d total):', count($tasks)));
        $this->newLine();

        if (empty($tasks)) {
            $this->line('  <fg=yellow>No tasks linked to this epic.</>');
        } else {
            $headers = ['ID', 'Title', 'Status', 'Commit'];
            $rows = array_map(function (array $task): array {
                return [
                    $task['id'] ?? '',
                    $task['title'] ?? '',
                    $task['status'] ?? 'open',
                    $task['commit_hash'] ?? '-',
                ];
            }, $tasks);

            $this->table($headers, $rows);
        }

        // Commit information
        if (! empty($commits)) {
            $this->newLine();
            $this->line(sprintf('<fg=cyan>Commits</> (%d total):', count($commits)));
            $this->newLine();

            foreach ($commitMessages as $commitInfo) {
                $this->line(sprintf('  <fg=yellow>%s</>', $commitInfo['hash']));
                $lines = explode("\n", trim($commitInfo['message']));
                foreach ($lines as $line) {
                    $this->line(sprintf('    %s', $line));
                }
                $this->newLine();
            }

            // Git stats
            if ($gitStats !== null) {
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
                $this->line($gitDiff);
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
    private function getGitStats(string $firstCommit, string $lastCommit): ?string
    {
        try {
            $process = new Process(['git', 'diff', '--stat', $firstCommit.'^..'.$lastCommit]);
            $process->run();

            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }

            return sprintf('Failed to get git stats: %s', trim($process->getErrorOutput()));
        } catch (\Throwable $e) {
            return sprintf('Failed to get git stats: %s', $e->getMessage());
        }
    }

    /**
     * Get full git diff for a range of commits.
     */
    private function getGitDiff(string $firstCommit, string $lastCommit): ?string
    {
        try {
            $process = new Process(['git', 'diff', $firstCommit.'^..'.$lastCommit]);
            $process->run();

            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }

            return sprintf('Failed to get git diff: %s', trim($process->getErrorOutput()));
        } catch (\Throwable $e) {
            return sprintf('Failed to get git diff: %s', $e->getMessage());
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
}
