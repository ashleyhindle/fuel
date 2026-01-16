<?php

declare(strict_types=1);

namespace App\Commands;

use App\Agents\AgentDriverRegistry;
use App\Commands\Concerns\CalculatesDuration;
use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Run;
use App\Services\FuelContext;
use App\Services\OutputParser;
use App\Services\RunService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

class RunShowCommand extends Command
{
    use CalculatesDuration;
    use HandlesJsonOutput;

    protected $signature = 'run:show
        {id : The run ID (e.g., run-abc123)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--raw : Show raw output instead of formatted}';

    protected $description = 'Show details for a specific run';

    public function __construct(
        private OutputParser $outputParser,
    ) {
        parent::__construct();
    }

    public function handle(
        RunService $runService,
        FuelContext $fuelContext
    ): int {
        try {
            $runId = $this->argument('id');
            $run = $runService->findRun($runId);

            if (! $run instanceof Run) {
                return $this->outputError(sprintf("Run '%s' not found", $runId));
            }

            // Calculate duration
            $duration = $this->calculateDuration($run->started_at, $run->ended_at);

            if ($this->option('json')) {
                // Convert to array and add duration for JSON output
                $runData = $run->toArray();
                $runData['duration'] = $duration;
                $this->outputJson($runData);
            } else {
                $this->info(sprintf('Run: %s', $run->run_id));
                $this->newLine();
                $this->displayRunDetails($run, $duration, true, $fuelContext);
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
