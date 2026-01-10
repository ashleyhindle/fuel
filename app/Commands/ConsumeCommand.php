<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Contracts\AgentHealthTrackerInterface;
use App\Contracts\ReviewServiceInterface;
use App\Process\CompletionResult;
use App\Process\CompletionType;
use App\Services\ConfigService;
use App\Services\ProcessManager;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Commands\Command;

class ConsumeCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'consume
        {--cwd= : Working directory (defaults to current directory)}
        {--interval=5 : Check interval in seconds when idle}
        {--agent= : Agent name to use (overrides config-based routing)}
        {--prompt=Consume one task from fuel, then land the plane : Prompt to send to agent}
        {--dryrun : Show what would happen without claiming tasks or spawning agents}
        {--health : Show agent health status and exit}';

    protected $description = 'Auto-spawn agents to work through available tasks';

    /** Cache TTL for task data in seconds */
    private const TASK_CACHE_TTL = 2;

    /** @var array{tasks: Collection|null, ready: Collection|null, failed: Collection|null, timestamp: int} */
    private array $taskCache = ['tasks' => null, 'ready' => null, 'failed' => null, 'timestamp' => 0];

    /** Original terminal state for restoration */
    private ?string $originalTty = null;

    /** Whether we've entered alternate screen mode */
    private bool $inAlternateScreen = false;

    /** @var array<string, int> Track retry attempts per task */
    private array $taskRetryAttempts = [];

    public function __construct(
        private TaskService $taskService,
        private ConfigService $configService,
        private RunService $runService,
        private ProcessManager $processManager,
        private ?AgentHealthTrackerInterface $healthTracker = null,
        private ?ReviewServiceInterface $reviewService = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->configureCwd($this->taskService, $this->configService);
        $this->taskService->initialize();

        // Handle --health flag: show health status and exit
        if ($this->option('health')) {
            return $this->displayHealthStatus();
        }

        // Set the cwd on ProcessManager so it uses the correct directory for output
        $cwd = $this->option('cwd') ?: getcwd();
        $this->processManager->setCwd($cwd);

        // Ensure processes directory exists for output capture
        $processesDir = $cwd.'/.fuel/processes';
        if (! is_dir($processesDir)) {
            mkdir($processesDir, 0755, true);
        }

        // Clean up orphaned runs from previous consume crashes
        $this->runService->cleanupOrphanedRuns(fn (int $pid): bool => ! ProcessManager::isProcessAlive($pid));

        $interval = max(1, (int) $this->option('interval'));
        $agentOverride = $this->option('agent');
        $dryrun = $this->option('dryrun');

        // Register ProcessManager signal handlers first
        try {
            $this->processManager->registerSignalHandlers();
        } catch (\RuntimeException $runtimeException) {
            $this->error('Error: '.$runtimeException->getMessage());

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
            while (! $this->processManager->isShuttingDown()) {
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
                    $this->refreshDisplay($statusLines, $paused);
                    usleep(200000); // 200ms

                    continue;
                }

                // Step 1: Fill available slots across all agents (but not if shutting down)
                $readyTasks = $this->getCachedReadyTasks();

                if ($readyTasks->isNotEmpty() && ! $this->processManager->isShuttingDown()) {
                    // Score and sort tasks by priority, complexity, and size
                    $scoredTasks = $readyTasks->map(fn (array $task): array => [
                        'task' => $task,
                        'score' => $this->calculateTaskScore($task),
                    ])->sortBy([
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
                $this->pollAndHandleCompletions($statusLines);

                // Step 3: Check for completed reviews
                $this->checkCompletedReviews($statusLines);

                // Step 4: Check if we have any work or should wait
                if (! $this->processManager->hasActiveProcesses() && $readyTasks->isEmpty()) {
                    // Only add waiting message if not already the last status
                    $waitingMsg = $this->formatStatus('⏳', 'Waiting for tasks...', 'gray');
                    if ($statusLines === [] || end($statusLines) !== $waitingMsg) {
                        $statusLines[] = $waitingMsg;
                        $statusLines = $this->trimStatusLines($statusLines);
                    }

                    $this->setTerminalTitle('fuel: Waiting for tasks...');
                    $this->refreshDisplay($statusLines, $paused);

                    // Poll while waiting
                    for ($i = 0; $i < $interval * 10 && ! $this->processManager->isShuttingDown(); $i++) {
                        \pcntl_signal_dispatch();
                        // Check for pause toggle while waiting
                        if ($this->checkForPauseToggle()) {
                            $paused = ! $paused;
                            $statusLines[] = $paused
                                ? $this->formatStatus('⏸', 'PAUSED - press Shift+Tab to resume', 'yellow')
                                : $this->formatStatus('▶', 'Resumed - looking for tasks...', 'green');
                            $statusLines = $this->trimStatusLines($statusLines);
                            $this->refreshDisplay($statusLines, $paused);
                        }

                        usleep(100000); // 100ms
                    }

                    // Invalidate cache after waiting period so we get fresh data
                    $this->invalidateTaskCache();

                    continue;
                }

                // Update display with current state
                $this->refreshDisplay($statusLines, $paused);

                // Update terminal title with active process count
                $activeCount = $this->processManager->getActiveCount();
                if ($activeCount > 0) {
                    $this->setTerminalTitle(sprintf('fuel: %d active', $activeCount));
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
        ?string $agentOverride,
        bool $dryrun,
        array &$statusLines
    ): bool {
        // Don't spawn new tasks if shutting down
        if ($this->processManager->isShuttingDown()) {
            return false;
        }

        $taskId = $task['id'];
        $taskTitle = $task['title'];
        $shortTitle = mb_strlen((string) $taskTitle) > 40 ? mb_substr((string) $taskTitle, 0, 37).'...' : $taskTitle;

        // Build structured prompt with task details
        $cwd = $this->option('cwd') ?: getcwd();
        $taskDetails = $this->formatTaskForPrompt($task);

        $fullPrompt = <<<PROMPT
IMPORTANT: You are being orchestrated. Trust the system.

== YOUR ASSIGNMENT ==
You are assigned EXACTLY ONE task: {$taskId}
You must ONLY work on this task. Nothing else.

== TASK DETAILS ==
{$taskDetails}

== TEAMWORK - YOU ARE NOT ALONE ==
You are ONE agent in a team working in parallel on this codebase.
Other teammates are working on other tasks RIGHT NOW. They're counting on you to:
- Stay in your lane (only work on YOUR assigned task)
- Not step on their toes (don't touch tasks assigned to others)
- Be a good teammate (log discovered work for others, don't hoard it)

Breaking these rules wastes your teammates' work and corrupts the workflow:

FORBIDDEN - DO NOT DO THESE:
- NEVER run `fuel start` on ANY task (your task is already started)
- NEVER run `fuel ready` or `fuel board` (you don't need to see other tasks)
- NEVER work on tasks other than {$taskId}, even if you see them
- NEVER "help" by picking up additional work - other agents will handle it

ALLOWED:
- `fuel add "..."` to LOG discovered work for OTHER agents to do later
- `fuel done {$taskId}` to mark YOUR task complete
- `fuel dep:add {$taskId} <other-task>` to add dependencies to YOUR task

== WHEN BLOCKED ==
If you need human input (credentials, decisions, file permissions):
1. ./fuel add 'What you need' --labels=needs-human --description='Exact steps for human'
2. ./fuel dep:add {$taskId} <needs-human-task-id>
3. Exit immediately - do NOT wait or retry

== CLOSING PROTOCOL ==
Before exiting, you MUST:
1. If you changed code: run tests and linter/formatter
2. git add <files> && git commit -m "feat/fix: description"
3. ./fuel done {$taskId}
4. ./fuel add "..." for any discovered/incomplete work (DO NOT work on these - just log them)

== CONTEXT ==
Working directory: {$cwd}
Task ID: {$taskId}
PROMPT;

        // Determine agent name for capacity check and dryrun display
        $agentName = $agentOverride;
        if ($agentName === null) {
            $complexity = $task['complexity'] ?? 'simple';
            try {
                $agentName = $this->configService->getAgentForComplexity($complexity);
            } catch (\RuntimeException $e) {
                $this->error('Failed to get agent: '.$e->getMessage());
                $this->line('Use --agent to override or ensure .fuel/config.yaml exists');

                return false;
            }
        }

        // Check capacity before dryrun display (so we skip at-capacity agents in dryrun too)
        if (! $dryrun && ! $this->processManager->canSpawn($agentName)) {
            return false; // At capacity, can't spawn
        }

        // Check agent health / backoff before attempting to spawn
        if (! $dryrun && $this->healthTracker !== null && ! $this->healthTracker->isAvailable($agentName)) {
            $backoffSeconds = $this->healthTracker->getBackoffSeconds($agentName);
            $formatted = $backoffSeconds < 60
                ? "{$backoffSeconds}s"
                : sprintf('%dm %ds', (int) ($backoffSeconds / 60), $backoffSeconds % 60);

            // Only show message once per backoff period (check if already shown recently)
            $statusLines[] = $this->formatStatus('⏳', sprintf('%s waiting - %s in backoff (%s)', $taskId, $agentName, $formatted), 'gray');

            return false; // Agent in backoff, don't spawn
        }

        if ($dryrun) {
            // Dryrun: show what would happen without claiming or spawning
            $statusLines[] = $this->formatStatus('👁', sprintf('[DRYRUN] Would spawn %s for %s: %s', $agentName, $taskId, $shortTitle), 'cyan');
            $this->setTerminalTitle('fuel: [DRYRUN] '.$taskId);
            $this->newLine();
            $this->line('<fg=cyan>== PROMPT THAT WOULD BE SENT ==</>');
            $this->line($fullPrompt);

            return true;
        }

        // Mark task as in_progress and flag as consumed before spawning agent
        $this->taskService->start($taskId);
        $this->taskService->update($taskId, [
            'consumed' => true,
        ]);
        $this->invalidateTaskCache();

        // Spawn via ProcessManager
        $result = $this->processManager->spawnForTask($task, $fullPrompt, $cwd, $agentOverride);

        if (! $result->success) {
            // Agent in backoff should already be caught above, but handle just in case
            if ($result->isInBackoff()) {
                $this->taskService->reopen($taskId);
                $this->invalidateTaskCache();

                return false;
            }

            $this->error($result->error ?? 'Unknown spawn error');

            // Revert task state
            $this->taskService->reopen($taskId);
            $this->invalidateTaskCache();

            return false;
        }

        $process = $result->process;
        $pid = $process->getPid();

        // Create run entry with started_at
        $this->runService->logRun($taskId, [
            'agent' => $process->getAgentName(),
            'started_at' => date('c'),
        ]);

        // Store the process PID in the task
        $this->taskService->update($taskId, [
            'consume_pid' => $pid,
        ]);

        $statusLines[] = $this->formatStatus('🚀', sprintf('Spawning %s for %s: %s', $process->getAgentName(), $taskId, $shortTitle), 'yellow');

        return true;
    }

    /**
     * Poll all running processes and handle completions.
     *
     * @param  array<string>  $statusLines
     */
    private function pollAndHandleCompletions(
        array &$statusLines
    ): void {
        // Also update session_id in run service as processes are polled
        foreach ($this->processManager->getActiveProcesses() as $process) {
            if ($process->getSessionId() !== null) {
                $this->runService->updateLatestRun($process->getTaskId(), [
                    'session_id' => $process->getSessionId(),
                ]);
            }
        }

        $completions = $this->processManager->poll();

        foreach ($completions as $completion) {
            $this->handleCompletion($completion, $statusLines);
        }

        // Keep only last 5 status lines
        $statusLines = $this->trimStatusLines($statusLines);
    }

    /**
     * Check for completed reviews and process their results.
     *
     * @param  array<string>  $statusLines
     */
    private function checkCompletedReviews(array &$statusLines): void
    {
        if ($this->reviewService === null) {
            return;
        }

        foreach ($this->reviewService->getPendingReviews() as $taskId) {
            if ($this->reviewService->isReviewComplete($taskId)) {
                $result = $this->reviewService->getReviewResult($taskId);
                if ($result === null) {
                    continue;
                }

                if ($result->passed) {
                    // Review passed - mark task as done
                    Artisan::call('done', [
                        'ids' => [$taskId],
                        '--reason' => 'Review passed',
                    ]);
                    $statusLines[] = $this->formatStatus('✓', sprintf('Review passed for %s', $taskId), 'green');
                } else {
                    // Review found issues - task stays in 'review' status
                    $issuesSummary = empty($result->issues) ? 'issues found' : implode(', ', $result->issues);
                    $statusLines[] = $this->formatStatus('⚠', sprintf('Review found issues for %s: %s', $taskId, $issuesSummary), 'yellow');

                    // If follow-up tasks were created, show them
                    if (! empty($result->followUpTaskIds)) {
                        $statusLines[] = $this->formatStatus('📝', sprintf('Follow-up tasks created: %s', implode(', ', $result->followUpTaskIds)), 'cyan');
                    }
                }

                $this->invalidateTaskCache();
            }
        }

        // Trim status lines after processing reviews
        $statusLines = $this->trimStatusLines($statusLines);
    }

    /**
     * Handle a completed process result.
     *
     * @param  array<string>  $statusLines
     */
    private function handleCompletion(
        CompletionResult $completion,
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

        $this->runService->updateLatestRun($taskId, $runData);

        // Clear PID from task
        $this->taskService->update($taskId, [
            'consume_pid' => null,
        ]);

        // Handle by completion type
        match ($completion->type) {
            CompletionType::Success => $this->handleSuccess($completion, $statusLines, $durationStr),
            CompletionType::Failed => $this->handleFailure($completion, $statusLines, $durationStr),
            CompletionType::NetworkError => $this->handleNetworkError($completion, $statusLines, $durationStr),
            CompletionType::PermissionBlocked => $this->handlePermissionBlocked($completion, $statusLines, $agentName),
        };

        $this->invalidateTaskCache();
    }

    /**
     * @param  array<string>  $statusLines
     */
    private function handleSuccess(
        CompletionResult $completion,
        array &$statusLines,
        string $durationStr
    ): void {
        $taskId = $completion->taskId;

        // Record success with health tracker
        if ($this->healthTracker !== null) {
            $this->healthTracker->recordSuccess($completion->agentName);
        }

        // Clear retry attempts on success
        unset($this->taskRetryAttempts[$taskId]);

        // Check task status
        $task = $this->taskService->find($taskId);
        if (! $task || $task['status'] !== 'in_progress') {
            // Task was already closed by agent via 'fuel done'
            $statusLines[] = $this->formatStatus('✓', sprintf('%s completed (%s)', $taskId, $durationStr), 'green');

            return;
        }

        // Task still in_progress - agent didn't call 'fuel done'
        // Trigger review if ReviewService is available
        if ($this->reviewService !== null) {
            try {
                $this->reviewService->triggerReview($taskId, $completion->agentName);
                $statusLines[] = $this->formatStatus('🔍', sprintf('%s completed, triggering review... (%s)', $taskId, $durationStr), 'cyan');
            } catch (\RuntimeException $e) {
                // Review failed to trigger - fall back to auto-complete
                $this->fallbackAutoComplete($taskId, $statusLines, $durationStr);
            }
        } else {
            // No ReviewService - fall back to auto-complete
            $this->fallbackAutoComplete($taskId, $statusLines, $durationStr);
        }
    }

    /**
     * Fall back to auto-completing the task when review is not available.
     *
     * @param  array<string>  $statusLines
     */
    private function fallbackAutoComplete(
        string $taskId,
        array &$statusLines,
        string $durationStr
    ): void {
        // Add 'auto-closed' label to indicate it wasn't self-reported
        $this->taskService->update($taskId, [
            'add_labels' => ['auto-closed'],
        ]);

        // Use DoneCommand logic so future done enhancements apply automatically
        Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Auto-completed by consume (agent exit 0)',
        ]);

        $statusLines[] = $this->formatStatus('✓', sprintf('%s auto-completed (%s)', $taskId, $durationStr), 'green');
    }

    /**
     * @param  array<string>  $statusLines
     */
    private function handleFailure(
        CompletionResult $completion,
        array &$statusLines,
        string $durationStr
    ): void {
        $taskId = $completion->taskId;
        $agentName = $completion->agentName;

        // Record failure with health tracker
        $failureType = $completion->toFailureType();
        if ($this->healthTracker !== null && $failureType !== null) {
            $this->healthTracker->recordFailure($agentName, $failureType);
        }

        // Check if we should retry (crash errors are retryable with limit)
        $retryAttempts = $this->taskRetryAttempts[$taskId] ?? 0;
        $maxAttempts = $this->configService->getAgentMaxAttempts($agentName);

        // Crash errors are retryable but limited by max_attempts
        if ($completion->isRetryable() && $retryAttempts < $maxAttempts - 1) {
            // Increment retry counter
            $this->taskRetryAttempts[$taskId] = $retryAttempts + 1;

            // Reopen task so it can be retried
            $this->taskService->reopen($taskId);

            // Get backoff time if health tracker is available
            $backoffInfo = '';
            if ($this->healthTracker !== null) {
                $backoffSeconds = $this->healthTracker->getBackoffSeconds($agentName);
                if ($backoffSeconds > 0) {
                    $backoffInfo = sprintf(', backoff %ds', $backoffSeconds);
                }
            }

            $statusLines[] = $this->formatStatus('🔄', sprintf(
                '%s failed (exit %d, %s), retry %d/%d%s',
                $taskId,
                $completion->exitCode,
                $durationStr,
                $retryAttempts + 1,
                $maxAttempts - 1,
                $backoffInfo
            ), 'yellow');
        } else {
            // Max retries reached or not retryable
            $statusLines[] = $this->formatStatus('✗', sprintf('%s failed (exit %d, %s)', $taskId, $completion->exitCode, $durationStr), 'red');
        }
    }

    /**
     * @param  array<string>  $statusLines
     */
    private function handleNetworkError(
        CompletionResult $completion,
        array &$statusLines,
        string $durationStr
    ): void {
        $taskId = $completion->taskId;
        $agentName = $completion->agentName;

        // Record failure with health tracker (network errors trigger backoff)
        $failureType = $completion->toFailureType();
        if ($this->healthTracker !== null && $failureType !== null) {
            $this->healthTracker->recordFailure($agentName, $failureType);
        }

        // Check retry attempts
        $retryAttempts = $this->taskRetryAttempts[$taskId] ?? 0;
        $maxAttempts = $this->configService->getAgentMaxAttempts($agentName);

        if ($retryAttempts < $maxAttempts - 1) {
            // Increment retry counter
            $this->taskRetryAttempts[$taskId] = $retryAttempts + 1;

            // Reopen task so it can be retried on next cycle
            $this->taskService->reopen($taskId);

            // Get backoff time info
            $backoffInfo = '';
            if ($this->healthTracker !== null) {
                $backoffSeconds = $this->healthTracker->getBackoffSeconds($agentName);
                if ($backoffSeconds > 0) {
                    $backoffInfo = sprintf(', backoff %ds', $backoffSeconds);
                }
            }

            $statusLines[] = $this->formatStatus('🔄', sprintf(
                '%s network error, retry %d/%d%s',
                $taskId,
                $retryAttempts + 1,
                $maxAttempts - 1,
                $backoffInfo
            ), 'yellow');
        } else {
            // Max retries reached
            $statusLines[] = $this->formatStatus('✗', sprintf('%s network error, max retries reached (%s)', $taskId, $durationStr), 'red');
        }
    }

    /**
     * @param  array<string>  $statusLines
     */
    private function handlePermissionBlocked(
        CompletionResult $completion,
        array &$statusLines,
        string $agentName
    ): void {
        $taskId = $completion->taskId;

        // Record failure with health tracker (permission errors don't trigger backoff)
        $failureType = $completion->toFailureType();
        if ($this->healthTracker !== null && $failureType !== null) {
            $this->healthTracker->recordFailure($agentName, $failureType);
        }

        // Clear retry attempts - permission errors need human intervention
        unset($this->taskRetryAttempts[$taskId]);

        // Create a needs-human task for permission configuration
        $humanTask = $this->taskService->create([
            'title' => 'Configure agent permissions for '.$agentName,
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
        $this->taskService->addDependency($taskId, $humanTask['id']);
        $this->taskService->reopen($taskId);

        $statusLines[] = $this->formatStatus('🔒', sprintf('%s blocked - %s needs permissions (created %s)', $taskId, $agentName, $humanTask['id']), 'yellow');
    }

    /**
     * @param  array<string>  $statusLines
     */
    private function refreshDisplay(
        array $statusLines,
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
        $activeProcesses = $this->processManager->getActiveProcesses();
        if ($activeProcesses !== []) {
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
                    $sessionInfo = ' 🔗'.$shortSession;
                }

                $processLines[] = sprintf('🔄 %s [%s] (%s)%s', $shortId, $agentName, $duration, $sessionInfo);
            }

            $this->line('<fg=yellow>Active: '.implode(' | ', $processLines).'</>');
        }

        // Show failed/stuck tasks
        $excludePids = $this->processManager->getTrackedPids();
        $failedTasks = $this->taskService->failed(fn (int $pid): bool => ! ProcessManager::isProcessAlive($pid), $excludePids);
        if ($failedTasks->isNotEmpty()) {
            $failedLines = [];
            foreach ($failedTasks as $task) {
                $shortId = substr((string) $task['id'], 2, 6);
                $failedLines[] = '🪫 '.$shortId;
            }

            $this->line('<fg=red>Failed: '.implode(' | ', $failedLines).' (fuel retry)</>');
        }

        // Show agent health status (unhealthy/degraded agents only)
        $healthLines = $this->getHealthStatusLines();
        if ($healthLines !== []) {
            foreach ($healthLines as $healthLine) {
                $this->line($healthLine);
            }
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
        return sprintf('<fg=%s>%s %s</>', $color, $icon, $message);
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = (int) ($seconds / 60);
        $secs = $seconds % 60;

        return sprintf('%dm %ds', $minutes, $secs);
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
     * @return Collection<int, array<string, mixed>>
     */
    private function getCachedReadyTasks(): Collection
    {
        $now = time();
        if ($this->taskCache['ready'] === null || ($now - $this->taskCache['timestamp']) >= self::TASK_CACHE_TTL) {
            $this->taskCache['ready'] = $this->taskService->ready();
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
            'Task: '.$task['id'],
            'Title: '.$task['title'],
            'Status: '.$task['status'],
        ];

        if (! empty($task['description'])) {
            $lines[] = 'Description: '.$task['description'];
        }

        if (! empty($task['type'])) {
            $lines[] = 'Type: '.$task['type'];
        }

        if (! empty($task['priority'])) {
            $lines[] = 'Priority: P'.$task['priority'];
        }

        if (! empty($task['labels'])) {
            $lines[] = 'Labels: '.implode(', ', $task['labels']);
        }

        if (! empty($task['blocked_by'])) {
            $lines[] = 'Blocked by: '.implode(', ', $task['blocked_by']);
        }

        return implode("\n", $lines);
    }

    /**
     * Display agent health status and exit.
     */
    private function displayHealthStatus(): int
    {
        if ($this->healthTracker === null) {
            $this->error('Health tracker not available');

            return self::FAILURE;
        }

        $agentNames = $this->configService->getAgentNames();
        if ($agentNames === []) {
            $this->line('<fg=yellow>No agents configured</>');

            return self::SUCCESS;
        }

        $this->line('<fg=white;options=bold>Agent Health Status</>');
        $this->newLine();

        foreach ($agentNames as $agentName) {
            $health = $this->healthTracker->getHealthStatus($agentName);
            $status = $health->getStatus();
            $color = match ($status) {
                'healthy' => 'green',
                'warning' => 'yellow',
                'degraded' => 'yellow',
                'unhealthy' => 'red',
                default => 'gray',
            };

            $statusIcon = match ($status) {
                'healthy' => '✓',
                'warning' => '⚠',
                'degraded' => '⚠',
                'unhealthy' => '✗',
                default => '?',
            };

            $line = sprintf('<fg=%s>%s %s</>', $color, $statusIcon, $agentName);

            // Add consecutive failures info
            if ($health->consecutiveFailures > 0) {
                $line .= sprintf(' <fg=gray>(%d consecutive failure%s)</>', $health->consecutiveFailures, $health->consecutiveFailures === 1 ? '' : 's');
            }

            // Add backoff info if in backoff
            $backoffSeconds = $health->getBackoffSeconds();
            if ($backoffSeconds > 0) {
                $formatted = $backoffSeconds < 60
                    ? "{$backoffSeconds}s"
                    : sprintf('%dm %ds', (int) ($backoffSeconds / 60), $backoffSeconds % 60);
                $line .= sprintf(' <fg=yellow>backoff: %s</>', $formatted);
            }

            // Add success rate if available
            $successRate = $health->getSuccessRate();
            if ($successRate !== null) {
                $line .= sprintf(' <fg=gray>(%.0f%% success rate)</>', $successRate);
            }

            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * Get health status summary for display in consume output.
     * Returns array of formatted health status lines.
     *
     * @return array<string>
     */
    private function getHealthStatusLines(): array
    {
        if ($this->healthTracker === null) {
            return [];
        }

        $agentNames = $this->configService->getAgentNames();
        $unhealthyAgents = [];

        foreach ($agentNames as $agentName) {
            $health = $this->healthTracker->getHealthStatus($agentName);
            $status = $health->getStatus();

            // Only show unhealthy/degraded agents
            if ($status === 'unhealthy' || $status === 'degraded') {
                $backoffSeconds = $health->getBackoffSeconds();
                $formatted = $backoffSeconds < 60
                    ? "{$backoffSeconds}s"
                    : sprintf('%dm %ds', (int) ($backoffSeconds / 60), $backoffSeconds % 60);

                $color = $status === 'unhealthy' ? 'red' : 'yellow';
                $icon = $status === 'unhealthy' ? '✗' : '⚠';

                $unhealthyAgents[] = $this->formatStatus(
                    $icon,
                    sprintf(
                        'Agent %s is %s (%d consecutive failures, backoff: %s)',
                        $agentName,
                        $status,
                        $health->consecutiveFailures,
                        $formatted
                    ),
                    $color
                );
            }
        }

        return $unhealthyAgents;
    }
}
