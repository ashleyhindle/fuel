<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\CalculatesDuration;
use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\Agent;
use App\Models\Run;
use App\Models\Task;
use App\Services\RunService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

class RunsCommand extends Command
{
    use CalculatesDuration;
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

            if (! $task instanceof Task) {
                return $this->outputError(sprintf("Task '%s' not found", $this->argument('id')));
            }

            $taskId = $task->id;

            // Get runs
            if ($this->option('last')) {
                $latestRun = $runService->getLatestRun($taskId);

                if (! $latestRun instanceof Run) {
                    return $this->outputError(sprintf("No runs found for task '%s'", $taskId));
                }

                // Calculate duration
                $duration = $this->calculateDuration($latestRun->started_at, $latestRun->ended_at);

                if ($this->option('json')) {
                    // Convert to array and add duration for JSON output
                    $runData = $latestRun->toArray();
                    $runData['duration'] = $duration;
                    $this->outputJson($runData);
                } else {
                    $this->info(sprintf('Latest run for task %s:', $taskId));
                    $this->newLine();
                    $this->displayRunDetails($latestRun, $duration, true);
                }
            } else {
                $runs = $runService->getRuns($taskId);

                if ($runs === []) {
                    return $this->outputError(sprintf("No runs found for task '%s'", $taskId));
                }

                if ($this->option('json')) {
                    // Convert runs to arrays with duration for JSON output
                    $runsWithDuration = array_map(function (Run $run): array {
                        $duration = $this->calculateDuration($run->started_at, $run->ended_at);
                        $runData = $run->toArray();
                        $runData['duration'] = $duration;

                        return $runData;
                    }, $runs);
                    $this->outputJson($runsWithDuration);
                } else {
                    $this->info(sprintf('Runs for task %s (', $taskId).count($runs).'):');
                    $this->newLine();

                    $headers = ['Run ID', 'Agent', 'Model', 'Started At', 'Duration', 'Exit', 'Cost', 'Session'];
                    $rows = array_map(function (Run $run): array {
                        $duration = $this->calculateDuration($run->started_at, $run->ended_at);
                        $sessionId = $run->session_id ?? '';
                        $shortSession = $sessionId ? substr($sessionId, 0, 8).'...' : '';

                        return [
                            $run->run_id ?? '',
                            $run->agent ?? '',
                            $run->model ?? '',
                            $this->formatDateTime($run->started_at ?? ''),
                            $duration,
                            $run->exit_code !== null ? (string) $run->exit_code : '',
                            $run->cost_usd !== null ? '$'.number_format($run->cost_usd, 2) : '',
                            $shortSession,
                        ];
                    }, $runs);

                    $this->table($headers, $rows);
                }
            }

            return self::SUCCESS;
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
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
        } catch (\Exception) {
            return $dateTimeString;
        }
    }

    /**
     * Display detailed run information.
     */
    private function displayRunDetails(Run $run, string $duration, bool $showOutput = false): void
    {
        $this->line('  Run ID: '.$run->run_id);

        if ($run->agent !== null) {
            $this->line('  Agent: '.$run->agent);
        }

        if ($run->model !== null) {
            $this->line('  Model: '.$run->model);
        }

        if ($run->started_at !== null) {
            $this->line('  Started: '.$this->formatDateTime($run->started_at));
        }

        if ($run->ended_at !== null) {
            $this->line('  Ended: '.$this->formatDateTime($run->ended_at));
        }

        if ($duration !== '') {
            $this->line('  Duration: '.$duration);
        }

        if ($run->exit_code !== null) {
            $exitColor = $run->exit_code === 0 ? 'green' : 'red';
            $this->line(sprintf('  Exit code: <fg=%s>%s</>', $exitColor, $run->exit_code));
        }

        if ($run->cost_usd !== null) {
            $this->line('  Cost: $'.number_format($run->cost_usd, 4));
        }

        if ($run->session_id !== null) {
            $this->line('  Session: '.$run->session_id);

            $agent = Agent::fromString($run->agent);
            if ($agent instanceof Agent) {
                $this->newLine();
                $this->line('  <fg=green>Resume:</> '.$agent->resumeCommand($run->session_id));
            }
        }

        if ($showOutput && $run->output !== null && $run->output !== '') {
            $this->newLine();
            $this->line('  <fg=cyan>── Output ──</>');
            // Indent each line of output
            $outputLines = explode("\n", $run->output);
            foreach ($outputLines as $line) {
                $this->line('  '.OutputFormatter::escape($line));
            }
        }
    }
}
