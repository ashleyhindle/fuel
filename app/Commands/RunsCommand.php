<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\RunService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class RunsCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'runs
        {id : The task ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--last : Show only the latest run with full output}';

    protected $description = 'View task execution history';

    public function handle(TaskService $taskService, RunService $runService): int
    {
        $this->configureCwd($taskService);

        try {
            // Validate task exists
            $task = $taskService->find($this->argument('id'));

            if ($task === null) {
                return $this->outputError("Task '{$this->argument('id')}' not found");
            }

            $taskId = $task['id'];

            // Get runs
            if ($this->option('last')) {
                $latestRun = $runService->getLatestRun($taskId);

                if ($latestRun === null) {
                    return $this->outputError("No runs found for task '{$taskId}'");
                }

                // Calculate duration
                $duration = $this->calculateDuration($latestRun['started_at'] ?? null, $latestRun['ended_at'] ?? null);

                // Prepare run data with duration
                $runData = $latestRun;
                $runData['duration'] = $duration;

                if ($this->option('json')) {
                    $this->outputJson($runData);
                } else {
                    $this->info("Latest run for task {$taskId}:");
                    $this->newLine();
                    $this->displayRunDetails($runData, true);
                }
            } else {
                $runs = $runService->getRuns($taskId);

                if (empty($runs)) {
                    return $this->outputError("No runs found for task '{$taskId}'");
                }

                // Calculate duration for each run
                $runsWithDuration = array_map(function (array $run) {
                    $duration = $this->calculateDuration($run['started_at'] ?? null, $run['ended_at'] ?? null);
                    $run['duration'] = $duration;

                    return $run;
                }, $runs);

                if ($this->option('json')) {
                    $this->outputJson($runsWithDuration);
                } else {
                    $this->info("Runs for task {$taskId} (".count($runs).'):');
                    $this->newLine();

                    $headers = ['Run ID', 'Agent', 'Model', 'Started At', 'Duration', 'Exit', 'Cost', 'Session'];
                    $rows = array_map(function (array $run) {
                        $sessionId = $run['session_id'] ?? '';
                        $shortSession = $sessionId ? substr($sessionId, 0, 8).'...' : '';

                        return [
                            $run['run_id'] ?? '',
                            $run['agent'] ?? '',
                            $run['model'] ?? '',
                            $this->formatDateTime($run['started_at'] ?? ''),
                            $run['duration'] ?? '',
                            $run['exit_code'] !== null ? (string) $run['exit_code'] : '',
                            isset($run['cost_usd']) ? '$'.number_format($run['cost_usd'], 2) : '',
                            $shortSession,
                        ];
                    }, $runsWithDuration);

                    $this->table($headers, $rows);
                }
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        }
    }

    /**
     * Calculate duration between two timestamps.
     */
    private function calculateDuration(?string $startedAt, ?string $endedAt): string
    {
        if ($startedAt === null) {
            return '';
        }

        try {
            $start = new \DateTime($startedAt);
            $end = $endedAt !== null ? new \DateTime($endedAt) : new \DateTime;

            $diff = $start->diff($end);

            // Format duration
            $parts = [];

            if ($diff->days > 0) {
                $parts[] = $diff->days.'d';
            }

            if ($diff->h > 0) {
                $parts[] = $diff->h.'h';
            }

            if ($diff->i > 0) {
                $parts[] = $diff->i.'m';
            }

            if ($diff->s > 0 || empty($parts)) {
                $parts[] = $diff->s.'s';
            }

            return implode(' ', $parts);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Format a datetime string for display.
     */
    private function formatDateTime(string $dateTimeString): string
    {
        if ($dateTimeString === '') {
            return '';
        }

        try {
            $date = new \DateTime($dateTimeString);

            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return $dateTimeString;
        }
    }

    /**
     * Display detailed run information.
     *
     * @param  array<string, mixed>  $run
     */
    private function displayRunDetails(array $run, bool $showOutput = false): void
    {
        $this->line("  Run ID: {$run['run_id']}");

        if (isset($run['agent']) && $run['agent'] !== null) {
            $this->line("  Agent: {$run['agent']}");
        }

        if (isset($run['model']) && $run['model'] !== null) {
            $this->line("  Model: {$run['model']}");
        }

        if (isset($run['started_at']) && $run['started_at'] !== null) {
            $this->line("  Started: {$this->formatDateTime($run['started_at'])}");
        }

        if (isset($run['ended_at']) && $run['ended_at'] !== null) {
            $this->line("  Ended: {$this->formatDateTime($run['ended_at'])}");
        }

        if (isset($run['duration']) && $run['duration'] !== '') {
            $this->line("  Duration: {$run['duration']}");
        }

        if (isset($run['exit_code']) && $run['exit_code'] !== null) {
            $exitColor = $run['exit_code'] === 0 ? 'green' : 'red';
            $this->line("  Exit code: <fg={$exitColor}>{$run['exit_code']}</>");
        }

        if (isset($run['cost_usd']) && $run['cost_usd'] !== null) {
            $this->line('  Cost: $'.number_format($run['cost_usd'], 4));
        }

        if (isset($run['session_id']) && $run['session_id'] !== null) {
            $this->line("  Session: {$run['session_id']}");
            $this->newLine();
            $this->line("  <fg=green>Resume:</> claude --resume {$run['session_id']}");
        }

        if ($showOutput && isset($run['output']) && $run['output'] !== null && $run['output'] !== '') {
            $this->newLine();
            $this->line('  <fg=cyan>── Output ──</>');
            // Indent each line of output
            $outputLines = explode("\n", $run['output']);
            foreach ($outputLines as $line) {
                $this->line("  {$line}");
            }
        }
    }
}
