<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class ConsumeCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'consume
        {--cwd= : Working directory (defaults to current directory)}
        {--interval=5 : Check interval in seconds when idle}
        {--agent=cursor-agent : Agent command to spawn}
        {--prompt=Consume one task from fuel, then land the plane : Prompt to send to agent}
        {--dryrun : Show what would happen without claiming tasks or spawning agents}';

    protected $description = 'Auto-spawn agents to work through available tasks';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);
        $taskService->initialize();

        $interval = max(1, (int) $this->option('interval'));
        $agentCommand = $this->option('agent');
        $prompt = $this->option('prompt');
        $dryrun = $this->option('dryrun');

        // Validate agent command exists in PATH (skip in dryrun)
        if (! $dryrun) {
            $agentPath = trim(shell_exec("which {$agentCommand} 2>/dev/null") ?? '');
            if (empty($agentPath)) {
                $this->error("Agent command not found: {$agentCommand}");
                $this->line('Ensure it\'s in your PATH or use --agent=/full/path/to/agent');

                return self::FAILURE;
            }
        }

        $isTty = stream_isatty(STDOUT);

        if ($isTty) {
            $this->getOutput()->write("\033[?1049h");
            $this->getOutput()->write("\033[H\033[2J");
        }

        $exiting = false;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$exiting) {
                $exiting = true;
            });
            pcntl_signal(SIGTERM, function () use (&$exiting) {
                $exiting = true;
            });
        }

        $statusLines = [];

        try {
            while (! $exiting) {
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                $this->refreshDisplay($taskService, $statusLines);

                $readyTasks = $taskService->ready();

                if ($readyTasks->isEmpty()) {
                    $statusLines[] = $this->formatStatus('⏳', 'Waiting for tasks...', 'gray');
                    $this->setTerminalTitle('Fuel: Waiting for tasks...');
                    $this->refreshDisplay($taskService, $statusLines);

                    // Poll while waiting
                    for ($i = 0; $i < $interval * 10 && ! $exiting; $i++) {
                        if (function_exists('pcntl_signal_dispatch')) {
                            pcntl_signal_dispatch();
                        }
                        usleep(100000); // 100ms
                    }

                    continue;
                }

                // Pick first ready task and claim it
                $task = $readyTasks->first();
                $taskId = $task['id'];
                $taskTitle = $task['title'];
                $shortTitle = mb_strlen($taskTitle) > 40 ? mb_substr($taskTitle, 0, 37).'...' : $taskTitle;

                // Build structured prompt with task details
                $cwd = $this->option('cwd') ?: getcwd();
                $taskDetails = $this->formatTaskForPrompt($task);

                $fullPrompt = <<<PROMPT
You have been assigned ONE specific task. Work ONLY on this task.

== ASSIGNED TASK ==
{$taskDetails}

== INSTRUCTIONS ==
1. Complete the task described above
2. When finished, run: fuel done {$taskId}
3. Do NOT pick up other tasks - only work on {$taskId}

== CONTEXT ==
Working directory: {$cwd}

{$prompt}
PROMPT;

                if ($dryrun) {
                    // Dryrun: show what would happen without claiming or spawning
                    $statusLines[] = $this->formatStatus('👁', "[DRYRUN] Would spawn agent for {$taskId}: {$shortTitle}", 'cyan');
                    $this->setTerminalTitle("Fuel: [DRYRUN] {$taskId}");
                    $this->refreshDisplay($taskService, $statusLines);
                    $this->newLine();
                    $this->line('<fg=cyan>== PROMPT THAT WOULD BE SENT ==</>');
                    $this->line($fullPrompt);
                    $this->newLine();
                    $this->line('<fg=gray>Press Ctrl+C to exit, or wait to see next task...</>');
                    sleep(3);

                    continue;
                }

                // Mark task as in_progress and flag as consumed before spawning agent
                $task = $taskService->start($taskId);
                $task = $taskService->update($taskId, [
                    'consumed' => true,
                    'consumed_at' => date('c'),
                ]);

                $statusLines[] = $this->formatStatus('🚀', "Spawning agent for {$taskId}: {$shortTitle}", 'yellow');
                $this->setTerminalTitle("Fuel: {$taskId} - {$shortTitle}");
                $this->refreshDisplay($taskService, $statusLines);

                // Spawn agent with inherited environment (so it can find `fuel` in PATH)
                $process = new Process(
                    [$agentCommand, '-p', $fullPrompt],
                    $cwd,
                    null,  // inherit environment variables
                    null,  // no stdin
                    null   // no timeout
                );
                $process->setTimeout(null);    // Explicitly disable timeout (agents can run for hours)
                $process->setIdleTimeout(null); // Disable idle timeout too

                $startTime = time();
                $process->start();

                // Wait for process with signal handling
                while ($process->isRunning()) {
                    if (function_exists('pcntl_signal_dispatch')) {
                        pcntl_signal_dispatch();
                    }

                    if ($exiting) {
                        $process->stop(10);
                        break;
                    }

                    // Refresh display periodically while agent works
                    $elapsed = $this->formatDuration(time() - $startTime);
                    $this->setTerminalTitle("Fuel: {$taskId} ({$elapsed})");
                    $this->refreshDisplay($taskService, $statusLines, $taskId, $startTime);
                    usleep(500000); // 500ms
                }

                $exitCode = $process->getExitCode();
                $duration = time() - $startTime;
                $durationStr = $this->formatDuration($duration);

                // Capture and store agent output for debugging
                $output = $process->getOutput().$process->getErrorOutput();
                // Truncate to last 10KB to avoid bloating tasks.jsonl
                if (strlen($output) > 10240) {
                    $output = '... [truncated] ...'.substr($output, -10240);
                }

                $taskService->update($taskId, [
                    'consumed_exit_code' => $exitCode,
                    'consumed_output' => $output,
                ]);

                if ($exitCode === 0) {
                    $statusLines[] = $this->formatStatus('✓', "{$taskId} completed ({$durationStr})", 'green');
                } else {
                    $statusLines[] = $this->formatStatus('✗', "{$taskId} failed (exit {$exitCode}, {$durationStr})", 'red');
                }

                // Keep only last 5 status lines
                if (count($statusLines) > 5) {
                    $statusLines = array_slice($statusLines, -5);
                }

                $this->refreshDisplay($taskService, $statusLines);

                // Brief pause before next task
                sleep(1);
            }
        } finally {
            if ($isTty) {
                $this->getOutput()->write("\033[?1049l");
                $this->setTerminalTitle('');  // Reset terminal title
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string>  $statusLines
     */
    private function refreshDisplay(TaskService $taskService, array $statusLines, ?string $activeTaskId = null, ?int $startTime = null): void
    {
        if (stream_isatty(STDOUT)) {
            // Clear screen and move cursor home
            $this->getOutput()->write("\033[H\033[2J");
        }

        // Render board (BoardCommand uses cursor home without clear, so no flicker)
        $this->call('board', ['--once' => true, '--cwd' => $this->option('cwd')]);

        $this->newLine();

        // Show active task info
        if ($activeTaskId && $startTime) {
            $duration = $this->formatDuration(time() - $startTime);
            $this->line("<fg=yellow>🔄 Agent working on {$activeTaskId} ({$duration})...</>");
        }

        // Show status history
        foreach ($statusLines as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->line('<fg=gray>Press Ctrl+C to exit</>');
    }

    private function formatStatus(string $icon, string $message, string $color): string
    {
        return "<fg={$color}>{$icon} {$message}</>";
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = (int) ($seconds / 60);
        $secs = $seconds % 60;

        return "{$minutes}m {$secs}s";
    }

    private function setTerminalTitle(string $title): void
    {
        if (stream_isatty(STDOUT)) {
            // OSC 0 sets both window title and icon name
            $this->getOutput()->write("\033]0;{$title}\007");
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function formatTaskForPrompt(array $task): string
    {
        $lines = [
            "Task: {$task['id']}",
            "Title: {$task['title']}",
            "Status: {$task['status']}",
        ];

        if (! empty($task['description'])) {
            $lines[] = "Description: {$task['description']}";
        }
        if (! empty($task['type'])) {
            $lines[] = "Type: {$task['type']}";
        }
        if (! empty($task['priority'])) {
            $lines[] = "Priority: P{$task['priority']}";
        }
        if (! empty($task['labels'])) {
            $lines[] = 'Labels: '.implode(', ', $task['labels']);
        }
        if (! empty($task['blocked_by'])) {
            $lines[] = 'Blocked by: '.implode(', ', $task['blocked_by']);
        }

        return implode("\n", $lines);
    }
}
