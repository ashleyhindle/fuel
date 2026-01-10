<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Process\CompletionResult;
use App\Process\CompletionType;
use App\Services\ConfigService;
use App\Services\ProcessManager;
use App\Services\RunService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class ConsumeCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'consume
        {--cwd= : Working directory (defaults to current directory)}
        {--interval=5 : Check interval in seconds when idle}
        {--agent= : Agent name to use (overrides config-based routing)}
        {--prompt=Consume one task from fuel, then land the plane : Prompt to send to agent}
        {--dryrun : Show what would happen without claiming tasks or spawning agents}';

    protected $description = 'Auto-spawn agents to work through available tasks';

    /** Cache TTL for task data in seconds */
    private const TASK_CACHE_TTL = 2;

    /** @var array{tasks: \Illuminate\Support\Collection|null, ready: \Illuminate\Support\Collection|null, failed: \Illuminate\Support\Collection|null, timestamp: int} */
    private array $taskCache = ['tasks' => null, 'ready' => null, 'failed' => null, 'timestamp' => 0];

    /** Original terminal state for restoration */
    private ?string $originalTty = null;

    /** Whether we've entered alternate screen mode */
    private bool $inAlternateScreen = false;

    public function handle(
        TaskService $taskService,
        ConfigService $configService,
        RunService $runService,
        ProcessManager $processManager,
    ): int {
        $this->configureCwd($taskService, $configService);
        $taskService->initialize();

        // Clean up orphaned runs from previous consume crashes
        $runService->cleanupOrphanedRuns(fn (int $pid): bool => ! ProcessManager::isProcessAlive($pid));

        $interval = max(1, (int) $this->option('interval'));
        $agentOverride = $this->option('agent');
        $dryrun = $this->option('dryrun');

        // Register ProcessManager signal handlers first
        try {
            $processManager->registerSignalHandlers();
        } catch (\RuntimeException $e) {
            $this->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }

        // Save terminal state and register shutdown handler BEFORE modifying terminal
        $this->originalTty = shell_exec('stty -g');
        register_shutdown_function([$this, 'restoreTerminal']);

        $this->getOutput()->write("\033[?1049h");
        $this->inAlternateScreen = true;
        $this->getOutput()->write("\033[?25l"); // Hide cursor
        $this->getOutput()->write("\033[H\033[2J");

        $paused = true;

        shell_exec('stty -icanon -echo');
        stream_set_blocking(STDIN, false);

        $statusLines = [];

        try {
            while (! $processManager->isShuttingDown()) {
                \pcntl_signal_dispatch();

                // Check for pause toggle (Shift+Tab)
                if ($this->checkForPauseToggle()) {
                    $paused = ! $paused;
                    $statusLines[] = $paused
                        ? $this->formatStatus('⏸', 'PAUSED - press Shift+Tab to resume', 'yellow')
                        : $this->formatStatus('▶', 'Resumed - looking for tasks...', 'green');
                    $statusLines = $this->trimStatusLines($statusLines);
                }

                // When paused, just refresh display and wait
                if ($paused) {
                    $this->setTerminalTitle('fuel: PAUSED');
                    $this->refreshDisplay($taskService, $statusLines, $processManager, $paused);
                    usleep(200000); // 200ms

                    continue;
                }

                // Step 1: Fill available slots across all agents (but not if shutting down)
                $readyTasks = $this->getCachedReadyTasks($taskService);

                if ($readyTasks->isNotEmpty() && ! $processManager->isShuttingDown()) {
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
                            $processManager,
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
                    }
                }

                // Step 2: Poll all running processes
                $this->pollAndHandleCompletions($processManager, $taskService, $runService, $statusLines);

                // Step 3: Check if we have any work or should wait
                if (! $processManager->hasActiveProcesses() && $readyTasks->isEmpty()) {
                    // Only add waiting message if not already the last status
                    $waitingMsg = $this->formatStatus('⏳', 'Waiting for tasks...', 'gray');
                    if (empty($statusLines) || end($statusLines) !== $waitingMsg) {
                        $statusLines[] = $waitingMsg;
                        $statusLines = $this->trimStatusLines($statusLines);
                    }
                    $this->setTerminalTitle('fuel: Waiting for tasks...');
                    $this->refreshDisplay($taskService, $statusLines, $processManager, $paused);

                    // Poll while waiting
                    for ($i = 0; $i < $interval * 10 && ! $processManager->isShuttingDown(); $i++) {
                        \pcntl_signal_dispatch();
                        // Check for pause toggle while waiting
                        if ($this->checkForPauseToggle()) {
                            $paused = ! $paused;
                            $statusLines[] = $paused
                                ? $this->formatStatus('⏸', 'PAUSED - press Shift+Tab to resume', 'yellow')
                                : $this->formatStatus('▶', 'Resumed - looking for tasks...', 'green');
                            $statusLines = $this->trimStatusLines($statusLines);
                            $this->refreshDisplay($taskService, $statusLines, $processManager, $paused);
                        }
                        usleep(100000); // 100ms
                    }

                    // Invalidate cache after waiting period so we get fresh data
                    $this->invalidateTaskCache();

                    continue;
                }

                // Update display with current state
                $this->refreshDisplay($taskService, $statusLines, $processManager, $paused);

                // Update terminal title with active process count
                $activeCount = $processManager->getActiveCount();
                if ($activeCount > 0) {
                    $this->setTerminalTitle("fuel: {$activeCount} active");
                } else {
                    $this->setTerminalTitle('fuel: Idle');
                }

                // Sleep between poll cycles
                usleep(100000); // 100ms
            }
        } finally {
            $this->restoreTerminal();
        }

        return self::SUCCESS;
    }

    /**
     * Restore terminal to its original state.
     * Called both from finally block and shutdown handler for safety.
     */
    public function restoreTerminal(): void
    {
        // Only restore once - check and clear the flag atomically
        if (! $this->inAlternateScreen && $this->originalTty === null) {
            return;
        }

        // Restore stty settings first (most important for usability)
        if ($this->originalTty !== null) {
            shell_exec('stty '.trim($this->originalTty));
            $this->originalTty = null;
        }

        // Restore stream blocking
        stream_set_blocking(STDIN, true);

        // Exit alternate screen buffer and show cursor
        if ($this->inAlternateScreen) {
            // Use echo to ensure output even if Laravel output is unavailable
            echo "\033[?25h";     // Show cursor
            echo "\033[?1049l";   // Exit alternate screen
            echo "\033]0;\007";   // Reset terminal title
            $this->inAlternateScreen = false;
        }
    }

    /**
     * Try to spawn a task if agent capacity allows.
     * Returns true if spawned (or would spawn in dryrun), false if at capacity.
     *
     * @param  array<string, mixed>  $task
     * @param  array<string>  $statusLines
     */
    private function trySpawnTask(
        array $task,
        TaskService $taskService,
        ConfigService $configService,
        RunService $runService,
        ProcessManager $processManager,
        ?string $agentOverride,
        bool $dryrun,
        array &$statusLines
    ): bool {
        // Don't spawn new tasks if shutting down
        if ($processManager->isShuttingDown()) {
            return false;
        }

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

        // Determine agent name for capacity check and dryrun display
        $agentName = $agentOverride;
        if ($agentName === null) {
            $complexity = $task['complexity'] ?? 'simple';
            try {
                $agentName = $configService->getAgentForComplexity($complexity);
            } catch (\RuntimeException $e) {
                $this->error("Failed to get agent: {$e->getMessage()}");
                $this->line('Use --agent to override or ensure .fuel/config.yaml exists');

                return false;
            }
        }

        // Check capacity before dryrun display (so we skip at-capacity agents in dryrun too)
        if (! $dryrun && ! $processManager->canSpawn($agentName)) {
            return false; // At capacity, can't spawn
        }

        if ($dryrun) {
            // Dryrun: show what would happen without claiming or spawning
            $statusLines[] = $this->formatStatus('👁', "[DRYRUN] Would spawn {$agentName} for {$taskId}: {$shortTitle}", 'cyan');
            $this->setTerminalTitle("fuel: [DRYRUN] {$taskId}");
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
        $this->invalidateTaskCache();

        // Spawn via ProcessManager
        $result = $processManager->spawn($task, $fullPrompt, $cwd, $agentOverride);

        if (! $result->success) {
            $this->error($result->error ?? 'Unknown spawn error');

            // Revert task state
            $taskService->reopen($taskId);
            $this->invalidateTaskCache();

            return false;
        }

        $process = $result->process;
        $pid = $process->getPid();

        // Create run entry with started_at
        $runService->logRun($taskId, [
            'agent' => $process->getAgentName(),
            'started_at' => date('c'),
        ]);

        // Store the process PID in the task
        $taskService->update($taskId, [
            'consume_pid' => $pid,
        ]);

        $statusLines[] = $this->formatStatus('🚀', "Spawning {$process->getAgentName()} for {$taskId}: {$shortTitle}", 'yellow');

        return true;
    }

    /**
     * Poll all running processes and handle completions.
     *
     * @param  array<string>  $statusLines
     */
    private function pollAndHandleCompletions(
        ProcessManager $processManager,
        TaskService $taskService,
        RunService $runService,
        array &$statusLines
    ): void {
        // Also update session_id in run service as processes are polled
        foreach ($processManager->getActiveProcesses() as $process) {
            if ($process->getSessionId() !== null) {
                $runService->updateLatestRun($process->getTaskId(), [
                    'session_id' => $process->getSessionId(),
                ]);
            }
        }

        $completions = $processManager->poll();

        foreach ($completions as $completion) {
            $this->handleCompletion($completion, $taskService, $runService, $statusLines);
        }

        // Keep only last 5 status lines
        $statusLines = $this->trimStatusLines($statusLines);
    }

    /**
     * Handle a completed process result.
     *
     * @param  array<string>  $statusLines
     */
    private function handleCompletion(
        CompletionResult $completion,
        TaskService $taskService,
        RunService $runService,
        array &$statusLines
    ): void {
        $taskId = $completion->taskId;
        $agentName = $completion->agentName;
        $durationStr = $completion->getFormattedDuration();

        // Update run entry with completion data
        $runData = [
            'ended_at' => date('c'),
            'exit_code' => $completion->exitCode,
            'output' => $completion->output,
        ];
        if ($completion->sessionId !== null) {
            $runData['session_id'] = $completion->sessionId;
        }
        if ($completion->costUsd !== null) {
            $runData['cost_usd'] = $completion->costUsd;
        }
        $runService->updateLatestRun($taskId, $runData);

        // Clear PID from task
        $taskService->update($taskId, [
            'consume_pid' => null,
        ]);

        // Handle by completion type
        match ($completion->type) {
            CompletionType::Success => $this->handleSuccess($completion, $taskService, $statusLines, $durationStr),
            CompletionType::Failed => $this->handleFailure($completion, $statusLines, $durationStr),
            CompletionType::NetworkError => $this->handleNetworkError($completion, $taskService, $statusLines, $durationStr),
            CompletionType::PermissionBlocked => $this->handlePermissionBlocked($completion, $taskService, $statusLines, $agentName),
        };

        $this->invalidateTaskCache();
    }

    /**
     * @param  array<string>  $statusLines
     */
    private function handleSuccess(
        CompletionResult $completion,
        TaskService $taskService,
        array &$statusLines,
        string $durationStr
    ): void {
        $taskId = $completion->taskId;

        // Auto-complete task if agent didn't run `fuel done`
        $task = $taskService->find($taskId);
        if ($task && $task['status'] === 'in_progress') {
            $taskService->done($taskId, 'Auto-completed by consume (agent exit 0)');
            $statusLines[] = $this->formatStatus('✓', "{$taskId} auto-completed ({$durationStr})", 'green');
        } else {
            $statusLines[] = $this->formatStatus('✓', "{$taskId} completed ({$durationStr})", 'green');
        }
    }

    /**
     * @param  array<string>  $statusLines
     */
    private function handleFailure(
        CompletionResult $completion,
        array &$statusLines,
        string $durationStr
    ): void {
        $statusLines[] = $this->formatStatus('✗', "{$completion->taskId} failed (exit {$completion->exitCode}, {$durationStr})", 'red');
    }

    /**
     * @param  array<string>  $statusLines
     */
    private function handleNetworkError(
        CompletionResult $completion,
        TaskService $taskService,
        array &$statusLines,
        string $durationStr
    ): void {
        // Reopen task so it can be retried on next cycle
        $taskService->reopen($completion->taskId);
        $statusLines[] = $this->formatStatus('🔄', "{$completion->taskId} failed with network error, will retry (exit {$completion->exitCode}, {$durationStr})", 'yellow');
    }

    /**
     * @param  array<string>  $statusLines
     */
    private function handlePermissionBlocked(
        CompletionResult $completion,
        TaskService $taskService,
        array &$statusLines,
        string $agentName
    ): void {
        $taskId = $completion->taskId;

        // Create a needs-human task for permission configuration
        $humanTask = $taskService->create([
            'title' => "Configure agent permissions for {$agentName}",
            'description' => "Agent {$agentName} was blocked from running commands while working on {$taskId}.\n\n".
                "To fix, either:\n".
                "1. Run the agent interactively and select 'Always allow' for tool permissions\n".
                "2. Or add autonomous flags to .fuel/config.yaml agent definition:\n".
                "   - Claude: args: [\"--dangerously-skip-permissions\"]\n".
                "   - cursor-agent: args: [\"--force\"]\n".
                "   - opencode: env: {OPENCODE_PERMISSION: '{\"permission\":\"allow\"}'}\n\n".
                "See README.md 'Agent Permissions' section for details.",
            'labels' => ['needs-human'],
            'priority' => 1,
        ]);

        // Block the original task until permissions are configured
        $taskService->addDependency($taskId, $humanTask['id']);
        $taskService->reopen($taskId);

        $statusLines[] = $this->formatStatus('🔒', "{$taskId} blocked - {$agentName} needs permissions (created {$humanTask['id']})", 'yellow');
    }

    /**
     * @param  array<string>  $statusLines
     */
    private function refreshDisplay(
        TaskService $taskService,
        array $statusLines,
        ProcessManager $processManager,
        bool $paused = false
    ): void {
        // Begin synchronized output (terminal buffers until end marker)
        $this->getOutput()->write("\033[?2026h");
        // Move cursor home and clear screen
        $this->getOutput()->write("\033[H\033[2J");

        // Render board
        $this->call('board', ['--once' => true, '--cwd' => $this->option('cwd')]);

        $this->newLine();

        // Show active processes
        $activeProcesses = $processManager->getActiveProcesses();
        if (! empty($activeProcesses)) {
            $processLines = [];
            foreach ($activeProcesses as $process) {
                $metadata = $process->getMetadata();
                $taskId = $metadata['task_id'];
                $agentName = $metadata['agent_name'];
                $duration = $this->formatDuration($metadata['duration']);
                $shortId = substr($taskId, 2, 6); // Skip 'f-' prefix
                $sessionInfo = '';
                if (! empty($metadata['session_id'])) {
                    $shortSession = substr($metadata['session_id'], 0, 8);
                    $sessionInfo = " 🔗{$shortSession}";
                }
                $processLines[] = "🔄 {$shortId} [{$agentName}] ({$duration}){$sessionInfo}";
            }
            if (! empty($processLines)) {
                $this->line('<fg=yellow>Active: '.implode(' | ', $processLines).'</>');
            }
        }

        // Show failed/stuck tasks
        $excludePids = $processManager->getTrackedPids();
        $failedTasks = $taskService->failed(fn (int $pid): bool => ! ProcessManager::isProcessAlive($pid), $excludePids);
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
        // Clear from cursor to end of screen to remove any leftover content below
        $this->getOutput()->write("\033[J");
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
     * Get cached ready tasks (refreshes if cache expired or after task mutations).
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function getCachedReadyTasks(TaskService $taskService): \Illuminate\Support\Collection
    {
        $now = time();
        if ($this->taskCache['ready'] === null || ($now - $this->taskCache['timestamp']) >= self::TASK_CACHE_TTL) {
            $this->taskCache['ready'] = $taskService->ready();
            $this->taskCache['timestamp'] = $now;
        }

        return $this->taskCache['ready'];
    }

    /**
     * Invalidate task cache (call after mutations like start, update, done).
     */
    private function invalidateTaskCache(): void
    {
        $this->taskCache = ['tasks' => null, 'ready' => null, 'failed' => null, 'timestamp' => 0];
    }

    /**
     * Trim status lines to prevent unbounded growth.
     *
     * @param  array<string>  $statusLines
     * @return array<string>
     */
    private function trimStatusLines(array $statusLines, int $maxLines = 5): array
    {
        if (count($statusLines) > $maxLines) {
            return array_slice($statusLines, -$maxLines);
        }

        return $statusLines;
    }

    /**
     * Calculate a score for task selection based on priority, complexity, and size.
     * Lower score = higher priority (should be selected first).
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
