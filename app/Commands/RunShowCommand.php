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
                $output = $this->getRunOutput($run, $fuelContext);
                $useScrollRegion = $this->shouldUseScrollRegion($output);

                if ($useScrollRegion) {
                    // Clear screen and position cursor at home for scroll region mode
                    echo "\033[2J";  // Clear screen
                    echo "\033[H";   // Cursor home
                }

                $this->info(sprintf('Run: %s', $run->run_id));
                $this->newLine();
                $headerLines = $this->displayRunDetails($run, $duration, false, $fuelContext);

                if ($useScrollRegion && $output !== null && $output !== '') {
                    $this->displayOutputWithScrollRegion($output, $headerLines);
                } elseif ($output !== null && $output !== '') {
                    $this->newLine();
                    $this->line('  <fg=cyan>── Output ──</>');
                    $this->outputChunk($output, $this->option('raw'));
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
     *
     * @return int Number of lines displayed (for scroll region calculation)
     */
    private function displayRunDetails(Run $run, string $duration, bool $showOutput = false, ?FuelContext $fuelContext = null): int
    {
        $lineCount = 0;

        $this->line('  Run ID: '.$run->run_id);
        $lineCount++;

        if ($run->agent !== null) {
            $this->line('  Agent: '.$run->agent);
            $lineCount++;
        }

        if ($run->model !== null) {
            $this->line('  Model: '.$run->model);
            $lineCount++;
        }

        if ($run->started_at !== null) {
            $this->line('  Started: '.$this->formatDateTime($run->started_at));
            $lineCount++;
        }

        if ($run->ended_at !== null) {
            $this->line('  Ended: '.$this->formatDateTime($run->ended_at));
            $lineCount++;
        }

        if ($duration !== '') {
            $this->line('  Duration: '.$duration);
            $lineCount++;
        }

        if ($run->exit_code !== null) {
            $exitColor = $run->exit_code === 0 ? 'green' : 'red';
            $this->line(sprintf('  Exit code: <fg=%s>%s</>', $exitColor, $run->exit_code));
            $lineCount++;
        }

        if ($run->cost_usd !== null) {
            $this->line('  Cost: $'.number_format($run->cost_usd, 4));
            $lineCount++;
        }

        if ($run->session_id !== null) {
            $this->line('  Session: '.$run->session_id);
            $lineCount++;

            if ($run->agent !== null) {
                try {
                    $registry = new AgentDriverRegistry;
                    $driver = $registry->getForAgentName($run->agent);
                    $this->newLine();
                    $lineCount++;
                    $this->line('  <fg=green>Resume:</> '.$driver->getResumeCommand($run->session_id));
                    $lineCount++;
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

        // Account for "Run: xxx" header line and newline after it
        return $lineCount + 2;
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

    /**
     * Check if we should use scroll region (interactive TTY, not piping).
     */
    private function shouldUseScrollRegion(?string $output): bool
    {
        // No output, no need for scroll region
        if ($output === null || $output === '') {
            return false;
        }

        // Must be a TTY (not piping)
        if (! stream_isatty(STDOUT)) {
            return false;
        }

        // Check if terminal supports scrolling regions (most modern terminals do)
        $term = getenv('TERM');

        return ! in_array($term, [false, '', 'dumb'], true);
    }

    /**
     * Get terminal height.
     */
    private function getTerminalHeight(): int
    {
        // Check environment variables first
        $envLines = getenv('LINES');
        if ($envLines !== false && (int) $envLines > 0) {
            return (int) $envLines;
        }

        if (isset($_SERVER['LINES']) && (int) $_SERVER['LINES'] > 0) {
            return (int) $_SERVER['LINES'];
        }

        // Try tput
        $lines = @shell_exec('tput lines 2>/dev/null');
        if ($lines !== null && $lines !== false) {
            $lines = (int) trim($lines);
            if ($lines > 0) {
                return $lines;
            }
        }

        return 24; // Default fallback
    }

    /**
     * Display output using ANSI scroll region (header stays fixed at top).
     */
    private function displayOutputWithScrollRegion(string $output, int $headerLines): void
    {
        $termHeight = $this->getTerminalHeight();

        // Add 2 for the "── Output ──" header and blank line
        $scrollTop = $headerLines + 3;
        $scrollBottom = $termHeight;

        // Only use scroll region if there's enough space
        if ($scrollBottom <= $scrollTop + 3) {
            // Not enough space, fall back to normal output
            $this->newLine();
            $this->line('  <fg=cyan>── Output ──</>');
            $this->outputChunk($output, $this->option('raw'));

            return;
        }

        // Output the "Output" header (this will be just above the scroll region)
        $this->newLine();
        $this->line('  <fg=cyan>── Output ──</>');

        // Set scroll region from after header to bottom of terminal
        // \033[{top};{bottom}r sets scroll region (DECSTBM)
        echo "\033[{$scrollTop};{$scrollBottom}r";

        // Move cursor to start of scroll region
        echo "\033[{$scrollTop};1H";

        // Output the content - it will scroll within the region
        $this->outputChunk($output, $this->option('raw'));

        // Reset scroll region to full screen
        echo "\033[r";

        // Move cursor below all content
        echo "\033[{$termHeight};1H";
        $this->newLine();
    }
}
