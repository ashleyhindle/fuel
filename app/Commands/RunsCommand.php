<?php

declare(strict_types=1);

namespace App\Commands;

use App\Agents\AgentDriverRegistry;
use App\Commands\Concerns\CalculatesDuration;
use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Run;
use App\Models\Task;
use App\Services\FuelContext;
use App\Services\OutputParser;
use App\Services\RunService;
use App\Services\TaskService;
use App\TUI\Table;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

class RunsCommand extends Command
{
    use CalculatesDuration;
    use HandlesJsonOutput;

    protected $signature = 'runs
        {id : The task ID (supports partial matching)}
        {--json : Output as JSON}
        {--raw : Show raw output instead of formatted}
        {--last : Show only the latest run with full output}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'View task execution history';

    public function __construct(
        private OutputParser $outputParser,
    ) {
        parent::__construct();
    }

    public function handle(
        TaskService $taskService,
        RunService $runService,
        FuelContext $fuelContext
    ): int {
        try {
            // Validate task exists
            $task = $taskService->find($this->argument('id'));

            if (! $task instanceof Task) {
                return $this->outputError(sprintf("Task '%s' not found", $this->argument('id')));
            }

            $taskId = $task->short_id;

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
                    $this->displayRunDetails($latestRun, $duration, true, $fuelContext);
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
                            $this->formatDateTime($run->started_at ?? new \DateTime),
                            $duration,
                            $run->exit_code !== null ? (string) $run->exit_code : '',
                            $run->cost_usd !== null ? '$'.number_format($run->cost_usd, 2) : '',
                            $shortSession,
                        ];
                    }, $runs);

                    $table = new Table;
                    $table->render($headers, $rows, $this->output);
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
    private function formatDateTime(\DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }

    /**
     * Display detailed run information.
     */
    private function displayRunDetails(Run $run, string $duration, bool $showOutput = false, ?FuelContext $fuelContext = null): void
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

            if ($run->agent !== null) {
                try {
                    $registry = new AgentDriverRegistry;
                    $driver = $registry->getForAgentName($run->agent);
                    $this->newLine();
                    $this->line('  <fg=green>Resume:</> '.$driver->getResumeCommand($run->session_id));
                } catch (\RuntimeException) {
                    // Driver not found, skip resume command
                }
            }
        }

        if ($showOutput) {
            $output = $this->getRunOutput($run, $fuelContext);
            if ($output !== null && $output !== '') {
                $this->newLine();
                $this->line('  <fg=cyan>── Output ──</>');
                $this->outputChunk($output, $this->option('raw'));
            }
        }
    }

    /**
     * Get run output from stdout.log file, falling back to DB if file doesn't exist.
     */
    private function getRunOutput(Run $run, ?FuelContext $fuelContext): ?string
    {
        // Try reading from stdout.log file first (has full output)
        if ($fuelContext instanceof FuelContext && $run->run_id !== null) {
            $stdoutPath = $fuelContext->getProcessesPath().'/'.$run->run_id.'/stdout.log';
            if (file_exists($stdoutPath)) {
                return file_get_contents($stdoutPath);
            }
        }

        // Fall back to DB output (may be truncated)
        return $run->output;
    }

    /**
     * Output a chunk of agent output, either raw or parsed.
     */
    private function outputChunk(string $chunk, bool $raw): void
    {
        if ($raw) {
            // Show raw JSON lines with indentation
            $lines = explode("\n", $chunk);
            foreach ($lines as $line) {
                $this->line('  '.OutputFormatter::escape($line));
            }

            return;
        }

        // Parse and format the output nicely
        $events = $this->outputParser->parseChunk($chunk);
        $hasOutput = false;

        foreach ($events as $event) {
            $formatted = $this->outputParser->format($event);
            if ($formatted !== null) {
                $hasOutput = true;
                // Indent each line
                $lines = explode("\n", $formatted);
                foreach ($lines as $line) {
                    $this->line('  '.$line);
                }
            }
        }

        // Fall back to raw output if no events were formatted (plain text output)
        if (! $hasOutput && trim($chunk) !== '') {
            $lines = explode("\n", $chunk);
            foreach ($lines as $line) {
                $this->line('  '.OutputFormatter::escape($line));
            }
        }
    }
}
