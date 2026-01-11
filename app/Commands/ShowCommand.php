<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\OutputParser;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

class ShowCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'show
        {id : The task ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--raw : Show raw JSON output instead of formatted}';

    protected $description = 'Show task details including all fields';

    public function __construct(
        private OutputParser $outputParser,
    ) {
        parent::__construct();
    }

    public function handle(TaskService $taskService, RunService $runService): int
    {
        $this->configureCwd($taskService);

        // Configure EpicService with --cwd if provided
        $cwd = $this->option('cwd') ?: getcwd();
        $dbService = new DatabaseService($cwd.'/.fuel/agent.db');
        $epicService = new EpicService($dbService, $taskService);

        try {
            $task = $taskService->find($this->argument('id'));

            if ($task === null) {
                return $this->outputError(sprintf("Task '%s' not found", $this->argument('id')));
            }

            // Fetch epic information if task has epic_id
            $epic = null;
            if (isset($task['epic_id']) && $task['epic_id'] !== null) {
                try {
                    $epic = $epicService->getEpic($task['epic_id']);
                } catch (\Exception) {
                    // Epic not found or error - continue without epic info
                    $epic = null;
                }
            }

            if ($this->option('json')) {
                // Include epic info in JSON output
                if ($epic !== null) {
                    $task['epic'] = [
                        'id' => $epic['id'],
                        'title' => $epic['title'] ?? null,
                        'status' => $epic['status'] ?? null,
                    ];
                }
                $this->outputJson($task);
            } else {
                $this->info('Task: '.$task['id']);
                $this->line('  Title: '.$task['title']);
                $this->line('  Status: '.$task['status']);

                if (isset($task['description']) && $task['description'] !== null) {
                    $this->line('  Description: '.$task['description']);
                }

                if (isset($task['type'])) {
                    $this->line('  Type: '.$task['type']);
                }

                if (isset($task['priority'])) {
                    $this->line('  Priority: '.$task['priority']);
                }

                if (isset($task['size'])) {
                    $this->line('  Size: '.$task['size']);
                }

                if (isset($task['labels']) && ! empty($task['labels'])) {
                    $labels = implode(', ', $task['labels']);
                    $this->line('  Labels: '.$labels);
                }

                if (isset($task['blocked_by']) && ! empty($task['blocked_by'])) {
                    $blockerIds = is_array($task['blocked_by']) ? implode(', ', $task['blocked_by']) : '';
                    if ($blockerIds !== '') {
                        $this->line('  Blocked by: '.$blockerIds);
                    }
                }

                if ($epic !== null) {
                    $this->line('  Epic: '.$epic['id'].' - '.($epic['title'] ?? 'Untitled').' ('.$epic['status'].')');
                }

                if (isset($task['reason'])) {
                    $this->line('  Reason: '.$task['reason']);
                }

                // Consume command fields
                if (! empty($task['consumed']) || isset($task['consume_pid'])) {
                    $this->newLine();
                    $this->line('  <fg=cyan>── Consume Info ──</>');
                    if (! empty($task['consumed'])) {
                        $this->line('  Consumed: Yes');
                    }

                    if (isset($task['consume_pid']) && $task['consume_pid'] !== null) {
                        $this->line('  PID: '.$task['consume_pid']);
                    }

                    if (isset($task['consumed_at'])) {
                        $this->line('  Consumed at: '.$task['consumed_at']);
                    }

                    if (isset($task['consumed_exit_code'])) {
                        $exitColor = $task['consumed_exit_code'] === 0 ? 'green' : 'red';
                        $this->line(sprintf('  Exit code: <fg=%s>%s</>', $exitColor, $task['consumed_exit_code']));
                    }

                    if (isset($task['consumed_output']) && $task['consumed_output'] !== '') {
                        $this->newLine();
                        $this->line('  <fg=cyan>── Agent Output ──</>');
                        // Indent each line of output
                        $outputLines = explode("\n", (string) $task['consumed_output']);
                        foreach ($outputLines as $line) {
                            $this->line('  '.OutputFormatter::escape($line));
                        }
                    }
                }

                // Run information from RunService
                $runs = $runService->getRuns($task['id']);

                // Check for live output if task is in_progress (even if no runs exist)
                $liveOutput = $this->getLiveOutput($task['id'], $task['status'] ?? 'open');

                if ($runs !== []) {
                    $this->newLine();
                    $this->line('  <fg=cyan>── Run History ──</>');
                    foreach ($runs as $index => $run) {
                        $isLatest = $index === count($runs) - 1;
                        $prefix = $isLatest ? '  → Latest' : '  '.($index + 1);
                        $this->line(sprintf('%s Run: %s', $prefix, $run['run_id']));
                        if (isset($run['agent'])) {
                            $this->line('    Agent: '.$run['agent']);
                        }

                        if (isset($run['model'])) {
                            $this->line('    Model: '.$run['model']);
                        }

                        if (isset($run['started_at'])) {
                            $this->line('    Started: '.$run['started_at']);
                        }

                        if (isset($run['ended_at'])) {
                            $this->line('    Ended: '.$run['ended_at']);
                            // Calculate duration if both times exist
                            if (isset($run['started_at'])) {
                                try {
                                    $start = new \DateTime($run['started_at']);
                                    $end = new \DateTime($run['ended_at']);
                                    $duration = $end->getTimestamp() - $start->getTimestamp();
                                    $durationStr = $duration < 60 ? $duration.'s' : (int) ($duration / 60).'m '.($duration % 60).'s';
                                    $this->line('    Duration: '.$durationStr);
                                } catch (\Exception) {
                                    // Ignore date parsing errors
                                }
                            }
                        }

                        if (isset($run['exit_code']) && $run['exit_code'] !== null) {
                            $exitColor = $run['exit_code'] === 0 ? 'green' : 'red';
                            $this->line(sprintf('    Exit code: <fg=%s>%s</>', $exitColor, $run['exit_code']));
                        }

                        if ($isLatest) {
                            // Show live output if available, otherwise show run output
                            if ($liveOutput !== null) {
                                $this->newLine();
                                $this->line('    <fg=cyan>── Run Output (live) ──</>');
                                $this->line('    <fg=yellow>Showing live output (tail)...</>');
                                $this->outputChunk($liveOutput, $this->option('raw'));
                            } elseif (isset($run['output']) && $run['output'] !== null && $run['output'] !== '') {
                                $this->newLine();
                                $this->line('    <fg=cyan>── Run Output ──</>');
                                $this->outputChunk((string) $run['output'], $this->option('raw'));
                            }
                        }

                        if ($index < count($runs) - 1) {
                            $this->newLine();
                        }
                    }
                } elseif ($liveOutput !== null) {
                    // Show live output even when there are no runs
                    $this->newLine();
                    $this->line('  <fg=cyan>── Run Output (live) ──</>');
                    $this->line('  <fg=yellow>Showing live output (tail)...</>');
                    $this->outputChunk($liveOutput, $this->option('raw'));
                }

                $this->newLine();
                $this->line('  Created: '.$task['created_at']);
                $this->line('  Updated: '.$task['updated_at']);
            }

            return self::SUCCESS;
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }
    }

    /**
     * Output a chunk of agent output, either raw or parsed.
     */
    private function outputChunk(string $chunk, bool $raw): void
    {
        if ($raw) {
            $this->getOutput()->write(OutputFormatter::escape($chunk));

            return;
        }

        $events = $this->outputParser->parseChunk($chunk);
        foreach ($events as $event) {
            $formatted = $this->outputParser->format($event);
            if ($formatted !== null) {
                $this->line($formatted);
            }
        }
    }

    /**
     * Get live output from stdout.log if task is in_progress and file exists.
     *
     * @return string|null Returns last 50 lines of stdout.log or null if not available
     */
    private function getLiveOutput(string $taskId, string $status): ?string
    {
        // Only check for live output if task is in_progress
        if ($status !== 'in_progress') {
            return null;
        }

        // Determine the working directory
        $cwd = $this->option('cwd') ?: getcwd();
        $stdoutPath = $cwd.'/.fuel/processes/'.$taskId.'/stdout.log';

        // Check if stdout.log exists
        if (! File::exists($stdoutPath)) {
            return null;
        }

        // Read the last 50 lines from the file
        return $this->readLastLines($stdoutPath, 50);
    }

    /**
     * Read the last N lines from a file.
     *
     * @return string|null Returns the last N lines or null if file cannot be read
     */
    private function readLastLines(string $filePath, int $lines): ?string
    {
        if (! File::exists($filePath)) {
            return null;
        }

        try {
            $content = File::get($filePath);
            if ($content === false || $content === '') {
                return null;
            }

            $allLines = explode("\n", $content);
            // Remove empty line at the end if file ends with newline
            if (end($allLines) === '') {
                array_pop($allLines);
            }

            $lineCount = count($allLines);

            // If file has fewer lines than requested, return all
            if ($lineCount <= $lines) {
                return implode("\n", $allLines);
            }

            // Return last N lines
            $lastLines = array_slice($allLines, -$lines);

            return implode("\n", $lastLines);
        } catch (\Exception) {
            return null;
        }
    }
}
