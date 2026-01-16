<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\OutputParser;
use App\Services\ProcessManager;
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
        {--raw : Show raw JSON output instead of formatted}
        {--tail : Continuously tail the live output (like tail -f)}';

    protected $description = 'Show task details including all fields';

    public function __construct(
        private OutputParser $outputParser,
    ) {
        parent::__construct();
    }

    public function handle(
        DatabaseService $databaseService,
        TaskService $taskService,
        RunService $runService,
        EpicService $epicService
    ): int {
        $id = $this->argument('id');

        // Delegate to appropriate show command based on ID prefix
        if (str_starts_with($id, 'e-')) {
            return $this->call('epic:show', [
                'id' => $id,
                '--cwd' => $this->option('cwd'),
                '--json' => $this->option('json'),
            ]);
        }

        if (str_starts_with($id, 'r-') || str_starts_with($id, 'review-')) {
            return $this->call('review:show', [
                'id' => $id,
                '--cwd' => $this->option('cwd'),
                '--json' => $this->option('json'),
                '--raw' => $this->option('raw'),
            ]);
        }

        if (str_starts_with($id, 'run-')) {
            return $this->call('run:show', [
                'id' => $id,
                '--cwd' => $this->option('cwd'),
                '--json' => $this->option('json'),
                '--raw' => $this->option('raw'),
            ]);
        }

        try {
            $task = $taskService->find($id);

            if (! $task instanceof Task) {
                return $this->outputError(sprintf("Task '%s' not found", $id));
            }

            // Fetch epic information using Eloquent relationship
            $epic = $task->epic;
            $epicStatus = null;
            if ($epic instanceof Epic) {
                $epicStatus = $epicService->getEpicStatus($epic->short_id)->value;
            }

            if ($this->option('json')) {
                $taskData = $task->toArray();
                // Include epic info in JSON output
                if ($epic instanceof Epic) {
                    $taskData['epic'] = [
                        'short_id' => $epic->short_id,
                        'title' => $epic->title ?? null,
                        'status' => $epicStatus,
                    ];
                }

                // Include cost in JSON output
                $taskCost = $runService->getTaskCost($task->short_id);
                if ($taskCost !== null) {
                    $taskData['cost_usd'] = $taskCost;
                }

                // commit_hash is already in toArray(), but ensure it's present
                if (! isset($taskData['commit_hash'])) {
                    $taskData['commit_hash'] = $task->commit_hash ?? null;
                }

                $this->outputJson($taskData);
            } else {
                // Header
                $this->newLine();
                $this->info('Task: '.$task->short_id);
                $this->newLine();

                // Basic Information Section
                $this->line('<fg=cyan>── Basic Info ──</>');

                // Handle multiline titles by splitting and indenting each line
                $titleLines = explode("\n", (string) $task->title);
                $firstLine = true;
                foreach ($titleLines as $line) {
                    if ($firstLine) {
                        $this->line('  Title: '.$line);
                        $firstLine = false;
                    } else {
                        $this->line('         '.$line);
                    }
                }

                $this->line('  Status: '.($task->status instanceof TaskStatus ? $task->status->value : $task->status));

                if (isset($task->type)) {
                    $this->line('  Type: '.$task->type);
                }

                if (isset($task->priority)) {
                    $this->line('  Priority: P'.$task->priority);
                }

                if (isset($task->complexity)) {
                    $this->line('  Complexity: '.$task->complexity);
                }

                if (isset($task->labels) && ! empty($task->labels)) {
                    $labels = implode(', ', $task->labels);
                    $this->line('  Labels: '.$labels);
                }

                if ($epic instanceof Epic) {
                    $this->line('  Epic: '.$epic->short_id.' - '.($epic->title ?? 'Untitled').' ('.$epicStatus.')');
                }

                // Description Section (only if present)
                if (isset($task->description) && $task->description !== null && $task->description !== '') {
                    $this->newLine();
                    $this->line('<fg=cyan>── Description ──</>');
                    // Handle multiline descriptions by splitting and indenting each line
                    $descriptionLines = explode("\n", (string) $task->description);
                    foreach ($descriptionLines as $line) {
                        $this->line('  '.$line);
                    }
                }

                // Dependencies Section (only if present)
                if ((isset($task->blocked_by) && ! empty($task->blocked_by))) {
                    $this->newLine();
                    $this->line('<fg=cyan>── Dependencies ──</>');
                    $blockerIds = is_array($task->blocked_by) ? implode(', ', $task->blocked_by) : '';
                    if ($blockerIds !== '') {
                        $this->line('  Blocked by: '.$blockerIds);
                    }
                }

                // Completion Info Section (only if relevant)
                $hasCompletionInfo = (isset($task->reason) && $task->reason !== null && $task->reason !== '') ||
                                     (isset($task->commit_hash) && $task->commit_hash !== null && $task->commit_hash !== '');

                if ($hasCompletionInfo) {
                    $this->newLine();
                    $this->line('<fg=cyan>── Completion Info ──</>');

                    if (isset($task->commit_hash) && $task->commit_hash !== null && $task->commit_hash !== '') {
                        $this->line('  Commit: '.$task->commit_hash);
                    }

                    if (isset($task->reason) && $task->reason !== null && $task->reason !== '') {
                        $this->line('  Reason: '.$task->reason);
                    }
                }

                // Cost Section (only if available)
                $taskCost = $runService->getTaskCost($task->short_id);
                if ($taskCost !== null) {
                    $this->newLine();
                    $this->line('<fg=cyan>── Cost ──</>');
                    $this->line(sprintf('  Total: $%.4f', $taskCost));
                }

                // Consume command fields
                if (! empty($task->consumed)) {
                    $this->newLine();
                    $this->line('  <fg=cyan>── Consume Info ──</>');
                    $this->line('  Consumed: Yes');

                    if (isset($task->consumed_at)) {
                        $this->line('  Consumed at: '.$task->consumed_at);
                    }

                    if (isset($task->consumed_output) && $task->consumed_output !== '') {
                        $this->newLine();
                        $this->line('  <fg=cyan>── Agent Output ──</>');
                        // Indent each line of output
                        $outputLines = explode("\n", (string) $task->consumed_output);
                        foreach ($outputLines as $line) {
                            $this->line('  '.OutputFormatter::escape($line));
                        }
                    }
                }

                // Run information from RunService (compact format)
                $runs = $runService->getRuns($task->short_id);

                // Check for live output if task is in_progress (even if no runs exist)
                $liveOutput = $this->getLiveOutput($task->short_id, $task->status->value, $runService);
                $stdoutPath = $this->getStdoutPath($task->short_id, $task->status->value, $runService);

                if ($runs !== []) {
                    $this->newLine();
                    $this->line('  <fg=cyan>── Runs ──</>');
                    foreach ($runs as $index => $run) {
                        $isLatest = $index === count($runs) - 1;
                        $prefix = $isLatest ? '→' : ' ';

                        // Build compact run line: → run_id | agent (model) | duration | exit:0
                        $parts = [];
                        if (isset($run->run_id)) {
                            $parts[] = '<fg=gray>'.$run->run_id.'</>';
                        }

                        if (isset($run->agent)) {
                            $agentStr = $run->agent;
                            if (isset($run->model)) {
                                $agentStr .= ' ('.$run->model.')';
                            }

                            $parts[] = $agentStr;
                        }

                        // Duration
                        if (isset($run->started_at) && isset($run->ended_at)) {
                            try {
                                $start = $run->started_at instanceof \DateTimeInterface
                                    ? $run->started_at
                                    : new \DateTime((string) $run->started_at);
                                $end = $run->ended_at instanceof \DateTimeInterface
                                    ? $run->ended_at
                                    : new \DateTime((string) $run->ended_at);
                                $duration = $end->getTimestamp() - $start->getTimestamp();
                                $parts[] = $duration < 60 ? $duration.'s' : (int) ($duration / 60).'m '.($duration % 60).'s';
                            } catch (\Exception) {
                            }
                        } elseif (isset($run->started_at)) {
                            $parts[] = 'running';
                        }

                        // Exit code
                        if (isset($run->exit_code) && $run->exit_code !== null) {
                            $exitColor = $run->exit_code === 0 ? 'green' : 'red';
                            $parts[] = sprintf('<fg=%s>exit:%s</>', $exitColor, $run->exit_code);
                        }

                        $this->line(sprintf('  %s %s', $prefix, implode(' | ', $parts)));

                        // Show output only for latest run
                        if ($isLatest) {
                            if ($this->option('tail') && $stdoutPath !== null) {
                                $this->line('    <fg=gray>Path: '.$stdoutPath.'</>');
                                // Check if process is still running using run's pid
                                $runPid = $run->pid ?? null;
                                if ($runPid !== null && ! ProcessManager::isProcessAlive($runPid)) {
                                    $this->line('    <fg=red>(agent not running - PID '.$runPid.' not found)</>');
                                    if ($liveOutput !== null) {
                                        $this->outputChunk($liveOutput, $this->option('raw'));
                                    }
                                } else {
                                    $this->line('    <fg=yellow>(live output - tailing, press Ctrl+C to exit)</>');
                                    $this->tailFile($stdoutPath, $task->short_id, $runService);
                                }
                            } elseif ($liveOutput !== null) {
                                if ($stdoutPath !== null) {
                                    $this->line('    <fg=gray>Path: '.$stdoutPath.'</>');
                                }

                                $this->line('    <fg=yellow>(live output)</>');
                                $this->outputChunk($liveOutput, $this->option('raw'));
                            } elseif (isset($run->output) && $run->output !== null && $run->output !== '') {
                                $this->line('    <fg=cyan>Run Output</>');
                                $this->outputChunk((string) $run->output, $this->option('raw'));
                            }
                        }
                    }
                } elseif ($liveOutput !== null || ($this->option('tail') && $stdoutPath !== null)) {
                    $this->newLine();
                    $this->line('  <fg=cyan>── Run Output (live) ──</>');
                    if ($stdoutPath !== null) {
                        $this->line('  <fg=gray>Path: '.$stdoutPath.'</>');
                    }

                    if ($this->option('tail') && $stdoutPath !== null) {
                        // Check if process is still running using latest run's pid
                        $latestRun = $runService->getLatestRun($task->short_id);
                        $latestPid = $latestRun?->pid;
                        if ($latestPid !== null && ! ProcessManager::isProcessAlive($latestPid)) {
                            $this->line('  <fg=red>(agent not running - PID '.$latestPid.' not found)</>');
                            if ($liveOutput !== null) {
                                $this->outputChunk($liveOutput, $this->option('raw'));
                            }
                        } else {
                            $this->line('  <fg=yellow>(live output - tailing, press Ctrl+C to exit)</>');
                            $this->tailFile($stdoutPath, $task->short_id, $runService);
                        }
                    } elseif ($liveOutput !== null) {
                        $this->line('  <fg=gray>Showing live output (tail)...</>');
                        $this->outputChunk($liveOutput, $this->option('raw'));
                    }
                }

                // Reviews
                $reviews = $databaseService->getReviewsForTask($task->short_id);
                if ($reviews !== []) {
                    $this->newLine();
                    $this->line('  <fg=cyan>── Reviews ──</>');
                    foreach ($reviews as $review) {
                        $status = $review->status ?? 'pending';
                        $statusColor = match ($status) {
                            'passed' => 'green',
                            'failed' => 'red',
                            default => 'yellow',
                        };
                        $timestamp = $this->formatDateTime($review->completed_at ?? $review->started_at);
                        $agent = $review->agent ?? '';
                        $reviewId = $review->id ?? '';

                        // Build one-line review summary: id | STATUS by agent @ time (issues)
                        $line = '  ';
                        if ($reviewId) {
                            $line .= '<fg=gray>'.$reviewId.'</> | ';
                        }

                        $line .= sprintf('<fg=%s>%s</>', $statusColor, strtoupper($status));
                        if ($agent) {
                            $line .= ' by '.$agent;
                        }

                        if ($timestamp !== '' && $timestamp !== '0') {
                            $line .= ' @ '.$timestamp;
                        }

                        // Add failure reason if failed
                        $issues = $review->issues();
                        if ($status === 'failed' && $issues !== []) {
                            $issueCount = count($issues);
                            $firstIssue = $issues[0] ?? '';
                            if (is_string($firstIssue) && strlen($firstIssue) > 60) {
                                $firstIssue = substr($firstIssue, 0, 57).'...';
                            }

                            $line .= $issueCount > 1
                                ? sprintf(' (%d issues: %s)', $issueCount, $firstIssue)
                                : sprintf(' (%s)', $firstIssue);
                        }

                        $this->line($line);
                    }
                }

                // Timestamps Section
                $this->newLine();
                $this->line('<fg=cyan>── Timestamps ──</>');
                $this->line('  Created: '.$this->formatDateTime($task->created_at));
                $this->line('  Updated: '.$this->formatDateTime($task->updated_at));
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
    private function getLiveOutput(string $taskId, string $status, RunService $runService): ?string
    {
        $stdoutPath = $this->getStdoutPath($taskId, $status, $runService);

        if ($stdoutPath === null) {
            return null;
        }

        // Read the last 50 lines from the file
        return $this->readLastLines($stdoutPath, 50);
    }

    /**
     * Get the stdout.log path for a task if it's in_progress and file exists.
     *
     * @return string|null Returns the path to stdout.log or null if not available
     */
    private function getStdoutPath(string $taskId, string $status, RunService $runService): ?string
    {
        // Only check for live output if task is in_progress
        if ($status !== TaskStatus::InProgress->value) {
            return null;
        }

        // Use FuelContext to get the processes path
        $fuelContext = app(FuelContext::class);
        $processesPath = $fuelContext->getProcessesPath();

        // Get the latest run for this task to determine the correct process directory
        $latestRun = $runService->getLatestRun($taskId);

        // Use run ID if available, otherwise fall back to task ID for backward compatibility
        $processId = $latestRun?->run_id ?? $taskId;
        $stdoutPath = $processesPath.'/'.$processId.'/stdout.log';

        // Check if stdout.log exists
        if (! File::exists($stdoutPath)) {
            return null;
        }

        return $stdoutPath;
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

    /**
     * Format a datetime string for display.
     */
    private function formatDateTime(?\DateTimeInterface $dateTimeString): string
    {
        if (! $dateTimeString instanceof \DateTimeInterface) {
            return '';
        }

        return $dateTimeString->format('M j H:i');
    }

    /**
     * Tail a file and continuously output new lines as they are added.
     *
     * @param  string  $filePath  Path to the file to tail
     * @param  string  $taskId  Task ID for checking status
     * @param  RunService  $runService  RunService for checking task status
     */
    private function tailFile(string $filePath, string $taskId, RunService $runService): void
    {
        // Set up signal handler for graceful exit
        $exiting = false;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$exiting): void {
                $exiting = true;
            });
            pcntl_signal(SIGTERM, function () use (&$exiting): void {
                $exiting = true;
            });
        }

        // Output existing content first (last 100 lines), then continue tailing
        $existingContent = $this->readLastLines($filePath, 100);
        if ($existingContent !== null && $existingContent !== '') {
            $this->outputChunk($existingContent, $this->option('raw'));
        }

        // Get the current file size and position - start from end to only show new content
        $lastPosition = File::exists($filePath) ? File::size($filePath) : 0;

        $lastStatusCheck = 0;
        $fileStableSince = null;

        try {
            while (true) {
                // Handle signals if pcntl is available
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                // Check if we should exit
                if ($exiting) {
                    break;
                }

                // Check task status every second to see if it's no longer in_progress
                if (time() - $lastStatusCheck >= 1) {
                    $lastStatusCheck = time();
                    $runs = $runService->getRuns($taskId);

                    if ($runs === []) {
                        // No runs exist, check if file has stopped growing
                        if ($fileStableSince === null) {
                            $fileStableSince = time();
                        } elseif (time() - $fileStableSince >= 3) {
                            // File hasn't grown in 3 seconds, exit
                            $this->outputRemainingContent($filePath, $lastPosition);
                            break;
                        }
                    } else {
                        $latestRun = $runs[count($runs) - 1];
                        // Check if latest run has ended
                        if (isset($latestRun->ended_at) && $latestRun->ended_at !== null) {
                            // Run has finished, output remaining content and exit
                            $this->outputRemainingContent($filePath, $lastPosition);
                            break;
                        }

                        $fileStableSince = null;
                    }
                }

                // Check if file exists and get new content
                // Clear stat cache to get accurate file size (PHP caches filesize results)
                clearstatcache(true, $filePath);
                if (File::exists($filePath)) {
                    $currentSize = File::size($filePath);

                    // If file has grown, read new content
                    if ($currentSize > $lastPosition) {
                        $handle = fopen($filePath, 'r');
                        if ($handle !== false) {
                            fseek($handle, $lastPosition);
                            $newContent = fread($handle, $currentSize - $lastPosition);
                            fclose($handle);

                            if ($newContent !== false && $newContent !== '') {
                                // Output the new content
                                $this->outputChunk($newContent, $this->option('raw'));
                            }

                            $lastPosition = $currentSize;
                            $fileStableSince = null;
                        }
                    }

                    // If file shrunk (rotated or truncated), reset position
                    if ($currentSize < $lastPosition) {
                        $lastPosition = 0;
                    }
                }

                // Sleep briefly to avoid CPU spinning (100ms)
                usleep(100000);
            }
        } catch (\Exception) {
            // Exit on error
        }
    }

    /**
     * Output remaining content from a file starting from a given position.
     *
     * @param  string  $filePath  Path to the file
     * @param  int  $position  Starting position
     */
    private function outputRemainingContent(string $filePath, int $position): void
    {
        if (! File::exists($filePath)) {
            return;
        }

        $currentSize = File::size($filePath);

        if ($currentSize > $position) {
            $handle = fopen($filePath, 'r');
            if ($handle !== false) {
                fseek($handle, $position);
                $content = fread($handle, $currentSize - $position);
                fclose($handle);

                if ($content !== false && $content !== '') {
                    $this->outputChunk($content, $this->option('raw'));
                }
            }
        }
    }
}
