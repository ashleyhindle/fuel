<?php

declare(strict_types=1);

namespace App\Commands;

use App\Agents\Tasks\WorkAgentTask;
use App\Commands\Concerns\HandlesJsonOutput;
use App\Commands\Concerns\RendersBoardColumns;
use App\Contracts\AgentHealthTrackerInterface;
use App\Contracts\ReviewServiceInterface;
use App\Enums\EpicStatus;
use App\Enums\FailureType;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Process\CompletionResult;
use App\Process\CompletionType;
use App\Process\ProcessType;
use App\Process\ReviewResult;
use App\Services\BackoffStrategy;
use App\Services\ConfigService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\NotificationService;
use App\Services\ProcessManager;
use App\Services\RunService;
use App\Services\TaskPromptBuilder;
use App\Services\TaskService;
use App\TUI\ScreenBuffer;
use App\TUI\Toast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\NullOutput;

class ConsumeCommand extends Command
{
    use HandlesJsonOutput;
    use RendersBoardColumns;

    protected $signature = 'consume
        {--cwd= : Working directory (defaults to current directory)}
        {--interval=5 : Check interval in seconds when idle}
        {--agent= : Agent name to use (overrides config-based routing)}
        {--prompt=Consume one task from fuel, then land the plane : Prompt to send to agent}
        {--dryrun : Show what would happen without claiming tasks or spawning agents}
        {--health : Show agent health status and exit}
        {--review : Enable automatic review of completed work}
        {--once : Show kanban board once and exit (no spawning)}
        {--debug : Enable debug logging to .fuel/debug.log}';

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

    /** @var array<string, string> Track original task status before review (to handle already-done tasks) */
    private array $preReviewTaskStatus = [];

    /** Current terminal width */
    private int $terminalWidth = 120;

    /** Current terminal height */
    private int $terminalHeight = 40;

    /** Whether blocked modal is visible */
    private bool $showBlockedModal = false;

    /** Whether done modal is visible */
    private bool $showDoneModal = false;

    /** Flag to force refresh on next loop (e.g., after SIGWINCH) */
    private bool $forceRefresh = false;

    /** Scroll offset for blocked modal */
    private int $blockedModalScroll = 0;

    /** Scroll offset for done modal */
    private int $doneModalScroll = 0;

    /** Spinner frame counter for activity indicator */
    private int $spinnerFrame = 0;

    /** @var array<int, string> Previous line content for differential rendering */
    private array $previousLines = [];

    /** Spinner characters for activity animation */
    private const SPINNER_CHARS = ['⠇', '⠏', '⠛', '⠹', '⠸', '⠼', '⠴', '⠦'];

    /** Input buffer for batched reading */
    private string $inputBuffer = '';

    /** @var array<string, bool> Track epics we've already played completion sound for */
    private array $notifiedEpics = [];

    /** Screen buffer for differential rendering and future text selection */
    private ?ScreenBuffer $screenBuffer = null;

    /** Previous screen buffer for comparison */
    private ?ScreenBuffer $previousBuffer = null;

    /** Current mouse cursor shape (for avoiding redundant OSC 22 sends) */
    private string $currentCursorShape = 'default';

    /** Selection start position [row, col] or null if not selecting */
    private ?array $selectionStart = null;

    /** Selection end position [row, col] or null if not selecting */
    private ?array $selectionEnd = null;

    /** Whether terminal window currently has focus */
    private bool $hasFocus = true;

    /** Debug mode enabled */
    private bool $debugMode = false;

    /** Debug log file handle */
    private mixed $debugFile = null;

    /** Toast notification manager */
    private ?Toast $toast = null;

    /** Last click timestamp for double-click detection (in microseconds) */
    private ?float $lastClickTime = null;

    /** Last click position [row, col] for double-click detection */
    private ?array $lastClickPos = null;

    /** Double-click threshold in milliseconds */
    private const DOUBLE_CLICK_THRESHOLD_MS = 500;

