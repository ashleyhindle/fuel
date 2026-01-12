<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Contracts\AgentHealthTrackerInterface;
use App\Contracts\ReviewServiceInterface;
use App\Enums\FailureType;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Process\CompletionResult;
use App\Process\CompletionType;
use App\Process\ProcessType;
use App\Process\ReviewResult;
use App\Services\BackoffStrategy;
use App\Services\ConfigService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
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
        {--health : Show agent health status and exit}
        {--skip-review : Skip automatic review of completed work}';

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

    /** @var array<string, array{status: string, in_backoff: bool, is_dead: bool}> Track previous health state per agent */
    private array $previousHealthStates = [];

    /** @var array<string, string> Track original task status before review (to handle already-closed tasks) */
    private array $preReviewTaskStatus = [];

    public function __construct(
        private TaskService $taskService,
        private ConfigService $configService,
        private RunService $runService,
        private ProcessManager $processManager,
        private FuelContext $fuelContext,
        private DatabaseService $databaseService,
        private BackoffStrategy $backoffStrategy,
        private ?AgentHealthTrackerInterface $healthTracker = null,
        private ?ReviewServiceInterface $reviewService = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->configureCwd($this->fuelContext, $this->databaseService);

        // Validate config early before entering TUI
        try {
            $this->configService->validate();
        } catch (\RuntimeException $runtimeException) {
            $this->error('Config validation failed: '.$runtimeException->getMessage());

            return self::FAILURE;
        }

        // Handle --health flag: show health status and exit
        if ($this->option('health')) {
            return $this->displayHealthStatus();
        }

        // Ensure processes directory exists for output capture
        $processesDir = $this->fuelContext->getProcessesPath();
        if (! is_dir($processesDir)) {
            mkdir($processesDir, 0755, true);
        }

        // Clean up orphaned runs from previous consume crashes
        $this->runService->cleanupOrphanedRuns(fn (int $pid): bool => ! ProcessManager::isProcessAlive($pid));

        // Recover stuck reviews (tasks in 'review' status with no active review process)
        if ($this->reviewService instanceof ReviewServiceInterface) {
            $recoveredReviews = $this->reviewService->recoverStuckReviews();
            foreach ($recoveredReviews as $taskId) {
                $this->info(sprintf('Recovered stuck review for task %s', $taskId));
            }
        }

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

        // Detect non-interactive mode (tests, CI, etc.) - run only one iteration
        $singleIteration = (function_exists('posix_isatty') && ! posix_isatty(STDOUT)) ||
                          (method_exists(app(), 'runningUnitTests') && app()->runningUnitTests()) ||
                          app()->environment('testing');

        // Skip terminal manipulation in non-interactive mode (tests, CI)
        if (! $singleIteration) {
            // Save terminal state and register shutdown handler BEFORE modifying terminal
            $this->originalTty = shell_exec('stty -g');
            register_shutdown_function([$this, 'restoreTerminal']);

            $this->getOutput()->write("\033[?1049h");
            $this->inAlternateScreen = true;
            $this->getOutput()->write("\033[?25l"); // Hide cursor
            $this->getOutput()->write("\033[H\033[2J");

            shell_exec('stty -icanon -echo');
            stream_set_blocking(STDIN, false);
        }

        // In non-interactive mode, start unpaused so we process tasks immediately
        $paused = ! $singleIteration;

        $statusLines = [];

        try {
            while (! $this->processManager->isShuttingDown()) {
                \pcntl_signal_dispatch();

                // Reload config on each iteration to pick up changes
                $this->configService->reload();

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
                    // Sort tasks by priority then creation date (FIFO within priority)
                    $sortedTasks = $readyTasks->sortBy([
                        ['priority', 'asc'],
                        ['created_at', 'asc'],
                    ])->values();

                    // Try to spawn tasks until we can't spawn any more
                    foreach ($sortedTasks as $task) {

                        // Try to spawn this task
                        $spawned = $this->trySpawnTask(
                            $task,
                            $agentOverride,
                            $dryrun,
                            $statusLines
                        );

                        if ($dryrun && $spawned) {
                            // In dryrun mode, show what would happen
                            if ($singleIteration) {
                                // In non-interactive mode (tests), ensure output is flushed before exiting
                                // The prompt was already output in trySpawnTask, so we can exit immediately
                                if (ob_get_level() > 0) {
                                    ob_flush();
                                }

                                flush();
                                break 2; // Break out of foreach and while loop
                            }

                            // In interactive mode, show message and wait
                            $this->newLine();
                            $this->line('<fg=gray>Press Ctrl+C to exit, or wait to see next task...</>');
                            sleep(3);
                        }
                    }
                }

                // Step 2: Check agent health status changes
                $this->checkAgentHealthChanges($statusLines);

                // Step 3: Poll all running processes
                $this->pollAndHandleCompletions($statusLines);

                // Step 4: Check for completed reviews
                $this->checkCompletedReviews($statusLines);

                // Step 5: Check if we have any work or should wait
                if (! $this->processManager->hasActiveProcesses() && $readyTasks->isEmpty()) {
                    // In single iteration mode, exit immediately if no tasks
                    if ($singleIteration) {
                        break;
                    }

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

                // Exit after one iteration in non-interactive mode (tests, CI, etc.)
                if ($singleIteration) {
                    break;
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
        Task $task,
        ?string $agentOverride,
        bool $dryrun,
        array &$statusLines
    ): bool {
        // Don't spawn new tasks if shutting down
        if ($this->processManager->isShuttingDown()) {
            return false;
        }

        $taskId = $task->short_id;
        $taskTitle = $task->title;
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
2. Run `git status` to see modified files
3. Run `git add <files>` for each file YOU modified (not files from other agents)
4. VERIFY: `git diff --cached --stat` shows all YOUR changes are staged
5. git commit -m "feat/fix: description"
6. ./fuel done {$taskId} --commit=<hash>
7. ./fuel add "..." for any discovered/incomplete work (DO NOT work on these - just log them)

CRITICAL: If you skip git add, your work will be lost. Verify YOUR files are staged before commit.

⚠️  FILE COLLISION WARNING:
If you see files in `git status` that you did NOT modify, DO NOT stage them with `git add`.
Other agents may have modified those files while you were working. Only stage files YOU changed.

CRITICAL - If you worked on the same file as another agent:
- DO NOT remove, overwrite, or undo their changes
- DO NOT assume your version is correct and theirs is wrong
- Use `git diff <file>` to see ALL changes in the file
- Preserve ALL changes from both agents - merge them together if needed
- If you cannot safely merge, create a needs-human task and block yourself
- When in doubt, preserve other agents' work - it's easier to add than to recover deleted work

== CONTEXT ==
Working directory: {$cwd}
Task ID: {$taskId}
PROMPT;

        // Determine agent name for capacity check and dryrun display
        $agentName = $agentOverride;
        if ($agentName === null) {
            $complexity = $task->complexity ?? 'simple';
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
        if (! $dryrun && $this->healthTracker instanceof AgentHealthTrackerInterface && ! $this->healthTracker->isAvailable($agentName)) {
            $backoffSeconds = $this->healthTracker->getBackoffSeconds($agentName);
            $formatted = $this->backoffStrategy->formatBackoffTime($backoffSeconds);

            // Only show message once per backoff period (check if already shown recently)
            $statusLines[] = $this->formatStatus('⏳', sprintf('%s waiting - %s in backoff (%s)', $taskId, $agentName, $formatted), 'gray');

            return false; // Agent in backoff, don't spawn
        }

        // Check if agent is dead (exceeded max_retries consecutive failures)
        if (! $dryrun && $this->healthTracker instanceof AgentHealthTrackerInterface) {
            $maxRetries = $this->configService->getAgentMaxRetries($agentName);
            if ($this->healthTracker->isDead($agentName, $maxRetries)) {
                $statusLines[] = $this->formatStatus('💀', sprintf('%s skipped - %s is dead (>= %d consecutive failures)', $taskId, $agentName, $maxRetries), 'red');

                return false; // Agent is dead, don't assign work
            }
        }

        if ($dryrun) {
            // Dryrun: show what would happen without claiming or spawning
            $statusLines[] = $this->formatStatus('👁', sprintf('[DRYRUN] Would spawn %s for %s: %s', $agentName, $taskId, $shortTitle), 'cyan');
            $this->setTerminalTitle('fuel: [DRYRUN] '.$taskId);
            $this->newLine();
            $this->line('<fg=cyan>== PROMPT THAT WOULD BE SENT ==</>');
            // In single iteration mode (tests), output directly to ensure it's captured
            // Use Laravel's line() method which handles output buffering correctly
            $this->line($fullPrompt);

            return true;
        }

        // Mark task as in_progress and flag as consumed before spawning agent
        $this->taskService->start($taskId);
        $this->taskService->update($taskId, [
            'consumed' => true,
        ]);
        $this->invalidateTaskCache();

        // Determine agent and model for run entry
        $complexity = $task->complexity ?? 'simple';
        $runAgentName = $agentOverride ?? $this->configService->getAgentForComplexity($complexity);
        $agentDef = $this->configService->getAgentDefinition($runAgentName);
        $runModel = $agentDef['model'] ?? null;

        // Create run entry before spawning to get run ID for process directory
        $runId = $this->runService->createRun($taskId, [
            'agent' => $runAgentName,
            'model' => $runModel,
            'started_at' => date('c'),
        ]);

        // Spawn via ProcessManager with run ID
        $result = $this->processManager->spawnForTask($task->toArray(), $fullPrompt, $cwd, $agentOverride, $runId);

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
        // Skip review processes as they don't have run entries
        foreach ($this->processManager->getActiveProcesses() as $process) {
            if ($process->getProcessType() === ProcessType::Review) {
                continue;
            }

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
        if (! $this->reviewService instanceof ReviewServiceInterface) {
            return;
        }

        foreach ($this->reviewService->getPendingReviews() as $taskId) {
            if ($this->reviewService->isReviewComplete($taskId)) {
                $result = $this->reviewService->getReviewResult($taskId);
                if (! $result instanceof ReviewResult) {
                    continue;
                }

                // Check if task was already closed before review
                $wasAlreadyClosed = isset($this->preReviewTaskStatus[$taskId]);
                $originalStatus = $this->preReviewTaskStatus[$taskId] ?? null;
                unset($this->preReviewTaskStatus[$taskId]);

                if ($result->passed) {
                    // Review passed
                    if ($wasAlreadyClosed) {
                        // Task was already closed - confirm done (maybe update reason)
                        $task = $this->taskService->find($taskId);
                        if ($task && ($task->status ?? '') !== TaskStatus::Closed->value) {
                            // Task status changed (shouldn't happen, but handle gracefully)
                            Artisan::call('done', [
                                'ids' => [$taskId],
                                '--reason' => 'Review passed (was already closed)',
                            ]);
                        }

                        $statusLines[] = $this->formatStatus('✓', sprintf('Review passed for %s (was already closed)', $taskId), 'green');
                    } else {
                        // Task was in_progress - mark as done
                        Artisan::call('done', [
                            'ids' => [$taskId],
                            '--reason' => 'Review passed',
                        ]);
                        $statusLines[] = $this->formatStatus('✓', sprintf('Review passed for %s', $taskId), 'green');
                    }
                } else {
                    // Review found issues - reopen task if it was already closed
                    $issuesSummary = $result->issues === [] ? 'issues found' : implode(', ', $result->issues);

                    // Store the review issues on the task for the next agent run
                    if ($result->issues !== []) {
                        $this->taskService->setLastReviewIssues($taskId, $result->issues);
                    }

                    if ($wasAlreadyClosed) {
                        // Task was already closed but review failed - reopen with issues
                        try {
                            $this->taskService->reopen($taskId);
                            $statusLines[] = $this->formatStatus('⚠', sprintf('Review found issues for %s (reopened): %s', $taskId, $issuesSummary), 'yellow');
                        } catch (\RuntimeException $e) {
                            $statusLines[] = $this->formatStatus('⚠', sprintf('Review found issues for %s: %s (could not reopen: %s)', $taskId, $issuesSummary, $e->getMessage()), 'yellow');
                        }
                    } else {
                        // Task needs to be reopened so it can be retried
                        try {
                            $this->taskService->reopen($taskId);
                            $statusLines[] = $this->formatStatus('⚠', sprintf('Review found issues for %s (reopened): %s', $taskId, $issuesSummary), 'yellow');
                        } catch (\RuntimeException) {
                            $statusLines[] = $this->formatStatus('⚠', sprintf('Review found issues for %s: %s', $taskId, $issuesSummary), 'yellow');
                        }
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
        // Review completions are handled separately by checkCompletedReviews()
        if ($completion->isReview()) {
            return;
        }

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

        if ($completion->model !== null) {
            $runData['model'] = $completion->model;
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
        if ($this->healthTracker instanceof AgentHealthTrackerInterface) {
            $this->healthTracker->recordSuccess($completion->agentName);
        }

        // Clear retry attempts on success
        unset($this->taskRetryAttempts[$taskId]);

        // Check task status
        $task = $this->taskService->find($taskId);
        if (! $task instanceof Task) {
            // Task was deleted?
            $statusLines[] = $this->formatStatus('?', sprintf('%s completed but task not found (%s)', $taskId, $durationStr), 'yellow');

            return;
        }

        // Always trigger review as quality gate for ALL completions (Phase 3 spec)
        // Track original status to handle already-closed tasks correctly
        $originalStatus = $task->status ?? TaskStatus::InProgress->value;
        $wasAlreadyClosed = $originalStatus === TaskStatus::Closed->value;

        if ($this->option('skip-review')) {
            // Skip review and mark done directly
            if (! $wasAlreadyClosed) {
                $this->taskService->done($taskId, 'Auto-completed by consume (review skipped)');
            }

            $statusLines[] = $this->formatStatus('✓', sprintf('%s completed (review skipped) (%s)', $taskId, $durationStr), 'green');
        } elseif ($this->reviewService instanceof ReviewServiceInterface) {
            // Trigger review if ReviewService is available
            try {
                // Store original status before triggering review
                if ($wasAlreadyClosed) {
                    $this->preReviewTaskStatus[$taskId] = $originalStatus;
                }

                $reviewTriggered = $this->reviewService->triggerReview($taskId, $completion->agentName);
                if ($reviewTriggered) {
                    $statusLines[] = $this->formatStatus('🔍', sprintf('%s completed, triggering review... (%s)', $taskId, $durationStr), 'cyan');
                } else {
                    // No review agent configured - auto-complete with warning
                    $this->fallbackAutoComplete($taskId, $statusLines, $durationStr, true);
                }
            } catch (\RuntimeException) {
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
     * @param  bool  $noReviewAgent  Whether this is due to no review agent configured (shows warning)
     */
    private function fallbackAutoComplete(
        string $taskId,
        array &$statusLines,
        string $durationStr,
        bool $noReviewAgent = false
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

        if ($noReviewAgent) {
            $statusLines[] = $this->formatStatus('⚠', sprintf('%s auto-completed - no review agent configured (%s)', $taskId, $durationStr), 'yellow');
        } else {
            $statusLines[] = $this->formatStatus('✓', sprintf('%s auto-completed (%s)', $taskId, $durationStr), 'green');
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
        $taskId = $completion->taskId;
        $agentName = $completion->agentName;

        // Record failure with health tracker
        $failureType = $completion->toFailureType();
        if ($this->healthTracker instanceof AgentHealthTrackerInterface && $failureType instanceof FailureType) {
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
            if ($this->healthTracker instanceof AgentHealthTrackerInterface) {
                $backoffSeconds = $this->healthTracker->getBackoffSeconds($agentName);
                if ($backoffSeconds > 0) {
                    $backoffInfo = sprintf(', backoff %s', $this->backoffStrategy->formatBackoffTime($backoffSeconds));
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
        if ($this->healthTracker instanceof AgentHealthTrackerInterface && $failureType instanceof FailureType) {
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
            if ($this->healthTracker instanceof AgentHealthTrackerInterface) {
                $backoffSeconds = $this->healthTracker->getBackoffSeconds($agentName);
                if ($backoffSeconds > 0) {
                    $backoffInfo = sprintf(', backoff %s', $this->backoffStrategy->formatBackoffTime($backoffSeconds));
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
        if ($this->healthTracker instanceof AgentHealthTrackerInterface && $failureType instanceof FailureType) {
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
        $this->taskService->addDependency($taskId, $humanTask->short_id);
        $this->taskService->reopen($taskId);

        $statusLines[] = $this->formatStatus('🔒', sprintf('%s blocked - %s needs permissions (created %s)', $taskId, $agentName, $humanTask->short_id), 'yellow');
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
        $failedTasks = $this->taskService->failed($excludePids);
        if ($failedTasks->isNotEmpty()) {
            $failedLines = [];
            foreach ($failedTasks as $task) {
                $shortId = substr((string) $task->short_id, 2, 6);
                $failedLines[] = '🪫 '.$shortId;
            }

            $this->line('<fg=red>Failed: '.implode(' | ', $failedLines).' (fuel retry)</>');
        }

        // Show agent health status (unhealthy/degraded agents only)
        $healthLines = $this->getHealthStatusLines();
        foreach ($healthLines as $healthLine) {
            $this->line($healthLine);
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
     * @return Collection<int, Task>
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
     * @param  array<string, mixed>  $task
     */
    private function formatTaskForPrompt(Task $task): string
    {
        $lines = [
            'Task: '.$task->short_id,
            'Title: '.$task->title,
            'Status: '.$task->status,
        ];

        // Include epic information if task is part of an epic
        if (! empty($task->epic_id)) {
            $epic = $task->epic;
            if ($epic instanceof Epic) {
                $lines[] = '';
                $lines[] = '== EPIC CONTEXT ==';
                $lines[] = 'This task is part of a larger epic:';
                $lines[] = 'Epic: '.$epic->short_id;
                $lines[] = 'Epic Title: '.$epic->title;
                if (! empty($epic->description)) {
                    $lines[] = 'Epic Description: '.$epic->description;
                }

                $lines[] = '';
                $lines[] = 'You are working on a small part of this larger epic. Understanding the epic context will help you build better solutions that align with the overall goal.';
                $lines[] = '';
            }
        }

        if (! empty($task->description)) {
            $lines[] = 'Description: '.$task->description;
        }

        if (! empty($task->type)) {
            $lines[] = 'Type: '.$task->type;
        }

        if (! empty($task->priority)) {
            $lines[] = 'Priority: P'.$task->priority;
        }

        if (! empty($task->labels)) {
            $lines[] = 'Labels: '.implode(', ', $task->labels);
        }

        if (! empty($task->blocked_by)) {
            $lines[] = 'Blocked by: '.implode(', ', $task->blocked_by);
        }

        // Include previous review issues if present
        if (! empty($task->last_review_issues)) {
            $lines[] = '';
            $lines[] = '⚠️ PREVIOUS ATTEMPT FAILED REVIEW';
            $lines[] = 'You ALREADY completed this task, but a reviewer found issues:';
            foreach ($task->last_review_issues as $issue) {
                $lines[] = '  - '.$issue;
            }

            $lines[] = '';
            $lines[] = 'DO NOT redo the entire task from scratch.';
            $lines[] = 'ONLY fix the specific issues listed above, then run the closing protocol again.';
        }

        return implode("\n", $lines);
    }

    /**
     * Display agent health status and exit.
     */
    private function displayHealthStatus(): int
    {
        if (! $this->healthTracker instanceof AgentHealthTrackerInterface) {
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
            $maxRetries = $this->configService->getAgentMaxRetries($agentName);
            $isDead = $this->healthTracker->isDead($agentName, $maxRetries);

            // Dead agents get special status
            if ($isDead) {
                $color = 'red';
                $statusIcon = '💀';
                $status = 'dead';
            } else {
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
            }

            $line = sprintf('<fg=%s>%s %s</>', $color, $statusIcon, $agentName);

            // Add dead/consecutive failures info
            if ($isDead) {
                $line .= sprintf(' <fg=red>(DEAD - %d consecutive failures, max: %d)</>', $health->consecutiveFailures, $maxRetries);
            } elseif ($health->consecutiveFailures > 0) {
                $line .= sprintf(' <fg=gray>(%d consecutive failure%s)</>', $health->consecutiveFailures, $health->consecutiveFailures === 1 ? '' : 's');
            }

            // Add backoff info if in backoff
            $backoffSeconds = $health->getBackoffSeconds();
            if ($backoffSeconds > 0) {
                $formatted = $this->backoffStrategy->formatBackoffTime($backoffSeconds);
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
     * Check agent health status changes and add warnings/recovery messages.
     *
     * @param  array<string>  $statusLines
     */
    private function checkAgentHealthChanges(array &$statusLines): void
    {
        if (! $this->healthTracker instanceof AgentHealthTrackerInterface) {
            return;
        }

        $agentNames = $this->configService->getAgentNames();

        foreach ($agentNames as $agentName) {
            $health = $this->healthTracker->getHealthStatus($agentName);
            $status = $health->getStatus();
            $backoffSeconds = $health->getBackoffSeconds();
            $inBackoff = $backoffSeconds > 0;
            $maxRetries = $this->configService->getAgentMaxRetries($agentName);
            $isDead = $this->healthTracker->isDead($agentName, $maxRetries);

            // Get previous state (default to healthy if not tracked)
            $previous = $this->previousHealthStates[$agentName] ?? [
                'status' => 'healthy',
                'in_backoff' => false,
                'is_dead' => false,
            ];

            // Check for state changes
            $statusChanged = $previous['status'] !== $status;
            $backoffChanged = $previous['in_backoff'] !== $inBackoff;
            $deadChanged = $previous['is_dead'] !== $isDead;

            // Agent entered backoff
            if ($backoffChanged && $inBackoff && ! $previous['in_backoff']) {
                $formatted = $this->backoffStrategy->formatBackoffTime($backoffSeconds);
                $statusLines[] = $this->formatStatus('⏳', sprintf('%s entered backoff (%s remaining)', $agentName, $formatted), 'yellow');
            }

            // Agent exited backoff (recovered)
            if ($backoffChanged && ! $inBackoff && $previous['in_backoff']) {
                $statusLines[] = $this->formatStatus('✓', sprintf('%s recovered from backoff', $agentName), 'green');
            }

            // Agent became dead
            if ($deadChanged && $isDead && ! $previous['is_dead']) {
                $statusLines[] = $this->formatStatus('💀', sprintf('%s is dead (%d consecutive failures >= %d)', $agentName, $health->consecutiveFailures, $maxRetries), 'red');
            }

            // Agent recovered from dead state
            if ($deadChanged && ! $isDead && $previous['is_dead']) {
                $statusLines[] = $this->formatStatus('✓', sprintf('%s recovered from dead state', $agentName), 'green');
            }

            // Agent became unhealthy (but not dead)
            if ($statusChanged && $status === 'unhealthy' && $previous['status'] !== 'unhealthy' && ! $isDead) {
                $statusLines[] = $this->formatStatus('⚠', sprintf('%s is unhealthy (%d consecutive failures)', $agentName, $health->consecutiveFailures), 'red');
            }

            // Agent recovered from unhealthy to healthy/warning/degraded
            if ($statusChanged && $previous['status'] === 'unhealthy' && $status !== 'unhealthy' && ! $isDead) {
                $statusLines[] = $this->formatStatus('✓', sprintf('%s recovered to %s status', $agentName, $status), 'green');
            }

            // Update previous state
            $this->previousHealthStates[$agentName] = [
                'status' => $status,
                'in_backoff' => $inBackoff,
                'is_dead' => $isDead,
            ];
        }
    }

    /**
     * Get health status summary for display in consume output.
     * Returns array of formatted health status lines.
     *
     * @return array<string>
     */
    private function getHealthStatusLines(): array
    {
        if (! $this->healthTracker instanceof AgentHealthTrackerInterface) {
            return [];
        }

        $agentNames = $this->configService->getAgentNames();
        $unhealthyAgents = [];

        foreach ($agentNames as $agentName) {
            $health = $this->healthTracker->getHealthStatus($agentName);
            $maxRetries = $this->configService->getAgentMaxRetries($agentName);
            $isDead = $this->healthTracker->isDead($agentName, $maxRetries);
            $status = $health->getStatus();

            // Show dead agents first (red)
            if ($isDead) {
                $unhealthyAgents[] = $this->formatStatus(
                    '💀',
                    sprintf(
                        'Agent %s is DEAD (%d consecutive failures, max: %d)',
                        $agentName,
                        $health->consecutiveFailures,
                        $maxRetries
                    ),
                    'red'
                );
            } else {
                $backoffSeconds = $health->getBackoffSeconds();
                $inBackoff = $backoffSeconds > 0;

                // Show agents in backoff (yellow)
                if ($inBackoff) {
                    $formatted = $this->backoffStrategy->formatBackoffTime($backoffSeconds);
                    $unhealthyAgents[] = $this->formatStatus(
                        '⏳',
                        sprintf(
                            'Agent %s in backoff (%s remaining, %d consecutive failures)',
                            $agentName,
                            $formatted,
                            $health->consecutiveFailures
                        ),
                        'yellow'
                    );
                } elseif ($status === 'unhealthy' || $status === 'degraded') {
                    // Show unhealthy/degraded agents (red/yellow)
                    $color = $status === 'unhealthy' ? 'red' : 'yellow';
                    $icon = $status === 'unhealthy' ? '✗' : '⚠';

                    $unhealthyAgents[] = $this->formatStatus(
                        $icon,
                        sprintf(
                            'Agent %s is %s (%d consecutive failures)',
                            $agentName,
                            $status,
                            $health->consecutiveFailures
                        ),
                        $color
                    );
                }

                // Healthy agents are not shown to avoid clutter
            }
        }

        return $unhealthyAgents;
    }
}
