<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\RunService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class ShowCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'show
        {id : The task ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Show task details including all fields';

    public function handle(TaskService $taskService, RunService $runService): int
    {
        $this->configureCwd($taskService);

        try {
            $task = $taskService->find($this->argument('id'));

            if ($task === null) {
                return $this->outputError(sprintf("Task '%s' not found", $this->argument('id')));
            }

            if ($this->option('json')) {
                $this->outputJson($task);
            } else {
                $this->info('Task: ' . $task['id']);
                $this->line('  Title: ' . $task['title']);
                $this->line('  Status: ' . $task['status']);

                if (isset($task['description']) && $task['description'] !== null) {
                    $this->line('  Description: ' . $task['description']);
                }

                if (isset($task['type'])) {
                    $this->line('  Type: ' . $task['type']);
                }

                if (isset($task['priority'])) {
                    $this->line('  Priority: ' . $task['priority']);
                }

                if (isset($task['size'])) {
                    $this->line('  Size: ' . $task['size']);
                }

                if (isset($task['labels']) && ! empty($task['labels'])) {
                    $labels = implode(', ', $task['labels']);
                    $this->line('  Labels: ' . $labels);
                }

                if (isset($task['blocked_by']) && ! empty($task['blocked_by'])) {
                    $blockerIds = is_array($task['blocked_by']) ? implode(', ', $task['blocked_by']) : '';
                    if ($blockerIds !== '') {
                        $this->line('  Blocked by: ' . $blockerIds);
                    }
                }

                if (isset($task['reason'])) {
                    $this->line('  Reason: ' . $task['reason']);
                }

                // Consume command fields
                if (! empty($task['consumed']) || isset($task['consume_pid'])) {
                    $this->newLine();
                    $this->line('  <fg=cyan>── Consume Info ──</>');
                    if (! empty($task['consumed'])) {
                        $this->line('  Consumed: Yes');
                    }

                    if (isset($task['consume_pid']) && $task['consume_pid'] !== null) {
                        $this->line('  PID: ' . $task['consume_pid']);
                    }

                    if (isset($task['consumed_at'])) {
                        $this->line('  Consumed at: ' . $task['consumed_at']);
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
                            $this->line('  ' . $line);
                        }
                    }
                }

                // Run information from RunService
                $runs = $runService->getRuns($task['id']);
                if ($runs !== []) {
                    $this->newLine();
                    $this->line('  <fg=cyan>── Run History ──</>');
                    foreach ($runs as $index => $run) {
                        $isLatest = $index === count($runs) - 1;
                        $prefix = $isLatest ? '  → Latest' : '  '.($index + 1);
                        $this->line(sprintf('%s Run: %s', $prefix, $run['run_id']));
                        if (isset($run['agent'])) {
                            $this->line('    Agent: ' . $run['agent']);
                        }

                        if (isset($run['model'])) {
                            $this->line('    Model: ' . $run['model']);
                        }

                        if (isset($run['started_at'])) {
                            $this->line('    Started: ' . $run['started_at']);
                        }

                        if (isset($run['ended_at'])) {
                            $this->line('    Ended: ' . $run['ended_at']);
                            // Calculate duration if both times exist
                            if (isset($run['started_at'])) {
                                try {
                                    $start = new \DateTime($run['started_at']);
                                    $end = new \DateTime($run['ended_at']);
                                    $duration = $end->getTimestamp() - $start->getTimestamp();
                                    $durationStr = $duration < 60 ? $duration . 's' : (int) ($duration / 60).'m '.($duration % 60).'s';
                                    $this->line('    Duration: ' . $durationStr);
                                } catch (\Exception) {
                                    // Ignore date parsing errors
                                }
                            }
                        }

                        if (isset($run['exit_code']) && $run['exit_code'] !== null) {
                            $exitColor = $run['exit_code'] === 0 ? 'green' : 'red';
                            $this->line(sprintf('    Exit code: <fg=%s>%s</>', $exitColor, $run['exit_code']));
                        }

                        if ($isLatest && isset($run['output']) && $run['output'] !== null && $run['output'] !== '') {
                            $this->newLine();
                            $this->line('    <fg=cyan>── Run Output ──</>');
                            // Indent each line of output
                            $outputLines = explode("\n", (string) $run['output']);
                            foreach ($outputLines as $line) {
                                $this->line('    ' . $line);
                            }
                        }

                        if ($index < count($runs) - 1) {
                            $this->newLine();
                        }
                    }
                }

                $this->newLine();
                $this->line('  Created: ' . $task['created_at']);
                $this->line('  Updated: ' . $task['updated_at']);
            }

            return self::SUCCESS;
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }
    }
}