    public function __construct(
        private TaskService $taskService,
        private ConfigService $configService,
        private RunService $runService,
        private ProcessManager $processManager,
        private FuelContext $fuelContext,
        private BackoffStrategy $backoffStrategy,
        private TaskPromptBuilder $promptBuilder,
        private EpicService $epicService,
        private NotificationService $notificationService,
        private ?AgentHealthTrackerInterface $healthTracker = null,
        private ?ReviewServiceInterface $reviewService = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        Artisan::call('migrate', ['--force' => true], new NullOutput);

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

        // Handle --once flag: show board once and exit (no spawning)
        if ($this->option('once')) {
            $this->updateTerminalSize();
            $this->renderKanbanBoard();

            return self::SUCCESS;
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

            // Get initial terminal size
            $this->updateTerminalSize();

            // Initialize screen buffers for differential rendering
            $this->screenBuffer = new ScreenBuffer($this->terminalWidth, $this->terminalHeight);
            $this->previousBuffer = new ScreenBuffer($this->terminalWidth, $this->terminalHeight);

            // Initialize toast notifications
            $this->toast = new Toast;

            // Register SIGWINCH handler for terminal resize
            if (function_exists('pcntl_signal') && defined('SIGWINCH')) {
                pcntl_signal(SIGWINCH, function (): void {
                    $this->forceRefresh = true;
                    $this->updateTerminalSize();
                    // Resize screen buffers to match new terminal dimensions
                    $this->screenBuffer?->resize($this->terminalWidth, $this->terminalHeight);
                    $this->previousBuffer?->resize($this->terminalWidth, $this->terminalHeight);
                });
            }

            $this->getOutput()->write("\033[?1049h");
            $this->inAlternateScreen = true;
            $this->getOutput()->write("\033[?25l"); // Hide cursor
            $this->getOutput()->write("\033[?1003h"); // Enable mouse reporting (any-event mode)
            $this->getOutput()->write("\033[?1004h"); // Enable focus reporting
            $this->getOutput()->write("\033[H\033[2J");

            shell_exec('stty -icanon -echo');
            stream_set_blocking(STDIN, false);

            // Initialize debug logging if enabled
            if ($this->option('debug')) {
                $this->debugMode = true;
                $debugPath = $this->fuelContext->basePath.'/debug.log';
                $this->debugFile = fopen($debugPath, 'w');
                $this->debug('Debug logging started');
            }
        }

        // In non-interactive mode, start unpaused so we process tasks immediately
        $paused = ! $singleIteration;

        $statusLines = [];

        try {
            while (! $this->processManager->isShuttingDown()) {
                \pcntl_signal_dispatch();

                // Update terminal size on resize
                if ($this->forceRefresh) {
                    $this->updateTerminalSize();
                    $this->forceRefresh = false;
                }

                // Reload config on each iteration to pick up changes
                $this->configService->reload();

                // Check for keyboard input (pause toggle, modal toggles, quit)
                if ($this->handleKeyboardInput($paused, $statusLines)) {
                    break; // User pressed 'q' to exit
                }

                // Fast path during active selection or toast animation - skip heavy task management
                if ($this->selectionStart !== null || $this->toast?->isVisible()) {
                    $this->refreshDisplay($statusLines, $paused);
                    usleep(16000); // 60fps

                    continue;
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
                        // Check for keyboard input while waiting
                        if ($this->handleKeyboardInput($paused, $statusLines)) {
                            break 2; // User pressed 'q' to exit
                        }

                        $this->refreshDisplay($statusLines, $paused);
                        usleep($this->calculateSleepMicroseconds());
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

                // Sleep between poll cycles - dynamic based on focus/selection state
                usleep($this->calculateSleepMicroseconds());
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
            echo "\033[?1004l";   // Disable focus reporting
            echo "\033[?1003l";   // Disable mouse reporting
            echo "\033]22;default\033\\"; // Reset cursor shape to default
            echo "\033[?25h";     // Show cursor
            echo "\033[?1049l";   // Exit alternate screen
            echo "\033]0;\007";   // Reset terminal title
            $this->inAlternateScreen = false;
        }

        // Close debug log
        if ($this->debugFile !== null) {
            fclose($this->debugFile);
            $this->debugFile = null;
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

        // Get working directory
        $cwd = $this->fuelContext->getProjectPath();

        // Create WorkAgentTask abstraction
        $reviewEnabled = (bool) $this->option('review');
        $agentTask = new WorkAgentTask(
            $task,
            $this->taskService,
            $this->promptBuilder,
            $this->reviewService,
            $reviewEnabled
        );

        // Wire up epic completion callback
        $agentTask->setEpicCompletionCallback($this->checkEpicCompletionSound(...));

        // Determine agent name for capacity check and dryrun display
        $agentName = $agentOverride;
        if ($agentName === null) {
            try {
                $agentName = $agentTask->getAgentName($this->configService);
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
            $fullPrompt = $agentTask->buildPrompt($cwd);
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

        // Spawn via ProcessManager using WorkAgentTask abstraction
        $result = $this->processManager->spawnAgentTask($agentTask, $cwd, $runId);

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
                $this->updateLatestRunIfTaskExists($process->getTaskId(), [
                    'session_id' => $process->getSessionId(),
                ], $statusLines);
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

                // Check if task was already done before review
                $wasAlreadyDone = isset($this->preReviewTaskStatus[$taskId]);
                $originalStatus = $this->preReviewTaskStatus[$taskId] ?? null;
                unset($this->preReviewTaskStatus[$taskId]);

                if ($result->passed) {
                    // Review passed
                    if ($wasAlreadyDone) {
                        // Task was already done - confirm done (maybe update reason)
                        $task = $this->taskService->find($taskId);
                        if ($task && $task->status !== TaskStatus::Done) {
                            // Task status changed (shouldn't happen, but handle gracefully)
                            Artisan::call('done', [
                                'ids' => [$taskId],
                                '--reason' => 'Review passed (was already done)',
                            ]);
                        }

                        $this->checkEpicCompletionSound($taskId);
                        $statusLines[] = $this->formatStatus('✓', sprintf('Review passed for %s (was already done)', $taskId), 'green');
                    } else {
                        // Task was in_progress - mark as done
                        Artisan::call('done', [
                            'ids' => [$taskId],
                            '--reason' => 'Review passed',
                        ]);
                        $this->checkEpicCompletionSound($taskId);
                        $statusLines[] = $this->formatStatus('✓', sprintf('Review passed for %s', $taskId), 'green');
                    }
                } else {
                    // Review found issues - reopen task if it was already done
                    $issuesSummary = $result->issues === [] ? 'issues found' : implode(', ', $result->issues);

                    // Store the review issues on the task for the next agent run
                    if ($result->issues !== []) {
                        $this->taskService->setLastReviewIssues($taskId, $result->issues);
                    }

                    if ($wasAlreadyDone) {
                        // Task was already done but review failed - reopen with issues
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

        $this->updateLatestRunIfTaskExists($taskId, $runData, $statusLines);

        // Clear PID from task (if task still exists - it may have been deleted)
        $task = $this->taskService->find($taskId);
        if (! $task instanceof Task) {
            $statusLines[] = $this->formatStatus('⚠', sprintf('%s completed but task was deleted', $taskId), 'yellow');
            $this->invalidateTaskCache();

            return;
        }

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

        // Check task status to determine what happened
        // Note: WorkAgentTask.onSuccess() has already handled review triggering or task completion
        $task = $this->taskService->find($taskId);
        if (! $task instanceof Task) {
            // Task was deleted?
            $statusLines[] = $this->formatStatus('?', sprintf('%s completed but task not found (%s)', $taskId, $durationStr), 'yellow');

            return;
        }

        // Display appropriate status message based on what WorkAgentTask.onSuccess() did
        match ($task->status) {
            TaskStatus::InReview => $statusLines[] = $this->formatStatus('🔍', sprintf('%s completed, triggering review... (%s)', $taskId, $durationStr), 'cyan'),
            TaskStatus::Done => $statusLines[] = $this->formatStatus('✓', sprintf('%s completed (%s)', $taskId, $durationStr), 'green'),
            default => $statusLines[] = $this->formatStatus('✓', sprintf('%s completed (%s)', $taskId, $durationStr), 'green'),
        };
    }

    /**
     * Update the latest run for a task, skipping if the task no longer exists.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string>  $statusLines
     */
    private function updateLatestRunIfTaskExists(
        string $taskId,
        array $data,
        array &$statusLines
    ): void {
        if (! $this->taskService->find($taskId) instanceof Task) {
            $statusLines[] = $this->formatStatus('⚠', sprintf('Skipping run update for missing task %s', $taskId), 'yellow');

            return;
        }

        $this->runService->updateLatestRun($taskId, $data);
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
        $frameStart = microtime(true);

        // Update terminal size
        $this->updateTerminalSize();

        // Begin synchronized output (terminal buffers until end marker)
        $this->getOutput()->write("\033[?2026h");

        // During active selection, skip expensive board capture and just update highlight
        if ($this->selectionStart !== null && $this->previousLines !== []) {
            $this->debug('Selection active - skipping capture, just rendering highlight');
            $this->renderSelectionHighlight();
            $this->toast?->render($this->getOutput(), $this->terminalWidth, $this->terminalHeight);
            $this->getOutput()->write("\033[?2026l"); // End synchronized output

            return;
        }

        // Capture the new screen content by rendering to a buffer
        $captureStart = microtime(true);
        $newLines = $this->captureKanbanBoard($statusLines, $paused);
        $this->debug('captureKanbanBoard', $captureStart);

        // Differential rendering: only update changed lines
        $renderStart = microtime(true);
        $this->renderDiff($newLines);
        $this->debug('renderDiff', $renderStart);

        // Store new lines for next comparison
        $this->previousLines = $newLines;

        // Render toast notification on top of everything
        $this->toast?->render($this->getOutput(), $this->terminalWidth, $this->terminalHeight);

        // End synchronized output (terminal flushes buffer to screen at once)
        $this->getOutput()->write("\033[?2026l");

        $this->debug('refreshDisplay total', $frameStart);
    }

    /**
     * Capture the kanban board content to a screen buffer without outputting.
     * Returns an array of lines indexed by row number (1-indexed).
     *
     * @param  array<string>  $statusLines
     * @return array<int, string>
     */
    private function captureKanbanBoard(array $statusLines, bool $paused): array
    {
        // Initialize or resize buffer if needed
        if (! $this->screenBuffer instanceof ScreenBuffer ||
            $this->screenBuffer->getWidth() !== $this->terminalWidth ||
            $this->screenBuffer->getHeight() !== $this->terminalHeight) {
            $this->screenBuffer = new ScreenBuffer($this->terminalWidth, $this->terminalHeight);
        }

        $this->screenBuffer->clear();

        $boardData = $this->getBoardData();
        $readyTasks = $boardData['ready'];
        $inProgressTasks = $boardData['in_progress'];
        $reviewTasks = $boardData['review'];
        $blockedTasks = $boardData['blocked'];
        $humanTasks = $boardData['human'];
        $doneTasks = $boardData['done'];

        // Get active process metadata
        $activeProcesses = [];
        foreach ($this->processManager->getActiveProcesses() as $process) {
            $metadata = $process->getMetadata();
            $activeProcesses[$metadata['task_id']] = $metadata;
        }

        // Calculate column width (2 columns with 2 space gap)
        $columnWidth = (int) (($this->terminalWidth - 2) / 2);

        // Build Ready column with card metadata
        $readyData = $this->buildTaskColumnWithMeta('Ready', $readyTasks->take(10)->all(), $columnWidth, $readyTasks->count());
        $readyColumn = $readyData['lines'];
        $readyCards = $readyData['cards'];

        // Build In Progress column with card metadata
        $inProgressData = $this->buildInProgressColumnWithMeta(
            'In Progress',
            $inProgressTasks->take(10)->all(),
            $columnWidth,
            $inProgressTasks->count(),
            $activeProcesses
        );
        $inProgressColumn = $inProgressData['lines'];
        $inProgressCards = $inProgressData['cards'];

        // Pad columns to equal height (before registering regions)
        $topMaxHeight = max(count($readyColumn), count($inProgressColumn));
        $readyColumn = $this->padColumn($readyColumn, $topMaxHeight, $columnWidth);
        $inProgressColumn = $this->padColumn($inProgressColumn, $topMaxHeight, $columnWidth);

        // Build top row content
        $currentRow = 1;
        $topRows = array_map(null, $readyColumn, $inProgressColumn);
        foreach ($topRows as $row) {
            $this->screenBuffer->setLine($currentRow, implode('  ', $row));
            $currentRow++;
        }

        // Register Ready column card regions (left column, starts at col 1)
        foreach ($readyCards as $taskId => $cardMeta) {
            // +1 because screen rows are 1-indexed, lineStart is 0-indexed
            $startRow = $cardMeta['lineStart'] + 1;
            $endRow = $cardMeta['lineEnd'] + 1;
            $this->screenBuffer->registerRegion($taskId, $startRow, $endRow, 1, $columnWidth, 'task');
        }

        // Register In Progress column card regions (right column, starts after gap)
        $inProgressStartCol = $columnWidth + 3; // column width + 2 space gap + 1
        foreach ($inProgressCards as $taskId => $cardMeta) {
            $startRow = $cardMeta['lineStart'] + 1;
            $endRow = $cardMeta['lineEnd'] + 1;
            $this->screenBuffer->registerRegion($taskId, $startRow, $endRow, $inProgressStartCol, $inProgressStartCol + $columnWidth - 1, 'task');
        }

        // Add Review column if there are review tasks
        if ($reviewTasks->isNotEmpty()) {
            $currentRow++; // Empty line
            $reviewData = $this->buildTaskColumnWithMeta('Review', $reviewTasks->take(10)->all(), $this->terminalWidth, $reviewTasks->count(), 'review');
            $reviewStartRow = $currentRow;
            foreach ($reviewData['lines'] as $line) {
                $this->screenBuffer->setLine($currentRow, $line);
                $currentRow++;
            }

            // Register Review column card regions (full width)
            foreach ($reviewData['cards'] as $taskId => $cardMeta) {
                $startRow = $reviewStartRow + $cardMeta['lineStart'];
                $endRow = $reviewStartRow + $cardMeta['lineEnd'];
                $this->screenBuffer->registerRegion($taskId, $startRow, $endRow, 1, $this->terminalWidth, 'task');
            }
        }

        // Add needs-human line
        if ($humanTasks->isNotEmpty()) {
            $currentRow++; // Empty line
            $humanLine = $this->buildHumanLine($humanTasks->all());
            $this->screenBuffer->setLine($currentRow, $humanLine);
            $currentRow++;
        }

        // Add health status lines
        $healthLines = $this->getHealthStatusLines();
        if ($healthLines !== []) {
            $currentRow++; // Empty line
            foreach ($healthLines as $healthLine) {
                $this->screenBuffer->setLine($currentRow, $healthLine);
                $currentRow++;
            }
        }

        // Build footer
        $footerParts = [];
        $footerParts[] = '<fg=gray>Shift+Tab: '.($paused ? 'resume' : 'pause').'</>';
        $footerParts[] = '<fg=gray>b: blocked ('.$blockedTasks->count().')</>';
        $footerParts[] = '<fg=gray>d: done ('.$doneTasks->count().')</>';
        $footerParts[] = '<fg=gray>q: exit</>';
        $footerLine = implode(' <fg=#555>|</> ', $footerParts);

        // Render status history above footer (positioned from bottom)
        $footerHeight = 2; // status line + key instructions
        $statusLineCount = count($statusLines);
        if ($statusLineCount > 0) {
            $startRow = $this->terminalHeight - $statusLineCount - $footerHeight;
            foreach ($statusLines as $i => $line) {
                $this->screenBuffer->setLine($startRow + $i, $line);
            }
        }

        // Status line (centered, above footer)
        if ($paused) {
            $statusLine = '<fg=yellow>PAUSED</>';
        } else {
            $spinner = self::SPINNER_CHARS[$this->spinnerFrame % count(self::SPINNER_CHARS)];
            $this->spinnerFrame++;
            $statusLine = sprintf('<fg=green>%s Consuming</>', $spinner);
        }

        $statusPadding = max(0, (int) floor(($this->terminalWidth - $this->visibleLength($statusLine)) / 2));
        $this->screenBuffer->setLine($this->terminalHeight - 1, str_repeat(' ', $statusPadding).$statusLine);

        // Footer line (centered, at bottom)
        $paddingAmount = max(0, (int) floor(($this->terminalWidth - $this->visibleLength($footerLine)) / 2));
        $this->screenBuffer->setLine($this->terminalHeight, str_repeat(' ', $paddingAmount).$footerLine.str_repeat(' ', $paddingAmount));

        // Render modals on top if active
        if ($this->showBlockedModal) {
            $this->captureModal('Blocked Tasks', $blockedTasks->all(), 'blocked', $this->blockedModalScroll);
        } elseif ($this->showDoneModal) {
            $this->captureModal('Done Tasks', $doneTasks->all(), 'done', $this->doneModalScroll);
        }

        return $this->screenBuffer->getLines();
    }

    /**
     * Build the human needs line (without outputting).
     *
     * @param  array<int, Task>  $humanTasks
     */
    private function buildHumanLine(array $humanTasks): string
    {
        $prefix = '<fg=yellow>👤 Needs human:</> ';
        $prefixLength = $this->visibleLength($prefix);
        $availableWidth = $this->terminalWidth - $prefixLength;

        $items = [];
        $currentLength = 0;
        $separator = '<fg=gray> | </>';

        foreach ($humanTasks as $task) {
            $shortId = $task->short_id;
            $title = (string) $task->title;
            $displayId = substr((string) $shortId, 2, 6);

            $separatorLength = $items !== [] ? $this->visibleLength($separator) : 0;
            $idPart = sprintf('<fg=yellow>[%s]</> ', $displayId);
            $idPartLength = $this->visibleLength($idPart);
            $titleMaxLength = $availableWidth - $currentLength - $separatorLength - $idPartLength;

            if ($titleMaxLength < 5) {
                break;
            }

            $truncatedTitle = $this->truncate($title, $titleMaxLength);
            $item = $idPart.$truncatedTitle;
            $itemLength = $this->visibleLength($item);

            if ($currentLength + $separatorLength + $itemLength > $availableWidth) {
                break;
            }

            $items[] = $item;
            $currentLength += $separatorLength + $itemLength;
        }

        if ($items !== []) {
            return $prefix.implode($separator, $items);
        }

        return $prefix.'<fg=gray>None</>';
    }

    /**
     * Capture a modal to the screen buffer.
     *
     * @param  array<int, Task>  $tasks
     */
    private function captureModal(string $title, array $tasks, string $style, int $scrollOffset = 0): void
    {
        if (! $this->screenBuffer instanceof ScreenBuffer) {
            return;
        }

        // Modal dimensions (centered, 60% width, up to 80% height)
        $modalWidth = min((int) ($this->terminalWidth * 0.6), $this->terminalWidth - 8);
        $maxHeight = (int) ($this->terminalHeight * 0.8);
        $startCol = (int) (($this->terminalWidth - $modalWidth) / 2);
        $startRow = 3;

        // Calculate visible task slots (header=3 lines, footer=1 line)
        $visibleSlots = $maxHeight - 4;
        $totalTasks = count($tasks);

        // Clamp scroll offset to valid range
        $maxScroll = max(0, $totalTasks - $visibleSlots);
        $scrollOffset = max(0, min($scrollOffset, $maxScroll));

        // Update the caller's scroll position if it was clamped
        if ($style === 'done') {
            $this->doneModalScroll = $scrollOffset;
        } else {
            $this->blockedModalScroll = $scrollOffset;
        }

        // Build modal content
        $modalLines = [];
        $modalLines[] = '<fg=cyan>╭'.str_repeat('─', $modalWidth - 2).'╮</>';

        // Title with scroll indicator
        $scrollIndicator = $totalTasks > $visibleSlots ? sprintf(' (%d-%d of %d)', $scrollOffset + 1, min($scrollOffset + $visibleSlots, $totalTasks), $totalTasks) : '';
        $titleWithIndicator = $title.$scrollIndicator;
        $modalLines[] = '<fg=cyan>│</> <fg=white;options=bold>'.$this->truncate($titleWithIndicator, $modalWidth - 6).'</>'.str_repeat(' ', max(0, $modalWidth - $this->visibleLength($titleWithIndicator) - 3)).'<fg=cyan>│</>';
        $modalLines[] = '<fg=cyan>├'.str_repeat('─', $modalWidth - 2).'┤</>';

        if ($tasks === []) {
            $emptyMsg = 'No tasks';
            $modalLines[] = '<fg=cyan>│</> <fg=gray>'.$emptyMsg.'</>'.str_repeat(' ', max(0, $modalWidth - strlen($emptyMsg) - 3)).'<fg=cyan>│</>';
        } else {
            // Slice tasks based on scroll offset
            $visibleTasks = array_slice($tasks, $scrollOffset, $visibleSlots);

            foreach ($visibleTasks as $task) {
                $displayId = substr((string) $task->short_id, 2, 6);
                $titleTrunc = $this->truncate((string) $task->title, $modalWidth - 16);
                $complexityChar = $this->getComplexityChar($task);

                $idColor = $style === 'blocked' ? 'fg=#b36666' : 'fg=#888888';
                $content = sprintf('<%s>[%s ·%s]</> %s', $idColor, $displayId, $complexityChar, $titleTrunc);
                $contentLen = $this->visibleLength($content);
                $padding = max(0, $modalWidth - $contentLen - 3);
                $modalLines[] = '<fg=cyan>│</> '.$content.str_repeat(' ', $padding).'<fg=cyan>│</>';
            }
        }

        $modalLines[] = '<fg=cyan>╰'.str_repeat('─', $modalWidth - 2).'╯</>';

        // Write modal lines to screen buffer, positioning them centered
        foreach ($modalLines as $i => $line) {
            $row = $startRow + $i;
            if ($row <= $this->terminalHeight) {
                // Position modal centered horizontally
                $afterModalEnd = $startCol - 1 + $modalWidth;

                // Combine: spaces before + modal + spaces after
                $compositeLine = str_repeat(' ', $startCol - 1).$line.str_repeat(' ', max(0, $this->terminalWidth - $afterModalEnd));
                $this->screenBuffer->setLine($row, $compositeLine);
            }
        }
    }

    /**
     * Strip ANSI codes from a string.
     */
    private function stripAnsi(string $text): string
    {
        // Remove both Laravel's <fg=...>...</> tags and raw ANSI escape sequences
        $stripped = preg_replace('/<[^>]+>/', '', $text);
        $stripped = preg_replace('/\033\[[0-9;]*m/', '', $stripped ?? $text);

        return $stripped ?? $text;
    }

    /**
     * Render only the lines that have changed since the last frame.
     * Uses ANSI cursor positioning to jump to changed lines.
     *
     * @param  array<int, string>  $newLines  1-indexed array of screen lines
     */
    private function renderDiff(array $newLines): void
    {
        // If this is the first frame or terminal was resized, render everything
        $forceFullRender = $this->previousLines === [] || $this->forceRefresh;

        if ($forceFullRender) {
            // Clear screen and render all lines
            $this->getOutput()->write("\033[H\033[2J");
            foreach ($newLines as $row => $line) {
                // Position cursor at start of row and write content
                $this->getOutput()->write(sprintf("\033[%d;1H", $row));
                $this->outputFormattedLine($line);
            }

            // Render selection highlight overlay if active
            $this->renderSelectionHighlight();
            $this->forceRefresh = false;

            return;
        }

        // Differential render: only update changed lines
        foreach ($newLines as $row => $newLine) {
            $oldLine = $this->previousLines[$row] ?? '';

            // Compare the visible text (strip ANSI for comparison)
            $newPlain = $this->stripAnsi($newLine);
            $oldPlain = $this->stripAnsi($oldLine);

            if ($newPlain !== $oldPlain) {
                // Line changed - position cursor and render
                $this->getOutput()->write(sprintf("\033[%d;1H", $row));
                $this->outputFormattedLine($newLine);
            }
        }

        // Render selection highlight overlay if active
        $this->renderSelectionHighlight();
    }

    /**
     * Render the selection highlight overlay using inverse video.
     */
    private function renderSelectionHighlight(): void
    {
        if ($this->selectionStart === null || $this->selectionEnd === null || ! $this->screenBuffer instanceof ScreenBuffer) {
            return;
        }

        [$startRow, $startCol] = $this->selectionStart;
        [$endRow, $endCol] = $this->selectionEnd;

        // Normalize so start is before end
        if ($startRow > $endRow || ($startRow === $endRow && $startCol > $endCol)) {
            [$startRow, $startCol, $endRow, $endCol] = [$endRow, $endCol, $startRow, $startCol];
        }

        // Don't highlight if it's just a single position (click without drag)
        if ($startRow === $endRow && $startCol === $endCol) {
            return;
        }

        // Render inverted text for each row in the selection
        for ($row = $startRow; $row <= $endRow; $row++) {
            $line = $this->screenBuffer->getPlainLine($row);

            // Determine column range for this row
            if ($row === $startRow && $row === $endRow) {
                // Single-line selection
                $colStart = $startCol;
                $colEnd = $endCol;
            } elseif ($row === $startRow) {
                // First row of multi-line: from startCol to end of line
                $colStart = $startCol;
                $colEnd = $this->terminalWidth;
            } elseif ($row === $endRow) {
                // Last row of multi-line: from start to endCol
                $colStart = 1;
                $colEnd = $endCol;
            } else {
                // Middle rows: full line
                $colStart = 1;
                $colEnd = $this->terminalWidth;
            }

            // Extract the selected portion and output with inverse video
            $selectedText = mb_substr($line, $colStart - 1, $colEnd - $colStart + 1);

            // Position cursor and output inverted text
            // \033[7m = inverse video, \033[27m = normal video
            $this->getOutput()->write(sprintf(
                "\033[%d;%dH\033[7m%s\033[27m",
                $row,
                $colStart,
                $selectedText
            ));
        }
    }

    /**
     * Output a formatted line (with ANSI-style tags converted to actual ANSI codes).
     */
    private function outputFormattedLine(string $line): void
    {
        // Use the Symfony formatter to convert <fg=...> tags to ANSI codes
        $formatter = $this->getOutput()->getFormatter();
        $formatted = $formatter->format($line);

        // Ensure line is terminated properly (clear to end of line)
        $this->getOutput()->write($formatted."\033[K");
    }

    /**
     * Render the full kanban board with cards.
     *
     * @param  array<string>  $statusLines
     */
    private function renderKanbanBoard(array $statusLines = [], bool $paused = false): void
    {
        $boardData = $this->getBoardData();
        $readyTasks = $boardData['ready'];
        $inProgressTasks = $boardData['in_progress'];
        $reviewTasks = $boardData['review'];
        $blockedTasks = $boardData['blocked'];
        $humanTasks = $boardData['human'];
        $doneTasks = $boardData['done'];

        // Get active process metadata for status lines
        $activeProcesses = [];
        foreach ($this->processManager->getActiveProcesses() as $process) {
            $metadata = $process->getMetadata();
            $activeProcesses[$metadata['task_id']] = $metadata;
        }

        // Calculate column width (2 columns with 2 space gap)
        $columnWidth = (int) (($this->terminalWidth - 2) / 2);

        // Build Ready column
        $readyColumn = $this->buildTaskColumn('Ready', $readyTasks->take(10)->all(), $columnWidth, $readyTasks->count());

        // Build In Progress column with status lines
        $inProgressColumn = $this->buildInProgressColumn(
            'In Progress',
            $inProgressTasks->take(10)->all(),
            $columnWidth,
            $inProgressTasks->count(),
            $activeProcesses
        );

        // Pad columns to equal height
        $topMaxHeight = max(count($readyColumn), count($inProgressColumn));
        $readyColumn = $this->padColumn($readyColumn, $topMaxHeight, $columnWidth);
        $inProgressColumn = $this->padColumn($inProgressColumn, $topMaxHeight, $columnWidth);

        // Render top row
        $topRows = array_map(null, $readyColumn, $inProgressColumn);
        foreach ($topRows as $row) {
            $this->line(implode('  ', $row));
        }

        // Render Review column if there are review tasks
        if ($reviewTasks->isNotEmpty()) {
            $this->newLine();
            $reviewColumn = $this->buildTaskColumn('Review', $reviewTasks->take(10)->all(), $this->terminalWidth, $reviewTasks->count(), 'review');
            foreach ($reviewColumn as $line) {
                $this->line($line);
            }
        }

        // Render needs-human line
        if ($humanTasks->isNotEmpty()) {
            $this->newLine();
            $this->renderHumanLine($humanTasks->all());
        }

        // Render health status
        $healthLines = $this->getHealthStatusLines();
        if ($healthLines !== []) {
            $this->newLine();
            foreach ($healthLines as $healthLine) {
                $this->line($healthLine);
            }
        }

        // Build footer (key instructions only)
        $footerParts = [];
        $footerParts[] = '<fg=gray>Shift+Tab: '.($paused ? 'resume' : 'pause').'</>';
        $footerParts[] = '<fg=gray>b: blocked ('.$blockedTasks->count().')</>';
        $footerParts[] = '<fg=gray>d: done ('.$doneTasks->count().')</>';
        $footerParts[] = '<fg=gray>q: exit</>';
        $footerLine = implode(' <fg=#555>|</> ', $footerParts);

        // Footer always takes 2 lines: status line + key instructions
        $footerHeight = 2;

        // Render status history above footer (positioned from bottom)
        $statusLineCount = count($statusLines);
        if ($statusLineCount > 0) {
            $startRow = $this->terminalHeight - $statusLineCount - $footerHeight;
            foreach ($statusLines as $i => $line) {
                $this->getOutput()->write(sprintf("\033[%d;1H%s", $startRow + $i, $line));
            }
        }

        // Render status line above key instructions (centered)
        if ($paused) {
            $statusLine = '<fg=yellow>PAUSED</>';
        } else {
            $spinner = self::SPINNER_CHARS[$this->spinnerFrame % count(self::SPINNER_CHARS)];
            $this->spinnerFrame++;
            $statusLine = sprintf('<fg=green>%s Consuming</>', $spinner);
        }

        $statusPadding = max(0, (int) floor(($this->terminalWidth - $this->visibleLength($statusLine)) / 2));
        $this->getOutput()->write(sprintf("\033[%d;1H%s%s", $this->terminalHeight - 1, str_repeat(' ', $statusPadding), $statusLine));

        // Position footer at bottom of screen
        $paddingAmount = max(0, (int) floor(($this->terminalWidth - $this->visibleLength($footerLine)) / 2));
        $this->getOutput()->write(sprintf("\033[%d;1H%s%s%s", $this->terminalHeight, str_repeat(' ', $paddingAmount), $footerLine, str_repeat(' ', $paddingAmount)));

        // Render modals if active
        if ($this->showBlockedModal) {
            $this->renderModal('Blocked Tasks', $blockedTasks->all(), 'blocked', $this->blockedModalScroll);
        } elseif ($this->showDoneModal) {
            $this->renderModal('Done Tasks', $doneTasks->all(), 'done', $this->doneModalScroll);
        }
    }

    /**
     * Get all board data from a single snapshot.
     *
     * @return array{ready: Collection, in_progress: Collection, review: Collection, blocked: Collection, human: Collection, done: Collection}
     */
    private function getBoardData(): array
    {
        $allTasks = $this->taskService->all();
        $readyTasks = $this->taskService->readyFrom($allTasks);
        $readyIds = $readyTasks->pluck('short_id')->toArray();

        $inProgressTasks = $allTasks
            ->filter(fn (Task $t): bool => $t->status === TaskStatus::InProgress)
            ->sortByDesc('updated_at')
            ->values();

        $reviewTasks = $allTasks
            ->filter(fn (Task $t): bool => $t->status === TaskStatus::Review)
            ->sortByDesc('updated_at')
            ->values();

        $blockedTasks = $allTasks
            ->filter(fn (Task $t): bool => $t->status === TaskStatus::Open && ! in_array($t->short_id, $readyIds, true))
            ->filter(function (Task $t): bool {
                $labels = $t->labels ?? [];
                if (! is_array($labels)) {
                    return true;
                }

                return ! in_array('needs-human', $labels, true);
            })
            ->values();

        $humanTasks = $allTasks
            ->filter(fn (Task $t): bool => $t->status === TaskStatus::Open)
            ->filter(function (Task $t): bool {
                $labels = $t->labels ?? [];

                return is_array($labels) && in_array('needs-human', $labels, true);
            })
            ->values();

        $doneTasks = $allTasks
            ->filter(fn (Task $t): bool => $t->status === TaskStatus::Done)
            ->sortByDesc('updated_at')
            ->values();

        return [
            'ready' => $readyTasks,
            'in_progress' => $inProgressTasks,
            'review' => $reviewTasks,
            'blocked' => $blockedTasks,
            'human' => $humanTasks,
            'done' => $doneTasks,
        ];
    }

    /**
     * Build a task column for the kanban board.
     *
     * @param  array<int, Task>  $tasks
     * @return array{lines: array<int, string>, cards: array<string, array{lineStart: int, lineEnd: int}>}
     */
    private function buildTaskColumnWithMeta(string $title, array $tasks, int $width, int $totalCount, string $style = 'normal'): array
    {
        $lines = [];
        $cards = []; // Maps task ID to line range within this column

        $lines[] = $this->padLine(sprintf('<fg=white;options=bold>%s</> (%d)', $title, $totalCount), $width);
        $lines[] = str_repeat('─', $width);

        if ($tasks === []) {
            $lines[] = $this->padLine('<fg=gray>No tasks</>', $width);
        } else {
            foreach ($tasks as $task) {
                $lineStart = count($lines);
                $cardLines = $this->buildTaskCard($task, $width, $style);
                $lines = array_merge($lines, $cardLines);
                $lineEnd = count($lines) - 1;

                $cards[$task->short_id] = [
                    'lineStart' => $lineStart,
                    'lineEnd' => $lineEnd,
                ];
            }
        }

        return ['lines' => $lines, 'cards' => $cards];
    }

    /**
     * Build a task column for the kanban board (legacy, returns lines only).
     *
     * @param  array<int, Task>  $tasks
     * @return array<int, string>
     */
    private function buildTaskColumn(string $title, array $tasks, int $width, int $totalCount, string $style = 'normal'): array
    {
        return $this->buildTaskColumnWithMeta($title, $tasks, $width, $totalCount, $style)['lines'];
    }

    /**
     * Build In Progress column with agent status lines.
     *
     * @param  array<int, Task>  $tasks
     * @param  array<string, array>  $activeProcesses
     * @return array{lines: array<int, string>, cards: array<string, array{lineStart: int, lineEnd: int}>}
     */
    private function buildInProgressColumnWithMeta(string $title, array $tasks, int $width, int $totalCount, array $activeProcesses): array
    {
        $lines = [];
        $cards = [];

        $lines[] = $this->padLine(sprintf('<fg=white;options=bold>%s</> (%d)', $title, $totalCount), $width);
        $lines[] = str_repeat('─', $width);

        if ($tasks === []) {
            $lines[] = $this->padLine('<fg=gray>No tasks</>', $width);
        } else {
            foreach ($tasks as $task) {
                $taskId = $task->short_id;
                $processInfo = $activeProcesses[$taskId] ?? null;

                $lineStart = count($lines);
                $cardLines = $this->buildInProgressCard($task, $width, $processInfo);
                $lines = array_merge($lines, $cardLines);
                $lineEnd = count($lines) - 1;

                $cards[$taskId] = [
                    'lineStart' => $lineStart,
                    'lineEnd' => $lineEnd,
                ];
            }
        }

        return ['lines' => $lines, 'cards' => $cards];
    }

    /**
     * Build In Progress column (legacy, returns lines only).
     *
     * @param  array<int, Task>  $tasks
     * @param  array<string, array>  $activeProcesses
     * @return array<int, string>
     */
    private function buildInProgressColumn(string $title, array $tasks, int $width, int $totalCount, array $activeProcesses): array
    {
        return $this->buildInProgressColumnWithMeta($title, $tasks, $width, $totalCount, $activeProcesses)['lines'];
    }

    /**
     * Build a single task card.
     *
     * @return array<int, string>
     */
    private function buildTaskCard(Task $task, int $width, string $style = 'normal'): array
    {
        $lines = [];
        $shortId = (string) $task->short_id;
        $taskTitle = (string) $task->title;
        $complexityChar = $this->getComplexityChar($task);

        // Icons
        $consumeIcon = empty($task->consumed) ? '' : '⚡';
        $failedIcon = $this->taskService->isFailed($task) ? '🪫' : '';
        $autoClosedIcon = '';
        if ($style === 'done') {
            $labels = $task->labels ?? [];
            $autoClosedIcon = is_array($labels) && in_array('auto-closed', $labels, true) ? '🤖' : '';
        }

        $icons = array_filter([$consumeIcon, $failedIcon, $autoClosedIcon]);
        $iconString = $icons !== [] ? ' '.implode(' ', $icons) : '';
        $iconWidth = $icons !== [] ? count($icons) * 2 + 1 : 0;

        $truncatedTitle = $this->truncate($taskTitle, $width - 4 - $iconWidth);

        // Colors based on style
        $borderColor = match ($style) {
            'blocked' => 'fg=#b36666',
            'done' => 'fg=#888888',
            'review' => 'fg=yellow',
            default => 'fg=gray',
        };

        $idColor = match ($style) {
            'blocked' => 'fg=#b36666',
            'done' => 'fg=#888888',
            'review' => 'fg=yellow',
            default => 'fg=cyan',
        };

        $titleColor = match ($style) {
            'done' => '<fg=#888888>',
            'review' => '<fg=yellow>',
            default => '',
        };
        $titleEnd = ($style === 'done' || $style === 'review') ? '</>' : '';

        // Header: ╭─ f-abc123 ────────╮
        // Fixed chars: ╭─ (2) + space (1) + space (1) + ╮ (1) = 5, plus id length
        $headerIdPart = sprintf('<%s>%s</>', $idColor, $shortId);
        $headerIdLen = strlen($shortId);
        $headerDashesLen = max(1, $width - 5 - $headerIdLen);
        $headerLine = sprintf('<%s>╭─</> %s <%s>%s╮</>', $borderColor, $headerIdPart, $borderColor, str_repeat('─', $headerDashesLen));
        $lines[] = $this->padLine($headerLine, $width);

        // Content line: │ title {icons} │
        $contentLine = sprintf('<%s>│</> %s%s%s', $borderColor, $titleColor, $truncatedTitle, $titleEnd).$iconString;
        $lines[] = $this->padLineWithBorderColor($contentLine, $width, $borderColor);

        // Footer: ╰───────────── t ─╯ or ╰───────────── t · e-xxxxxx ─╯
        // Fixed chars without epic: ╰ (1) + space (1) + complexity (1) + space (1) + ─╯ (2) = 6
        // Fixed chars with epic: ╰ (1) + space (1) + complexity (1) + space (1) + · (1) + space (1) + epic (8) + space (1) + ─╯ (2) = 17
        $epicId = $task->epic?->short_id;
        $hasEpic = $epicId !== null && $width >= 18; // Minimum width to show epic ID

        if ($hasEpic) {
            $footerDashesLen = max(1, $width - 17);
            $footerLine = sprintf('<%s>╰%s %s · %s ─╯</>', $borderColor, str_repeat('─', $footerDashesLen), $complexityChar, $epicId);
        } else {
            $footerDashesLen = max(1, $width - 6);
            $footerLine = sprintf('<%s>╰%s %s ─╯</>', $borderColor, str_repeat('─', $footerDashesLen), $complexityChar);
        }

        $lines[] = $this->padLine($footerLine, $width);

        return $lines;
    }

    /**
     * Build an in-progress task card with status line.
     *
     * @param  array<string, mixed>|null  $processInfo
     * @return array<int, string>
     */
    private function buildInProgressCard(Task $task, int $width, ?array $processInfo): array
    {
        $lines = [];
        $shortId = (string) $task->short_id;
        $taskTitle = (string) $task->title;
        $complexityChar = $this->getComplexityChar($task);

        // Icons
        $consumeIcon = empty($task->consumed) ? '' : '⚡';
        $failedIcon = $this->taskService->isFailed($task) ? '🪫' : '';
        $icons = array_filter([$consumeIcon, $failedIcon]);
        $iconString = $icons !== [] ? ' '.implode(' ', $icons) : '';
        $iconWidth = $icons !== [] ? count($icons) * 2 + 1 : 0;

        $truncatedTitle = $this->truncate($taskTitle, $width - 4 - $iconWidth);

        $borderColor = 'fg=gray';
        $idColor = 'fg=cyan';

        // Header: ╭─ f-abc123 ────────╮
        // Fixed chars: ╭─ (2) + space (1) + space (1) + ╮ (1) = 5, plus id length
        $headerIdPart = sprintf('<%s>%s</>', $idColor, $shortId);
        $headerIdLen = strlen($shortId);
        $headerDashesLen = max(1, $width - 5 - $headerIdLen);
        $headerLine = sprintf('<%s>╭─</> %s <%s>%s╮</>', $borderColor, $headerIdPart, $borderColor, str_repeat('─', $headerDashesLen));
        $lines[] = $this->padLine($headerLine, $width);

        // Content line: │ title {icons} │
        $contentLine = sprintf('<%s>│</> %s', $borderColor, $truncatedTitle).$iconString;
        $lines[] = $this->padLineWithBorderColor($contentLine, $width, $borderColor);

        // Status line if we have process info
        if ($processInfo !== null) {
            $agentName = $processInfo['agent_name'] ?? 'unknown';
            $duration = $this->formatDuration($processInfo['duration'] ?? 0);

            // Calculate relative time since last output from agent
            $lastOutputTime = $processInfo['last_output_time'] ?? null;
            if ($lastOutputTime !== null) {
                $sinceOutput = time() - $lastOutputTime;
                $activityStr = $sinceOutput < 5 ? 'now' : $this->formatDuration($sinceOutput).' ago';
            } else {
                $activityStr = 'waiting...';
            }

            $statusLine = sprintf('<%s>│</> <fg=gray>%s · %s · last: %s</>', $borderColor, $agentName, $duration, $activityStr);
            $lines[] = $this->padLineWithBorderColor($statusLine, $width, $borderColor);
        }

        // Footer: ╰───────────── t ─╯ or ╰───────────── t · e-xxxxxx ─╯
        // Fixed chars without epic: ╰ (1) + space (1) + complexity (1) + space (1) + ─╯ (2) = 6
        // Fixed chars with epic: ╰ (1) + space (1) + complexity (1) + space (1) + · (1) + space (1) + epic (8) + space (1) + ─╯ (2) = 17
        $epicId = $task->epic?->short_id;
        $hasEpic = $epicId !== null && $width >= 18; // Minimum width to show epic ID

        if ($hasEpic) {
            $footerDashesLen = max(1, $width - 17);
            $footerLine = sprintf('<%s>╰%s %s · %s ─╯</>', $borderColor, str_repeat('─', $footerDashesLen), $complexityChar, $epicId);
        } else {
            $footerDashesLen = max(1, $width - 6);
            $footerLine = sprintf('<%s>╰%s %s ─╯</>', $borderColor, str_repeat('─', $footerDashesLen), $complexityChar);
        }

        $lines[] = $this->padLine($footerLine, $width);

        return $lines;
    }

    /**
     * Pad a line with border character at the end.
     */
    private function padLineWithBorder(string $line, int $width): string
    {
        return $this->padLineWithBorderColor($line, $width, 'fg=gray');
    }

    /**
     * Pad a line with colored border character at the end.
     */
    private function padLineWithBorderColor(string $line, int $width, string $borderColor): string
    {
        $visibleLen = $this->visibleLength($line);
        $padding = max(0, $width - $visibleLen - 1); // -1 for │ at end

        return $line.str_repeat(' ', $padding).sprintf('<%s>│</>', $borderColor);
    }

    /**
     * Render needs-human tasks line.
     *
     * @param  array<int, Task>  $humanTasks
     */
    private function renderHumanLine(array $humanTasks): void
    {
        $prefix = '<fg=yellow>👤 Needs human:</> ';
        $prefixLength = $this->visibleLength($prefix);
        $availableWidth = $this->terminalWidth - $prefixLength;

        $items = [];
        $currentLength = 0;
        $separator = '<fg=gray> | </>';

        foreach ($humanTasks as $task) {
            $shortId = $task->short_id;
            $title = (string) $task->title;
            $displayId = substr((string) $shortId, 2, 6);

            $separatorLength = $items !== [] ? $this->visibleLength($separator) : 0;
            $idPart = sprintf('<fg=yellow>[%s]</> ', $displayId);
            $idPartLength = $this->visibleLength($idPart);
            $titleMaxLength = $availableWidth - $currentLength - $separatorLength - $idPartLength;

            if ($titleMaxLength < 5) {
                break;
            }

            $truncatedTitle = $this->truncate($title, $titleMaxLength);
            $item = $idPart.$truncatedTitle;
            $itemLength = $this->visibleLength($item);

            if ($currentLength + $separatorLength + $itemLength > $availableWidth) {
                break;
            }

            $items[] = $item;
            $currentLength += $separatorLength + $itemLength;
        }

        if ($items !== []) {
            $this->line($prefix.implode($separator, $items));
        }
    }

    /**
     * Render a modal overlay with task list.
     *
     * @param  array<int, Task>  $tasks
     */
    private function renderModal(string $title, array $tasks, string $style, int $scrollOffset = 0): void
    {
        // Modal dimensions (centered, 60% width, up to 80% height)
        $modalWidth = min((int) ($this->terminalWidth * 0.6), $this->terminalWidth - 8);
        $maxHeight = (int) ($this->terminalHeight * 0.8);
        $startCol = (int) (($this->terminalWidth - $modalWidth) / 2);
        $startRow = 3;

        // Calculate visible task slots (header=3 lines, footer=1 line)
        $visibleSlots = $maxHeight - 4;
        $totalTasks = count($tasks);

        // Clamp scroll offset to valid range
        $maxScroll = max(0, $totalTasks - $visibleSlots);
        $scrollOffset = max(0, min($scrollOffset, $maxScroll));

        // Update the caller's scroll position if it was clamped
        if ($style === 'done') {
            $this->doneModalScroll = $scrollOffset;
        } else {
            $this->blockedModalScroll = $scrollOffset;
        }

        // Build modal content
        $modalLines = [];
        $modalLines[] = '<fg=cyan>╭'.str_repeat('─', $modalWidth - 2).'╮</>';

        // Title with scroll indicator
        $scrollIndicator = $totalTasks > $visibleSlots ? sprintf(' (%d-%d of %d)', $scrollOffset + 1, min($scrollOffset + $visibleSlots, $totalTasks), $totalTasks) : '';
        $titleWithIndicator = $title.$scrollIndicator;
        $modalLines[] = '<fg=cyan>│</> <fg=white;options=bold>'.$this->truncate($titleWithIndicator, $modalWidth - 6).'</>'.str_repeat(' ', max(0, $modalWidth - $this->visibleLength($titleWithIndicator) - 3)).'<fg=cyan>│</>';
        $modalLines[] = '<fg=cyan>├'.str_repeat('─', $modalWidth - 2).'┤</>';

        if ($tasks === []) {
            $emptyMsg = 'No tasks';
            $modalLines[] = '<fg=cyan>│</> <fg=gray>'.$emptyMsg.'</>'.str_repeat(' ', max(0, $modalWidth - strlen($emptyMsg) - 3)).'<fg=cyan>│</>';
        } else {
            // Slice tasks based on scroll offset
            $visibleTasks = array_slice($tasks, $scrollOffset, $visibleSlots);

            foreach ($visibleTasks as $task) {
                $displayId = substr((string) $task->short_id, 2, 6);
                $titleTrunc = $this->truncate((string) $task->title, $modalWidth - 16);
                $complexityChar = $this->getComplexityChar($task);

                $idColor = $style === 'blocked' ? 'fg=#b36666' : 'fg=#888888';
                $content = sprintf('<%s>[%s ·%s]</> %s', $idColor, $displayId, $complexityChar, $titleTrunc);
                $contentLen = $this->visibleLength($content);
                $padding = max(0, $modalWidth - $contentLen - 3);
                $modalLines[] = '<fg=cyan>│</> '.$content.str_repeat(' ', $padding).'<fg=cyan>│</>';
            }
        }

        $modalLines[] = '<fg=cyan>╰'.str_repeat('─', $modalWidth - 2).'╯</>';

        // Render modal using absolute positioning
        foreach ($modalLines as $i => $line) {
            $row = $startRow + $i;
            // Move cursor to position and draw line
            $this->getOutput()->write(sprintf("\033[%d;%dH%s", $row, $startCol, $line));
        }
    }

    /**
     * Get complexity character for a task.
     */
    private function getComplexityChar(Task $task): string
    {
        $complexity = $task->complexity ?? 'simple';

        return match ($complexity) {
            'trivial' => 't',
            'simple' => 's',
            'moderate' => 'm',
            'complex' => 'c',
            default => 's',
        };
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
     * Handle keyboard input for pause toggle and modal toggles.
     *
     * @param  bool  $paused  Reference to paused state
     * @param  array<string>  $statusLines  Reference to status lines
     * @return bool True if should exit, false to continue
     */
    private function handleKeyboardInput(bool &$paused, array &$statusLines): bool
    {
        // Batch read available input into buffer
        $read = [STDIN];
        $write = null;
        $except = null;

        if (stream_select($read, $write, $except, 0, 0) > 0) {
            $chunk = fread(STDIN, 256);
            if ($chunk !== false) {
                $this->inputBuffer .= $chunk;
            }
        }

        if ($this->inputBuffer === '') {
            return false;
        }

        // Process all complete sequences in the buffer
        while ($this->inputBuffer !== '') {
            $consumed = $this->processInputSequence($paused, $statusLines);
            if ($consumed === 0) {
                // Check if buffer is just a bare ESC with no more data waiting
                if ($this->inputBuffer === "\x1b") {
                    $read = [STDIN];
                    $write = null;
                    $except = null;
                    if (stream_select($read, $write, $except, 0, 0) === 0) {
                        // No more data - treat as standalone Escape
                        $this->handleBareEscape();
                        $this->inputBuffer = '';
                    }
                }

                break;
            }

            if ($consumed === -1) {
                return true; // Exit requested
            }
        }

        return false;
    }

    /**
     * Handle a bare Escape keypress (close modals).
     */
    private function handleBareEscape(): void
    {
        if ($this->showBlockedModal || $this->showDoneModal) {
            $this->showBlockedModal = false;
            $this->showDoneModal = false;
            $this->blockedModalScroll = 0;
            $this->doneModalScroll = 0;
            $this->forceRefresh = true;
        }
    }

    /**
     * Process a single input sequence from the buffer.
     *
     * @return int Bytes consumed (0 = incomplete, -1 = exit requested)
     */
    private function processInputSequence(bool &$paused, array &$statusLines): int
    {
        $buf = $this->inputBuffer;
        $len = strlen($buf);

        if ($len === 0) {
            return 0;
        }

        // Escape sequence
        if ($buf[0] === "\x1b") {
            if ($len < 2) {
                return 0; // Need more data
            }

            // CSI sequences (ESC [)
            if ($buf[1] === '[') {
                // Focus gained: ESC [ I (3 bytes)
                if ($len >= 3 && $buf[2] === 'I') {
                    $this->hasFocus = true;
                    $this->debug('Focus gained');
                    $this->inputBuffer = substr($buf, 3);

                    return 3;
                }

                // Focus lost: ESC [ O (3 bytes)
                if ($len >= 3 && $buf[2] === 'O') {
                    $this->hasFocus = false;
                    $this->debug('Focus lost');
                    // Cancel any active selection when losing focus
                    $this->selectionStart = null;
                    $this->selectionEnd = null;
                    $this->inputBuffer = substr($buf, 3);

                    return 3;
                }

                // Mouse event: ESC [ M <btn> <x> <y> (6 bytes total)
                if ($len >= 3 && $buf[2] === 'M') {
                    if ($len < 6) {
                        return 0; // Need more data for mouse event
                    }

                    $inputStart = microtime(true);

                    // Parse mouse event
                    $btn = ord($buf[3]) - 32;
                    $col = ord($buf[4]) - 32; // 1-indexed column
                    $row = ord($buf[5]) - 32; // 1-indexed row

                    // Decode button state
                    $isWheelUp = ($btn & 64) && ($btn & 3) === 0;
                    $isWheelDown = ($btn & 64) && ($btn & 3) === 1;
                    $isMotion = ($btn & 32) !== 0; // Motion flag
                    $buttonNum = $btn & 3; // 0=left, 1=middle, 2=right, 3=release
                    $isButtonDown = ! $isMotion && $buttonNum !== 3 && ! ($btn & 64);
                    $isButtonUp = ! $isMotion && $buttonNum === 3;
                    $isDrag = $isMotion && $buttonNum !== 3;

                    // Handle wheel scrolling
                    if ($isWheelUp || $isWheelDown) {
                        $scrollDelta = $isWheelUp ? -1 : 1;
                        if ($this->showDoneModal) {
                            $this->doneModalScroll = max(0, $this->doneModalScroll + $scrollDelta);
                            $this->forceRefresh = true;
                        } elseif ($this->showBlockedModal) {
                            $this->blockedModalScroll = max(0, $this->blockedModalScroll + $scrollDelta);
                            $this->forceRefresh = true;
                        }
                    }

                    // Update cursor shape based on content under mouse (before selection logic)
                    $this->updateCursorShape($row, $col);

                    // Handle text selection - only allow when cursor is over text (I-beam)
                    if ($isButtonDown && $buttonNum === 0) {
                        // Left mouse button down - check for double-click or start new selection
                        if ($this->currentCursorShape === 'text') {
                            $now = microtime(true);
                            $isDoubleClick = false;

                            // Check if this is a double-click
                            if ($this->lastClickTime !== null && $this->lastClickPos !== null) {
                                $timeDiff = ($now - $this->lastClickTime) * 1000; // Convert to ms
                                [$lastRow, $lastCol] = $this->lastClickPos;

                                // Double-click if within time threshold and same position (±1 for tolerance)
                                if ($timeDiff < self::DOUBLE_CLICK_THRESHOLD_MS &&
                                    abs($row - $lastRow) <= 1 &&
                                    abs($col - $lastCol) <= 1) {
                                    $isDoubleClick = true;
                                }
                            }

                            if ($isDoubleClick) {
                                // Expand selection to word boundaries
                                $this->expandSelectionToWord($row, $col);
                                $this->debug(sprintf('Double-click word select at row=%d, col=%d', $row, $col));

                                // Reset click tracking so triple-click doesn't trigger
                                $this->lastClickTime = null;
                                $this->lastClickPos = null;
                            } else {
                                // Single click - start normal selection
                                $this->selectionStart = [$row, $col];
                                $this->selectionEnd = [$row, $col];
                                $this->debug(sprintf('Selection started at row=%d, col=%d', $row, $col));

                                // Track this click for double-click detection
                                $this->lastClickTime = $now;
                                $this->lastClickPos = [$row, $col];
                            }
                        }
                    } elseif ($isDrag && $buttonNum === 0 && $this->selectionStart !== null) {
                        // Dragging with left button - update selection end
                        $this->selectionEnd = [$row, $col];
                        $this->debug(sprintf('Selection drag to row=%d, col=%d', $row, $col));

                        // Render highlight immediately for responsive feedback
                        $this->getOutput()->write("\033[?2026h"); // Begin sync
                        $this->renderSelectionHighlight();
                        $this->getOutput()->write("\033[?2026l"); // End sync
                    } elseif ($isButtonUp && $this->selectionStart !== null && $this->selectionEnd !== null) {
                        // Mouse up - copy selection to clipboard if we have a range
                        $this->copySelectionToClipboard();
                        $this->debug('Selection copied to clipboard');
                        $this->selectionStart = null;
                        $this->selectionEnd = null;
                        $this->forceRefresh = true;
                    }

                    $this->debug(sprintf('Mouse event processed btn=%d row=%d col=%d', $btn, $row, $col), $inputStart);
                    $this->inputBuffer = substr($buf, 6);

                    return 6;
                }

                // Shift+Tab: ESC [ Z (3 bytes)
                if ($len >= 3 && $buf[2] === 'Z') {
                    $paused = ! $paused;
                    $statusLines[] = $paused
                        ? $this->formatStatus('⏸', 'PAUSED - press Shift+Tab to resume', 'yellow')
                        : $this->formatStatus('▶', 'Resumed - looking for tasks...', 'green');
                    $statusLines = $this->trimStatusLines($statusLines);
                    $this->inputBuffer = substr($buf, 3);

                    return 3;
                }

                // Other CSI sequences - consume ESC [
                $this->inputBuffer = substr($buf, 2);

                return 2;
            }

            // ESC followed by non-[ character - treat as bare escape + that char
            $this->handleBareEscape();
            $this->inputBuffer = substr($buf, 1);

            return 1;
        }

        // Single character keys
        $char = $buf[0];
        $this->inputBuffer = substr($buf, 1);

        switch ($char) {
            case 'b':
            case 'B':
                $this->showBlockedModal = ! $this->showBlockedModal;
                if ($this->showBlockedModal) {
                    $this->showDoneModal = false;
                    $this->doneModalScroll = 0;
                    $this->blockedModalScroll = 0;
                } else {
                    $this->blockedModalScroll = 0;
                }

                $this->forceRefresh = true;

                return 1;

            case 'd':
            case 'D':
                $this->showDoneModal = ! $this->showDoneModal;
                if ($this->showDoneModal) {
                    $this->showBlockedModal = false;
                    $this->blockedModalScroll = 0;
                    $this->doneModalScroll = 0;
                } else {
                    $this->doneModalScroll = 0;
                }

                $this->forceRefresh = true;

                return 1;

            case 'q':
            case 'Q':
                return -1; // Exit requested
        }

        return 1; // Consume unknown single char
    }

    /**
     * Update terminal size from stty.
     */
    private function updateTerminalSize(): void
    {
        if (! function_exists('shell_exec')) {
            return;
        }

        $sttyOutput = @shell_exec('stty size 2>/dev/null');
        if ($sttyOutput !== null) {
            $parts = explode(' ', trim($sttyOutput));
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                $this->terminalHeight = (int) $parts[0];
                $this->terminalWidth = (int) $parts[1];
            }
        }
    }

    private function setTerminalTitle(string $title): void
    {
        $projectName = basename($this->fuelContext->getProjectPath());
        // OSC 0 sets both window title and icon name
        $this->getOutput()->write("\033]0;{$projectName} {$title}\007");
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

    /**
     * Check if a completed task's epic is now review pending and send notification.
     * Only notifies once per epic.
     */
    private function checkEpicCompletionSound(string $taskId): void
    {
        $task = $this->taskService->find($taskId);
        if (! $task instanceof Task || empty($task->epic_id)) {
            return;
        }

        $epicId = (string) $task->epic_id;

        // Already notified for this epic
        if (isset($this->notifiedEpics[$epicId])) {
            return;
        }

        // Check if epic is now review pending (all tasks done)
        try {
            $epic = $this->epicService->getEpic($epicId);
            $epicStatus = $this->epicService->getEpicStatus($epicId);
            if ($epicStatus === EpicStatus::ReviewPending) {
                // Mark as notified so we don't play again
                $this->notifiedEpics[$epicId] = true;

                // Send notification with sound and desktop alert
                $epicTitle = $epic?->title ?? $epicId;
                $this->notificationService->alert(
                    'Epic ready for review: '.$epicTitle,
                    'Fuel: Epic Complete'
                );
            }
        } catch (\RuntimeException) {
            // Epic not found, ignore
        }
    }

    /**
     * Update mouse cursor shape based on content under the cursor.
     * Uses OSC 22 to set pointer shape - "text" over text content, "default" elsewhere.
     */
    private function updateCursorShape(int $row, int $col): void
    {
        if (! $this->screenBuffer instanceof ScreenBuffer) {
            return;
        }

        // Check what's under the cursor
        $char = $this->screenBuffer->charAt($row, $col);

        // Determine desired cursor shape
        // Use "text" cursor if there's a non-whitespace character
        $hasText = $char !== '' && $char !== ' ' && trim($char) !== '';
        $desiredShape = $hasText ? 'text' : 'default';

        // Only send OSC 22 if shape changed (avoid redundant output)
        if ($desiredShape !== $this->currentCursorShape) {
            $this->currentCursorShape = $desiredShape;
            // OSC 22 ; <shape> ST - set pointer shape
            $this->getOutput()->write("\033]22;{$desiredShape}\033\\");
        }
    }

    /**
     * Copy the current selection to the system clipboard using OSC 52.
     */
    private function copySelectionToClipboard(): void
    {
        if (! $this->screenBuffer instanceof ScreenBuffer || $this->selectionStart === null || $this->selectionEnd === null) {
            return;
        }

        [$startRow, $startCol] = $this->selectionStart;
        [$endRow, $endCol] = $this->selectionEnd;

        // Don't copy if it's just a click (no actual selection)
        if ($startRow === $endRow && $startCol === $endCol) {
            return;
        }

        // Extract the selected text
        $text = $this->screenBuffer->extractSelection($startRow, $startCol, $endRow, $endCol);

        if ($text === '') {
            return;
        }

        // OSC 52 - manipulate selection data
        // Format: \033]52;c;<base64-data>\007
        // 'c' = clipboard selection
        $base64 = base64_encode($text);
        $this->getOutput()->write("\033]52;c;{$base64}\007");

        // Show toast notification
        $this->toast?->show('Copied to clipboard', 'success', '', 1000);
    }

    /**
     * Expand selection to word boundaries from a given position.
     *
     * Word characters are: a-zA-Z0-9_-@
     */
    private function expandSelectionToWord(int $row, int $col): void
    {
        if (! $this->screenBuffer instanceof ScreenBuffer) {
            return;
        }

        $line = $this->screenBuffer->getPlainLine($row);
        $lineLength = mb_strlen($line);

        // Adjust col to 0-indexed for string operations
        $pos = $col - 1;

        if ($pos < 0 || $pos >= $lineLength) {
            return;
        }

        // Check if the character at position is a word character
        $char = mb_substr($line, $pos, 1);
        if (! $this->isWordChar($char)) {
            // Clicked on non-word char, don't select anything
            return;
        }

        // Find left boundary (scan left until non-word char or start)
        $left = $pos;
        while ($left > 0) {
            $prevChar = mb_substr($line, $left - 1, 1);
            if (! $this->isWordChar($prevChar)) {
                break;
            }

            $left--;
        }

        // Find right boundary (scan right until non-word char or end)
        $right = $pos;
        while ($right < $lineLength - 1) {
            $nextChar = mb_substr($line, $right + 1, 1);
            if (! $this->isWordChar($nextChar)) {
                break;
            }

            $right++;
        }

        // Set selection (convert back to 1-indexed)
        $this->selectionStart = [$row, $left + 1];
        $this->selectionEnd = [$row, $right + 1];

        // Render highlight immediately
        $this->getOutput()->write("\033[?2026h"); // Begin sync
        $this->renderSelectionHighlight();
        $this->getOutput()->write("\033[?2026l"); // End sync

        // Brief pause so user can see what was selected
        usleep(150000); // 150ms

        // Copy to clipboard
        $this->copySelectionToClipboard();
    }

    /**
     * Check if a character is a word character for selection purposes.
     *
     * Word characters: a-zA-Z0-9_-@
     */
    private function isWordChar(string $char): bool
    {
        if ($char === '') {
            return false;
        }

        return preg_match('/^[a-zA-Z0-9_\-@]$/', $char) === 1;
    }

    /**
     * Check if a position is within the current selection.
     */
    private function isInSelection(int $row, int $col): bool
    {
        if ($this->selectionStart === null || $this->selectionEnd === null) {
            return false;
        }

        [$startRow, $startCol] = $this->selectionStart;
        [$endRow, $endCol] = $this->selectionEnd;

        // Normalize so start is before end
        if ($startRow > $endRow || ($startRow === $endRow && $startCol > $endCol)) {
            [$startRow, $startCol, $endRow, $endCol] = [$endRow, $endCol, $startRow, $startCol];
        }

        if ($row < $startRow || $row > $endRow) {
            return false;
        }

        if ($row === $startRow && $row === $endRow) {
            return $col >= $startCol && $col <= $endCol;
        }

        if ($row === $startRow) {
            return $col >= $startCol;
        }

        if ($row === $endRow) {
            return $col <= $endCol;
        }

        return true; // Middle rows are fully selected
    }

    /**
     * Write a debug message to the log file with timestamp and optional timing.
     */
    private function debug(string $message, ?float $startTime = null): void
    {
        if (! $this->debugMode || $this->debugFile === null) {
            return;
        }

        $timestamp = date('H:i:s.').sprintf('%03d', (int) ((microtime(true) - floor(microtime(true))) * 1000));

        if ($startTime !== null) {
            $elapsed = (microtime(true) - $startTime) * 1000;
            $message .= sprintf(' [%.2fms]', $elapsed);
        }

        fwrite($this->debugFile, sprintf('[%s] %s%s', $timestamp, $message, PHP_EOL));
        fflush($this->debugFile);
    }

    /**
     * Calculate the appropriate sleep duration based on current state.
     */
    private function calculateSleepMicroseconds(): int
    {
        // During active selection: 60fps for smooth highlighting
        if ($this->selectionStart !== null) {
            return 16000; // ~60fps
        }

        // When unfocused: slow down significantly to save CPU
        if (! $this->hasFocus) {
            return 500000; // 500ms - 2fps when not focused
        }

        // Normal operation: 10fps
        return 100000; // 100ms
    }
}
