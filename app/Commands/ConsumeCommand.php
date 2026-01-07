<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\ConfigService;
use App\Services\RunService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class ConsumeCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'consume
        {--cwd= : Working directory (defaults to current directory)}
        {--interval=5 : Check interval in seconds when idle}
        {--agent= : Agent command to spawn (overrides config-based routing)}
        {--prompt=Consume one task from fuel, then land the plane : Prompt to send to agent}
        {--dryrun : Show what would happen without claiming tasks or spawning agents}';

    protected $description = 'Auto-spawn agents to work through available tasks';

    /** @var array<int, Process> Active agent processes indexed by PID */
    private array $activeProcesses = [];

    /** @var array<string, int> Count of active processes per agent name */
    private array $agentCounts = [];

    /** @var array<int, array{task_id: string, agent_name: string, start_time: int}> Process metadata indexed by PID */
    private array $processMetadata = [];

    public function handle(TaskService $taskService, ConfigService $configService, RunService $runService): int
    {
        $this->configureCwd($taskService);
        $taskService->initialize();

        // Configure ConfigService with cwd path if provided
        if ($cwd = $this->option('cwd')) {
            $configService->setConfigPath($cwd.'/.fuel/config.yaml');
        }

        // Clean up orphaned runs from previous consume crashes
        $cleanupCount = $runService->cleanupOrphanedRuns(fn (int $pid): bool => ! $this->isProcessRunning($pid));
        if ($cleanupCount > 0) {
            // Log cleanup (will be visible briefly before entering alternate screen)
            $this->info("Cleaned up {$cleanupCount} orphaned run(s) from previous session");
            sleep(1); // Give user a moment to see the message
        }

        $interval = max(1, (int) $this->option('interval'));
        $agentOverride = $this->option('agent');
        $dryrun = $this->option('dryrun');

        $this->getOutput()->write("\033[?1049h");
        $this->getOutput()->write("\033[?25l"); // Hide cursor
        $this->getOutput()->write("\033[H\033[2J");

        $exiting = false;
        $paused = true;
        $originalTty = null;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$exiting) {
                $exiting = true;
            });
            pcntl_signal(SIGTERM, function () use (&$exiting) {
                $exiting = true;
            });
        }

        // Set up terminal for non-blocking keyboard input (pause toggle)
        $canDetectKeys = stream_isatty(STDIN);
        if ($canDetectKeys) {
            $originalTty = shell_exec('stty -g');
            shell_exec('stty -icanon -echo');
            stream_set_blocking(STDIN, false);
        }

        $statusLines = [];

        try {
            while (! $exiting) {
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                // Check for pause toggle (Shift+Tab)
                if ($canDetectKeys && $this->checkForPauseToggle()) {
                    $paused = ! $paused;
                    $statusLines[] = $paused
                        ? $this->formatStatus('⏸', 'PAUSED - press Shift+Tab to resume', 'yellow')
                        : $this->formatStatus('▶', 'Resumed - looking for tasks...', 'green');
                }

                // When paused, just refresh display and wait
                if ($paused) {
                    $this->setTerminalTitle('Fuel: PAUSED');
                    $this->refreshDisplay($taskService, $statusLines, $this->activeProcesses, $paused);
                    usleep(200000); // 200ms

                    continue;
                }

                // Step 1: Fill available slots across all agents
                $readyTasks = $taskService->ready();

                if ($readyTasks->isNotEmpty()) {
                    // Score and sort tasks by priority, complexity, and size
                    $scoredTasks = $readyTasks->map(function (array $task) {
                        return [
                            'task' => $task,
                            'score' => $this->calculateTaskScore($task),
                        ];
                    })->sortBy([
                        ['score', 'asc'], // Lower score = higher priority
                        ['task.priority', 'asc'],
                        ['task.created_at', 'asc'],
                    ])->values();

                    // Try to spawn tasks until we can't spawn any more
                    foreach ($scoredTasks as $scoredTask) {
                        $task = $scoredTask['task'];

                        // Try to spawn this task
                        $spawned = $this->trySpawnTask(
                            $task,
                            $taskService,
                            $configService,
                            $runService,
                            $agentOverride,
                            $dryrun,
                            $statusLines
                        );

                        if ($dryrun && $spawned) {
                            // In dryrun mode, show what would happen and continue
                            $this->newLine();
                            $this->line('<fg=gray>Press Ctrl+C to exit, or wait to see next task...</>');
                            sleep(3);
                        }

                        // If we couldn't spawn (capacity reached for this agent), try next task
                        // This allows us to spawn tasks for different agents even if one is at capacity
                    }
                }

                // Step 2: Poll all running processes
                $this->pollRunningProcesses($taskService, $runService, $statusLines);

                // Step 3: Check if we have any work or should wait
                if (empty($this->activeProcesses) && $readyTasks->isEmpty()) {
                    // Only add waiting message if not already the last status
                    $waitingMsg = $this->formatStatus('⏳', 'Waiting for tasks...', 'gray');
                    if (empty($statusLines) || end($statusLines) !== $waitingMsg) {
                        $statusLines[] = $waitingMsg;
                    }
                    $this->setTerminalTitle('Fuel: Waiting for tasks...');
                    $this->refreshDisplay($taskService, $statusLines, $this->activeProcesses, $paused);

                    // Poll while waiting
                    for ($i = 0; $i < $interval * 10 && ! $exiting; $i++) {
                        if (function_exists('pcntl_signal_dispatch')) {
                            pcntl_signal_dispatch();
                        }
                        // Check for pause toggle while waiting
                        if ($canDetectKeys && $this->checkForPauseToggle()) {
                            $paused = ! $paused;
                            $statusLines[] = $paused
                                ? $this->formatStatus('⏸', 'PAUSED - press Shift+Tab to resume', 'yellow')
                                : $this->formatStatus('▶', 'Resumed - looking for tasks...', 'green');
                            $this->refreshDisplay($taskService, $statusLines, $this->activeProcesses, $paused);
                        }
                        usleep(100000); // 100ms
                    }

                    continue;
                }

                // Update display with current state
                $this->refreshDisplay($taskService, $statusLines, $this->activeProcesses, $paused);

                // Update terminal title with active process count
                if (! empty($this->activeProcesses)) {
                    $count = count($this->activeProcesses);
                    $this->setTerminalTitle("Fuel: {$count} active");
                } else {
                    $this->setTerminalTitle('Fuel: Idle');
                }

                // Sleep between poll cycles
                usleep(100000); // 100ms
            }
        } finally {
            // Restore terminal state
            if ($originalTty !== null) {
                shell_exec('stty '.trim($originalTty));
                stream_set_blocking(STDIN, true);
            }
            $this->getOutput()->write("\033[?25h"); // Show cursor
            $this->getOutput()->write("\033[?1049l");
            $this->setTerminalTitle('');  // Reset terminal title
        }

        return self::SUCCESS;
    }

    /**
     * Try to spawn a task if agent capacity allows.
     * Returns true if spawned (or would spawn in dryrun), false if at capacity.
     */
    private function trySpawnTask(
        array $task,
        TaskService $taskService,
        ConfigService $configService,
        RunService $runService,
        ?string $agentOverride,
        bool $dryrun,
        array &$statusLines
    ): bool {
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
2. Do NOT pick up other tasks - only work on {$taskId}

== NEEDS-HUMAN WORKFLOW ==
If you become blocked (can't create files, need credentials, need human decisions, etc):
1. Create a needs-human task: ./fuel add 'What is needed' --labels=needs-human --description='Exact steps'
2. Block current task: ./fuel dep:add {$taskId} <needs-human-task-id>
3. Exit immediately

== MANDATORY CLOSING PROTOCOL ==
You MUST complete EVERY step before exiting. No exceptions:
1. ./fuel done {$taskId}
2. ./fuel add "..." for any incomplete/discovered work
3. If you changed code: run tests and linter/formatter
4. git add <files> && git commit -m "feat/fix: description"
5. ./fuel ready (verify task state)

Skipping steps breaks the workflow. Your work is NOT done until all steps complete.

== CONTEXT ==
Working directory: {$cwd}
PROMPT;

        // Build agent command array
        if ($agentOverride) {
            $agentCommandArray = [$agentOverride, '-p', $fullPrompt];
        } else {
            $taskComplexity = $task['complexity'] ?? 'simple';
            try {
                $agentCommandArray = $configService->getAgentCommand($taskComplexity, $fullPrompt);
            } catch (\RuntimeException $e) {
                $this->error("Failed to get agent command: {$e->getMessage()}");
                $this->line('Use --agent to override or ensure .fuel/config.yaml exists');

                return false;
            }
        }

        // Extract agent name for capacity check
        $agentCommand = $agentCommandArray[0];
        $agentName = basename($agentCommand);

        // Check if we can spawn another instance of this agent
        if (! $dryrun && ! $this->canSpawnAgent($agentName, $configService)) {
            return false; // At capacity, can't spawn
        }

        // Validate agent command exists in PATH (skip in dryrun)
        if (! $dryrun) {
            $agentPath = trim(shell_exec("which {$agentCommand} 2>/dev/null") ?? '');
            if (empty($agentPath)) {
                $this->error("Agent command not found: {$agentCommand}");
                $this->line('Ensure it\'s in your PATH or use --agent=/full/path/to/agent');

                return false;
            }
        }

        if ($dryrun) {
            // Dryrun: show what would happen without claiming or spawning
            $statusLines[] = $this->formatStatus('👁', "[DRYRUN] Would spawn agent for {$taskId}: {$shortTitle}", 'cyan');
            $this->setTerminalTitle("Fuel: [DRYRUN] {$taskId}");
            $this->newLine();
            $this->line('<fg=cyan>== PROMPT THAT WOULD BE SENT ==</>');
            $this->line($fullPrompt);

            return true;
        }

        // Mark task as in_progress and flag as consumed before spawning agent
        $taskService->start($taskId);
        $taskService->update($taskId, [
            'consumed' => true,
        ]);

        // Create run entry with started_at
        $runService->logRun($taskId, [
            'agent' => $agentName,
            'started_at' => date('c'),
        ]);

        $statusLines[] = $this->formatStatus('🚀', "Spawning {$agentName} for {$taskId}: {$shortTitle}", 'yellow');

        $startTime = time();

        // Spawn agent using Symfony Process for reliable management
        $process = new Process(
            $agentCommandArray,
            $cwd,
            null,  // inherit environment variables
            null,  // no stdin
            null   // no timeout
        );
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->start();

        $pid = $process->getPid();
        if ($pid === null || $pid <= 0) {
            $this->error("Failed to spawn agent for {$taskId}");

            return false;
        }

        // Track the process
        $this->activeProcesses[$pid] = $process;
        $this->agentCounts[$agentName] = ($this->agentCounts[$agentName] ?? 0) + 1;
        $this->processMetadata[$pid] = [
            'task_id' => $taskId,
            'agent_name' => $agentName,
            'start_time' => $startTime,
        ];

        // Store the process PID in the task
        $taskService->update($taskId, [
            'consume_pid' => $pid,
        ]);

        return true;
    }

    /**
     * Check if a process is still running by PID.
     * Used for detecting orphaned processes from previous consume runs.
     */
    private function isProcessRunning(int $pid): bool
    {
        // posix_kill with signal 0 checks if process exists
        return posix_kill($pid, 0);
    }

    /**
     * Poll all running processes and handle completions.
     */
    private function pollRunningProcesses(
        TaskService $taskService,
        RunService $runService,
        array &$statusLines
    ): void {
        foreach ($this->activeProcesses as $pid => $process) {
            if (! $process->isRunning()) {
                // Process completed - handle it
                $this->handleProcessCompletion(
                    $pid,
                    $process,
                    $taskService,
                    $runService,
                    $statusLines
                );
            }
        }

        // Keep only last 5 status lines
        if (count($statusLines) > 5) {
            $statusLines = array_slice($statusLines, -5);
        }
    }

    /**
     * Handle a completed process.
     */
    private function handleProcessCompletion(
        int $pid,
        Process $process,
        TaskService $taskService,
        RunService $runService,
        array &$statusLines
    ): void {
        if (! isset($this->processMetadata[$pid])) {
            return;
        }

        $metadata = $this->processMetadata[$pid];
        $taskId = $metadata['task_id'];
        $agentName = $metadata['agent_name'];
        $startTime = $metadata['start_time'];

        $exitCode = $process->getExitCode();
        $duration = time() - $startTime;
        $durationStr = $this->formatDuration($duration);

        // Capture agent output
        $output = $process->getOutput().$process->getErrorOutput();

        // Update run entry with completion data
        $runService->updateLatestRun($taskId, [
            'ended_at' => date('c'),
            'exit_code' => $exitCode,
            'output' => $output,
        ]);

        $taskService->update($taskId, [
            'consume_pid' => null, // Clear PID on completion
        ]);

        // Clean up process tracking
        unset($this->activeProcesses[$pid]);
        unset($this->processMetadata[$pid]);
        if (isset($this->agentCounts[$agentName]) && $this->agentCounts[$agentName] > 0) {
            $this->agentCounts[$agentName]--;
        }

        // Check for network errors and retry if needed
        if ($exitCode === 1) {
            $networkErrorPatterns = [
                'ConnectError',
                'ConnectionError',
                'NetworkError',
                'ECONNREFUSED',
                'ETIMEDOUT',
                'ENOTFOUND',
                'Connection refused',
                'Connection timed out',
                'Network is unreachable',
                'Name or service not known',
            ];

            foreach ($networkErrorPatterns as $pattern) {
                if (stripos($output, $pattern) !== false) {
                    // Reopen task so it can be retried on next cycle
                    $taskService->reopen($taskId);
                    $statusLines[] = $this->formatStatus('🔄', "{$taskId} failed with network error, will retry (exit {$exitCode}, {$durationStr})", 'yellow');

                    return;
                }
            }
        }

        // Check for permission-blocked agents (exit 0 but couldn't complete)
        $permissionBlockedPatterns = [
            'commands are being rejected',
            'terminal commands are being rejected',
            'please manually complete',
        ];

        foreach ($permissionBlockedPatterns as $pattern) {
            if (stripos($output, $pattern) !== false) {
                // Create a needs-human task for permission configuration
                $humanTask = $taskService->create([
                    'title' => "Configure agent permissions for {$agentName}",
                    'description' => "Agent {$agentName} was blocked from running commands while working on {$taskId}.\n\n".
                        "To fix, either:\n".
                        "1. Run `{$agentName}` interactively and select 'Always allow' for tool permissions\n".
                        "2. Or add autonomous flags to .fuel/config.yaml:\n".
                        "   - Claude: args: [\"--dangerously-skip-permissions\"]\n".
                        "   - cursor-agent: args: [\"--force\"]\n\n".
                        "See README.md 'Agent Permissions' section for details.",
                    'labels' => ['needs-human'],
                    'priority' => 1,
                ]);

                // Block the original task until permissions are configured
                $taskService->addDependency($taskId, $humanTask['id']);
                $taskService->reopen($taskId);

                $statusLines[] = $this->formatStatus('🔒', "{$taskId} blocked - {$agentName} needs permissions (created {$humanTask['id']})", 'yellow');

                return;
            }
        }

        // Handle completion status
        if ($exitCode === 0) {
            // Auto-complete task if agent didn't run `fuel done`
            $task = $taskService->find($taskId);
            if ($task && $task['status'] === 'in_progress') {
                $taskService->done($taskId, 'Auto-completed by consume (agent exit 0)');
                $statusLines[] = $this->formatStatus('✓', "{$taskId} auto-completed ({$durationStr})", 'green');
            } else {
                $statusLines[] = $this->formatStatus('✓', "{$taskId} completed ({$durationStr})", 'green');
            }
        } else {
            $statusLines[] = $this->formatStatus('✗', "{$taskId} failed (exit {$exitCode}, {$durationStr})", 'red');
        }
    }

    /**
     * Check if we can spawn another agent instance.
     * Cleans up completed processes and checks against configured limit.
     */
    private function canSpawnAgent(string $agentName, ConfigService $configService): bool
    {
        // Clean up completed processes
        foreach ($this->activeProcesses as $pid => $process) {
            if (! $process->isRunning()) {
                unset($this->activeProcesses[$pid]);

                // Decrement count for this agent (get from metadata)
                $agentForProcess = $this->processMetadata[$pid]['agent_name'] ?? 'unknown';
                if (isset($this->agentCounts[$agentForProcess]) && $this->agentCounts[$agentForProcess] > 0) {
                    $this->agentCounts[$agentForProcess]--;
                }
            }
        }

        // Check current count vs limit
        $currentCount = $this->agentCounts[$agentName] ?? 0;
        $limit = $configService->getAgentLimit($agentName);

        return $currentCount < $limit;
    }

    /**
     * @param  array<string>  $statusLines
     * @param  array<int, Process>  $activeProcesses
     */
    private function refreshDisplay(TaskService $taskService, array $statusLines, array $activeProcesses, bool $paused = false): void
    {
        // Begin synchronized output (terminal buffers until end marker)
        $this->getOutput()->write("\033[?2026h");
        // Move cursor home and clear screen
        $this->getOutput()->write("\033[H\033[2J");

        // Render board
        $this->call('board', ['--once' => true, '--cwd' => $this->option('cwd')]);

        $this->newLine();

        // Show active processes
        if (! empty($activeProcesses)) {
            $processLines = [];
            foreach (array_keys($activeProcesses) as $pid) {
                if (isset($this->processMetadata[$pid])) {
                    $metadata = $this->processMetadata[$pid];
                    $taskId = $metadata['task_id'];
                    $agentName = $metadata['agent_name'] ?? 'unknown';
                    $startTime = $metadata['start_time'];
                    $duration = $this->formatDuration(time() - $startTime);
                    $shortId = substr($taskId, 2, 6); // Skip 'f-' prefix
                    $processLines[] = "🔄 {$shortId} [{$agentName}] ({$duration})";
                }
            }
            if (! empty($processLines)) {
                $this->line('<fg=yellow>Active: '.implode(' | ', $processLines).'</>');
            }
        }

        // Show failed/stuck tasks
        $isPidDead = fn (int $pid): bool => ! $this->isProcessRunning($pid);
        $excludePids = array_keys($activeProcesses);
        $failedTasks = $taskService->failed($isPidDead, $excludePids);
        if ($failedTasks->isNotEmpty()) {
            $failedLines = [];
            foreach ($failedTasks as $task) {
                $shortId = substr($task['id'], 2, 6);
                $failedLines[] = "🪫 {$shortId}";
            }
            $this->line('<fg=red>Failed: '.implode(' | ', $failedLines).' (fuel retry)</>');
        }

        // Show status history
        foreach ($statusLines as $line) {
            $this->line($line);
        }

        $this->newLine();
        if ($paused) {
            $this->line('<fg=yellow>PAUSED</> - <fg=gray>Shift+Tab to resume | Ctrl+C to exit</>');
        } else {
            $this->line('<fg=gray>Shift+Tab to pause | Ctrl+C to exit</>');
        }

        // End synchronized output (terminal flushes buffer to screen at once)
        $this->getOutput()->write("\033[?2026l");
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

    /**
     * Check for Shift+Tab keypress to toggle pause state.
     * Uses non-blocking read with stream_select().
     */
    private function checkForPauseToggle(): bool
    {
        $read = [STDIN];
        $write = null;
        $except = null;

        // Non-blocking check (0 timeout)
        if (stream_select($read, $write, $except, 0, 0) > 0) {
            $char = fgetc(STDIN);

            // Shift+Tab sends escape sequence: ESC [ Z (\x1b[Z)
            if ($char === "\x1b") {
                // Read the rest of the escape sequence
                $seq = '';
                while (($next = fgetc(STDIN)) !== false) {
                    $seq .= $next;
                    // Escape sequences typically end after 1-2 chars
                    if (strlen($seq) >= 2) {
                        break;
                    }
                }

                // Check if it's Shift+Tab ([Z)
                if ($seq === '[Z') {
                    // Drain any remaining buffered input to avoid multiple toggles
                    while (fgetc(STDIN) !== false) {
                        // drain
                    }

                    return true;
                }
            }
        }

        return false;
    }

    private function setTerminalTitle(string $title): void
    {
        // OSC 0 sets both window title and icon name
        $this->getOutput()->write("\033]0;{$title}\007");
    }

    /**
     * Calculate a score for task selection based on priority, complexity, and size.
     * Lower score = higher priority (should be selected first).
     *
     * Scoring weights:
     * - Priority: 0-4 (0 = critical, 4 = backlog) - weight: 100
     * - Complexity: trivial=0, simple=1, moderate=2, complex=3 - weight: 10
     * - Size: xs=0, s=1, m=2, l=3, xl=4 - weight: 1
     *
     * Weight ratio explanation (100:10:1):
     * - Priority always dominates: Max complexity difference (30) < min priority step (100),
     *   so priority differences cannot be overcome by complexity or size.
     * - Complexity breaks ties: Max size difference (4) < min complexity step (10),
     *   so complexity differences break ties when priority is equal.
     * - Size is final tiebreaker: Only affects ordering when priority and complexity are equal.
     *
     * @param  array<string, mixed>  $task
     */
    private function calculateTaskScore(array $task): int
    {
        // Priority score (0-4, lower is better)
        $priority = $task['priority'] ?? 2;
        $priorityScore = $priority * 100;

        // Complexity score (trivial=0, simple=1, moderate=2, complex=3)
        $complexityMap = [
            'trivial' => 0,
            'simple' => 1,
            'moderate' => 2,
            'complex' => 3,
        ];
        $complexity = $task['complexity'] ?? 'simple';
        $complexityScore = $complexityMap[$complexity] ?? 1;
        $complexityScore *= 10;

        // Size score (xs=0, s=1, m=2, l=3, xl=4)
        $sizeMap = [
            'xs' => 0,
            's' => 1,
            'm' => 2,
            'l' => 3,
            'xl' => 4,
        ];
        $size = $task['size'] ?? 'm';
        $sizeScore = $sizeMap[$size] ?? 2;

        return $priorityScore + $complexityScore + $sizeScore;
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
