<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Commands\Concerns\RendersBoardColumns;
use App\Contracts\AgentHealthTrackerInterface;
use App\Contracts\ReviewServiceInterface;
use App\Enums\EpicStatus;
use App\Enums\TaskStatus;
use App\Ipc\Events\HealthChangeEvent;
use App\Ipc\Events\ReviewCompletedEvent;
use App\Ipc\Events\StatusLineEvent;
use App\Ipc\Events\TaskCompletedEvent;
use App\Ipc\Events\TaskSpawnedEvent;
use App\Ipc\IpcMessage;
use App\Models\Task;
use App\Services\BackoffStrategy;
use App\Services\ConfigService;
use App\Services\ConsumeIpcClient;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\NotificationService;
use App\Services\ProcessManager;
use App\Services\RunService;
use App\Services\TaskPromptBuilder;
use App\Services\TaskService;
use App\TUI\GradientText;
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
        {--health : Show agent health status and exit}
        {--review : Enable automatic review of completed work}
        {--once : Show kanban board once and exit (no spawning)}
        {--debug : Enable debug logging to .fuel/debug.log}
        {--status : Connect to runner, request snapshot, print summary, exit}
        {--pause : Send pause command to runner and exit}
        {--resume : Send resume command to runner and exit}
        {--unpause : Send resume command to runner and exit (alias for --resume)}
        {--stop : Send graceful stop command to runner and exit}
        {--force : Send force stop command to runner and exit}
        {--start : Start the runner daemon in the background and exit}
        {--restart : Restart the runner daemon (stop and start)}
        {--fresh : Kill existing runner and start fresh}
        {--ip=127.0.0.1 : IP address of the runner to connect to}
        {--port= : Port number to connect to (overrides config)}';

    protected $description = 'Auto-spawn agents to work through available tasks';

    /** IPC client for runner communication (null in standalone mode) */
    private ?ConsumeIpcClient $ipcClient = null;

    /** Original terminal state for restoration */
    private ?string $originalTty = null;

    /** Whether we've entered alternate screen mode */
    private bool $inAlternateScreen = false;

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

    /** Previous connection state for detecting changes */
    private ?bool $lastConnectionState = null;

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

    /** Whether command palette is active */
    private bool $commandPaletteActive = false;

    /** Current command palette input text (without leading /) */
    private string $commandPaletteInput = '';

    /** Cursor position within command palette input (0-indexed) */
    private int $commandPaletteCursor = 0;

    /** Currently selected suggestion index (-1 = none) */
    private int $commandPaletteSuggestionIndex = -1;

    /** Cached suggestion list */
    private array $commandPaletteSuggestions = [];

    /** Double-click threshold in milliseconds */
    private const DOUBLE_CLICK_THRESHOLD_MS = 500;

    /** Connecting animation spinner frames */
    private const CONNECTING_SPINNER = ['▪', '▪', '▫', '▫', '■', '■', '□', '□'];

    /** Gradient text animator for connecting screen */
    private ?GradientText $connectingGradient = null;

    /** Current status text for connecting animation */
    private string $connectingStatus = '';

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

        // Handle IPC control flags (--status, --pause, --resume, --stop, --force)
        if ($this->hasIpcControlFlag()) {
            return $this->handleIpcControl();
        }

        // Handle --start flag: start runner daemon in background and exit
        if ($this->option('start')) {
            return $this->handleStartDaemon();
        }

        // Handle --once flag: show board once and exit (no spawning)
        if ($this->option('once')) {
            $this->updateTerminalSize();
            $this->renderKanbanBoard();

            return self::SUCCESS;
        }

        // Detect non-interactive mode (tests, CI, etc.) - run only one iteration
        $singleIteration = (function_exists('posix_isatty') && ! posix_isatty(STDOUT)) ||
                          (method_exists(app(), 'runningUnitTests') && app()->runningUnitTests()) ||
                          app()->environment('testing');

        // Enter alternate screen immediately for the connecting animation (interactive mode only)
        if (! $singleIteration) {
            $this->enterAlternateScreen();

            // Initialize debug logging early so we can trace the startup sequence
            if ($this->option('debug')) {
                $this->debugMode = true;
                $debugPath = $this->fuelContext->basePath.'/debug.log';
                $this->debugFile = fopen($debugPath, 'a');
                $this->debug('Debug logging started - entering alternate screen');
            }
        }

        // Ensure processes directory exists for output capture
        $processesDir = $this->fuelContext->getProcessesPath();
        if (! is_dir($processesDir)) {
            mkdir($processesDir, 0755, true);
        }

        // Clean up orphaned runs from previous consume crashes (with animation)
        if (! $singleIteration) {
            $this->renderConnectingFrame(0, 'Cleaning up');
        }

        $this->runService->cleanupOrphanedRuns();

        // Recover stuck reviews (tasks in 'review' status with no active review process)
        if ($this->reviewService instanceof ReviewServiceInterface) {
            $recoveredReviews = $this->reviewService->recoverStuckReviews();
            foreach ($recoveredReviews as $taskId) {
                // In interactive mode, we don't show individual recovery messages during animation
                if ($singleIteration) {
                    $this->info(sprintf('Recovered stuck review for task %s', $taskId));
                }
            }
        }

        max(1, (int) $this->option('interval'));
        $this->option('agent');

        // Register ProcessManager signal handlers first
        try {
            $this->processManager->registerSignalHandlers();
        } catch (\RuntimeException $runtimeException) {
            $this->restoreTerminal();
            $this->error('Error: '.$runtimeException->getMessage());

            return self::FAILURE;
        }

        // Handle --fresh: kill existing runner before starting (with animation)
        if ($this->option('fresh')) {
            if (! $singleIteration) {
                $this->renderConnectingFrame(1, 'Stopping runner');
            }

            $pidFilePath = $this->fuelContext->getPidFilePath();

            if (file_exists($pidFilePath)) {
                // Use shared lock for reading PID file
                $lockFile = $pidFilePath.'.lock';
                $lock = @fopen($lockFile, 'c');

                if ($lock !== false) {
                    flock($lock, LOCK_SH);
                    $content = file_get_contents($pidFilePath);
                    flock($lock, LOCK_UN);
                    fclose($lock);
                    $pidData = json_decode($content, true);
                } else {
                    // Fallback to direct read
                    $pidData = json_decode(@file_get_contents($pidFilePath), true);
                }

                $pid = $pidData['pid'] ?? null;

                if ($pid && ProcessManager::isProcessAlive($pid)) {
                    posix_kill($pid, SIGTERM);
                    // Wait for process to actually die (up to 5 seconds)
                    $waited = 0;
                    while (ProcessManager::isProcessAlive($pid) && $waited < 50) {
                        usleep(100000); // 100ms
                        $waited++;
                    }
                    // Force kill if still alive
                    if (ProcessManager::isProcessAlive($pid)) {
                        posix_kill($pid, SIGKILL);
                        usleep(100000); // 100ms for SIGKILL to take effect
                    }
                }

                @unlink($pidFilePath);
            }

            if ($singleIteration) {
                $this->info('Killed existing runner, starting fresh...');
            }
        }

        // Initialize IPC client and connect to runner (with animation)
        try {
            $ip = $this->option('ip');
            $this->ipcClient = new ConsumeIpcClient($ip);
            $pidFilePath = $this->fuelContext->getPidFilePath();
            $port = $this->option('port') ? (int) $this->option('port') : $this->configService->getConsumePort();
            $isRemote = $ip !== '127.0.0.1';

            // Only start local runner if not connecting to a remote runner
            if (! $isRemote && ! $this->ipcClient->isRunnerAlive($pidFilePath)) {
                // Start runner with animation
                if (! $singleIteration) {
                    $frame = 0;
                    $this->renderConnectingFrame($frame++, 'Starting runner');
                }

                $this->ipcClient->startRunner($this->fuelContext->getFuelBinaryPath(), $port);

                // Wait for server with animation
                if (! $singleIteration) {
                    $connected = $this->showConnectingAnimation(
                        fn (): bool => $this->ipcClient->isServerReady($port),
                        'Starting runner',
                        10000
                    );
                    if (! $connected) {
                        throw new \RuntimeException('Timed out waiting for runner to start');
                    }
                } else {
                    $this->ipcClient->waitForServer($port, 5);
                }
            }

            // Connect to runner
            if (! $singleIteration) {
                $this->debug('Connecting to runner on '.$ip.':'.$port);
                $connectStart = microtime(true);
            }

            $this->ipcClient->connect($port);
            if (! $singleIteration) {
                $this->debug('Socket connected', $connectStart);
            }

            // Attach to runner with animation (waits for Hello + Snapshot events)
            if (! $singleIteration) {
                $this->debug('Beginning attach to runner...');
                $attachStart = microtime(true);

                $this->ipcClient->beginAttach();

                // Animate while waiting for attach to complete
                $attached = $this->showConnectingAnimation(
                    function (): bool {
                        $this->ipcClient->pollAttachEvents();

                        return $this->ipcClient->isAttachComplete();
                    },
                    'Syncing',
                    10000
                );

                if (! $attached) {
                    throw new \RuntimeException('Timed out waiting for runner to respond');
                }

                $this->ipcClient->finalizeAttach();
                $this->debug('Attached to runner', $attachStart);

                // Sync task review setting with runner
                $this->ipcClient->setTaskReviewEnabled((bool) $this->option('review'));
            } else {
                // Non-interactive: use blocking attach
                $this->ipcClient->attach();

                // Sync task review setting with runner
                $this->ipcClient->setTaskReviewEnabled((bool) $this->option('review'));
            }
        } catch (\RuntimeException $runtimeException) {
            $this->restoreTerminal();
            $this->error('Failed to connect to runner: '.$runtimeException->getMessage());

            return self::FAILURE;
        }

        // Complete terminal setup for interactive mode
        if (! $singleIteration) {
            $setupStart = microtime(true);
            $this->debug('Starting terminal setup...');

            // Initialize screen buffers for differential rendering
            $this->screenBuffer = new ScreenBuffer($this->terminalWidth, $this->terminalHeight);
            $this->previousBuffer = new ScreenBuffer($this->terminalWidth, $this->terminalHeight);

            // Initialize toast notifications
            $this->toast = new Toast;

            // Register SIGWINCH handler for terminal resize
            pcntl_signal(SIGWINCH, function (): void {
                $this->forceRefresh = true;
                $this->updateTerminalSize();
                // Resize screen buffers to match new terminal dimensions
                $this->screenBuffer?->resize($this->terminalWidth, $this->terminalHeight);
                $this->previousBuffer?->resize($this->terminalWidth, $this->terminalHeight);
            });

            // Enable mouse and focus reporting (stty already set in enterAlternateScreen)
            $this->getOutput()->write("\033[?1003h"); // Enable mouse reporting (any-event mode)
            $this->getOutput()->write("\033[?1004h"); // Enable focus reporting
            $this->getOutput()->write("\033[H\033[2J"); // Clear screen for board

            $this->debug('Terminal setup complete', $setupStart);
            $this->debug('Entering main loop - first render coming...');
        }

        // In non-interactive mode, start unpaused so we process tasks immediately
        $paused = ! $singleIteration;

        $statusLines = [];

        try {
            while (true) {
                \pcntl_signal_dispatch();

                // Update terminal size on resize
                if ($this->forceRefresh) {
                    $this->updateTerminalSize();
                    $this->forceRefresh = false;
                }

                // Poll IPC events from runner and update state
                // (pollEvents handles reconnection internally if disconnected)
                foreach ($this->ipcClient->pollEvents() as $event) {
                    $this->handleIpcEvent($event, $statusLines);
                }

                // Trim status lines to prevent unbounded growth from agent notifications
                $statusLines = $this->trimStatusLines($statusLines);

                // Check for connection state changes and force refresh if changed
                $currentConnectionState = $this->ipcClient->isConnected();
                if ($this->lastConnectionState !== null && $currentConnectionState !== $this->lastConnectionState) {
                    $this->forceRefresh = true;
                }
                $this->lastConnectionState = $currentConnectionState;

                // Sync pause state from runner
                $paused = $this->ipcClient->isPaused();

                // Check for keyboard input (pause toggle, modal toggles, quit)
                if ($this->handleKeyboardInput($paused, $statusLines)) {
                    // User pressed 'q' - detach and exit
                    $this->ipcClient->detach();
                    break;
                }

                // Fast path during active selection or toast animation
                if ($this->selectionStart !== null || $this->toast?->isVisible()) {
                    $this->refreshDisplay($statusLines, $paused);
                    usleep(16000); // 60fps

                    continue;
                }

                // Update display with current state
                $this->refreshDisplay($statusLines, $paused);

                // Update terminal title with active process count
                $activeCount = count($this->ipcClient->getActiveProcesses());
                if ($paused) {
                    $this->setTerminalTitle('fuel: PAUSED');
                } elseif ($activeCount > 0) {
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
            $this->ipcClient?->disconnect();
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
     * Enter alternate screen mode early for the connecting animation.
     */
    private function enterAlternateScreen(): void
    {
        $this->originalTty = shell_exec('stty -g');
        register_shutdown_function([$this, 'restoreTerminal']);

        // Disable echo BEFORE entering alt screen to prevent escape sequences from showing
        shell_exec('stty -icanon -echo');
        stream_set_blocking(STDIN, false);

        $this->updateTerminalSize();

        $this->getOutput()->write("\033[?1049h"); // Enter alternate screen
        $this->inAlternateScreen = true;
        $this->getOutput()->write("\033[?25l"); // Hide cursor
        $this->getOutput()->write("\033[H\033[2J"); // Clear screen
    }

    /**
     * Render a single frame of the connecting animation.
     */
    private function renderConnectingFrame(int $frame, string $status = 'Connecting'): void
    {
        $this->updateTerminalSize();

        // Clear entire screen to prevent residual text from showing
        echo "\033[H\033[2J";

        // Create or reset gradient if status changed
        if (! $this->connectingGradient instanceof GradientText || $this->connectingStatus !== $status) {
            $this->connectingGradient = GradientText::cyan($status);
            $this->connectingStatus = $status;
        }

        $spinner = self::CONNECTING_SPINNER[$frame % count(self::CONNECTING_SPINNER)];

        // Get gradient-rendered status text
        $gradientStatus = $this->connectingGradient->render();
        $statusLength = $this->connectingGradient->length();

        // Calculate center position
        $centerRow = (int) ($this->terminalHeight / 2);
        $centerCol = (int) ($this->terminalWidth / 2);

        // Render the logo (FUEL in gradient yellow/orange)
        $fuelGradient = GradientText::fuel('FUEL');
        $fuelText = $fuelGradient->render();
        $fuelLength = 4; // "FUEL"

        // Position logo centered, with fuel pump emoji
        $logoRow = $centerRow - 1;
        $logoCol = $centerCol - (int) (($fuelLength + 3) / 2); // +3 for emoji and space
        echo sprintf("\033[%d;%dH\e[2K⛽ %s", $logoRow, $logoCol, $fuelText);

        // Position status line below logo, centered
        $statusRow = $centerRow + 1;
        $totalWidth = $statusLength + 2; // spinner + space + status
        $statusCol = $centerCol - (int) ($totalWidth / 2);
        echo sprintf("\033[%d;%dH\e[2K\e[38;2;100;120;140m%s\e[0m %s", $statusRow, $statusCol, $spinner, $gradientStatus);

        // Subtle hint at bottom
        $hintRow = $this->terminalHeight - 2;
        $hint = 'Starting background runner...';
        $hintCol = $centerCol - (int) (strlen($hint) / 2);
        echo sprintf("\033[%d;%dH\e[38;2;60;60;70m%s\e[0m", $hintRow, $hintCol, $hint);
    }

    /**
     * Show connecting animation while waiting for a condition.
     *
     * @param  callable(): bool  $condition  Returns true when done waiting
     * @param  string  $status  Status message to display
     * @param  int  $timeoutMs  Maximum time to wait in milliseconds
     * @return bool True if condition was met, false if timed out
     */
    private function showConnectingAnimation(callable $condition, string $status = 'Connecting', int $timeoutMs = 30000): bool
    {
        $frame = 0;
        $startTime = microtime(true) * 1000;
        $frameDelay = 33333; // ~30fps for smooth gradient animation

        while (true) {
            // Check if condition is met
            if ($condition()) {
                // Reset gradient state for next animation
                $this->connectingGradient = null;
                $this->connectingStatus = '';

                return true;
            }

            // Check timeout
            $elapsed = (microtime(true) * 1000) - $startTime;
            if ($elapsed > $timeoutMs) {
                $this->connectingGradient = null;
                $this->connectingStatus = '';

                return false;
            }

            // Render frame
            $this->renderConnectingFrame($frame, $status);
            $frame++;

            usleep($frameDelay);
        }
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

        // Get active process metadata - use IPC client if connected, otherwise local process manager
        $activeProcesses = [];
        if ($this->ipcClient?->isConnected()) {
            // Transform IPC active processes to match expected format
            foreach ($this->ipcClient->getActiveProcesses() as $taskId => $processData) {
                // Handle both integer timestamps (from snapshot) and string dates (from events)
                $startedAt = $processData['started_at'] ?? time();
                if (is_string($startedAt)) {
                    $startedAt = strtotime($startedAt) ?: time();
                }

                $activeProcesses[$taskId] = [
                    'task_id' => $taskId,
                    'agent_name' => $processData['agent'] ?? 'unknown',
                    'duration' => time() - $startedAt,
                    'last_output_time' => $processData['last_output_time'] ?? null,
                ];
            }
        } else {
            foreach ($this->processManager->getActiveProcesses() as $process) {
                $metadata = $process->getMetadata();
                $activeProcesses[$metadata['task_id']] = $metadata;
            }
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

        // Build footer - use counts from IPC client (lazy-loaded data)
        $blockedCount = $this->ipcClient?->getBlockedCount() ?? $blockedTasks->count();
        $doneCount = $this->ipcClient?->getDoneCount() ?? $doneTasks->count();
        $footerParts = [];
        $footerParts[] = '<fg=gray>Shift+Tab: '.($paused ? 'resume' : 'pause').'</>';
        $footerParts[] = '<fg=gray>b: blocked ('.$blockedCount.')</>';
        $footerParts[] = '<fg=gray>d: done ('.$doneCount.')</>';
        $footerParts[] = '<fg=gray>q: exit</>';
        $footerLine = implode(' <fg=#555>|</> ', $footerParts);

        // Connection status indicator (green = connected, red = disconnected)
        $isConnected = $this->ipcClient?->isConnected() === true;
        $connectionIndicator = $isConnected ? '<fg=green>●</>' : '<fg=red>●</>';

        // Render status history above footer (positioned from bottom)
        $footerHeight = 2; // status line + key instructions
        $statusLineCount = count($statusLines);
        if ($statusLineCount > 0) {
            $startRow = $this->terminalHeight - $statusLineCount - $footerHeight;
            foreach ($statusLines as $i => $line) {
                $this->screenBuffer->setLine($startRow + $i, $line);
            }
        }

        // Render command palette if active (overlays status line area)
        if ($this->commandPaletteActive && ! $this->showBlockedModal && ! $this->showDoneModal) {
            $this->captureCommandPalette();
        } else {
            // Status line (centered, above footer)
            $statusLine = $this->buildStatusLine($paused, $isConnected);

            $statusPadding = max(0, (int) floor(($this->terminalWidth - $this->visibleLength($statusLine)) / 2));
            $this->screenBuffer->setLine($this->terminalHeight - 1, str_repeat(' ', $statusPadding).$statusLine);

            // Footer line (centered, at bottom) with connection indicator at far right
            $footerVisibleLen = $this->visibleLength($footerLine);
            $indicatorVisibleLen = 1; // The ● character
            $paddingAmount = max(0, (int) floor(($this->terminalWidth - $footerVisibleLen) / 2));
            $rightPadding = max(0, $this->terminalWidth - $paddingAmount - $footerVisibleLen - $indicatorVisibleLen - 1);
            $this->screenBuffer->setLine($this->terminalHeight, str_repeat(' ', $paddingAmount).$footerLine.str_repeat(' ', $rightPadding).$connectionIndicator.' ');
        }

        // Render modals on top if active - use lazy-loaded data from IPC client
        if ($this->showBlockedModal) {
            $blockedData = $this->ipcClient?->getBlockedTasks();
            if ($blockedData === null) {
                $this->captureLoadingModal('Blocked Tasks');
            } else {
                $this->captureModal('Blocked Tasks', $this->hydrateTasksForModal($blockedData), 'blocked', $this->blockedModalScroll);
            }
        } elseif ($this->showDoneModal) {
            $doneData = $this->ipcClient?->getDoneTasks();
            if ($doneData === null) {
                $this->captureLoadingModal('Done Tasks');
            } else {
                $this->captureModal('Done Tasks', $this->hydrateTasksForModal($doneData), 'done', $this->doneModalScroll);
            }
        }

        return $this->screenBuffer->getLines();
    }

    private function buildStatusLine(bool $paused, bool $isConnected): string
    {
        if (! $isConnected) {
            $spinner = self::CONNECTING_SPINNER[$this->spinnerFrame % count(self::CONNECTING_SPINNER)];
            $this->spinnerFrame++;

            return sprintf('<fg=yellow>%s Reconnecting</>', $spinner);
        }

        if ($paused) {
            return '<fg=yellow>PAUSED</>';
        }

        $spinner = self::CONNECTING_SPINNER[$this->spinnerFrame % count(self::CONNECTING_SPINNER)];
        $this->spinnerFrame++;

        return sprintf('<fg=green>%s Consuming</>', $spinner);
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
     * Hydrate task arrays from IPC into Task model objects for modal rendering.
     *
     * @param  array<array>  $tasksData  Raw task data from IPC
     * @return array<int, Task>
     */
    private function hydrateTasksForModal(array $tasksData): array
    {
        return Task::hydrate($tasksData)->all();
    }

    /**
     * Capture a loading modal as an overlay layer.
     */
    private function captureLoadingModal(string $title): void
    {
        if (! $this->screenBuffer instanceof ScreenBuffer) {
            return;
        }

        // Modal dimensions (centered, 60% width)
        $modalWidth = min((int) ($this->terminalWidth * 0.6), $this->terminalWidth - 8);
        $startCol = (int) (($this->terminalWidth - $modalWidth) / 2) + 1;
        $startRow = 3;

        // Build loading modal content
        $modalLines = [];
        $modalLines[] = '<fg=cyan>╭'.str_repeat('─', $modalWidth - 2).'╮</>';
        $modalLines[] = '<fg=cyan>│</> <fg=white;options=bold>'.$this->truncate($title, $modalWidth - 6).'</>'.str_repeat(' ', max(0, $modalWidth - $this->visibleLength($title) - 3)).'<fg=cyan>│</>';
        $modalLines[] = '<fg=cyan>├'.str_repeat('─', $modalWidth - 2).'┤</>';

        // Loading message
        $loadingMsg = '⏳ Loading...';
        $padding = max(0, $modalWidth - strlen($loadingMsg) - 3);
        $modalLines[] = '<fg=cyan>│</> <fg=yellow>'.$loadingMsg.'</>'.str_repeat(' ', $padding).'<fg=cyan>│</>';

        $modalLines[] = '<fg=cyan>╰'.str_repeat('─', $modalWidth - 2).'╯</>';

        // Store modal as an overlay layer
        $this->screenBuffer->setOverlay(1, $startRow, $startCol, $modalLines);
    }

    /**
     * Capture a modal as an overlay layer (does not modify main buffer).
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
        $startCol = (int) (($this->terminalWidth - $modalWidth) / 2) + 1; // +1 for 1-indexed
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

        // Build modal content (just the lines, no padding)
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

        // Store modal as an overlay layer (layer 1) positioned at startRow, startCol
        $this->screenBuffer->setOverlay(1, $startRow, $startCol, $modalLines);
    }

    /**
     * Render the command palette as an overlay layer.
     *
     * Displays a suggestions box above the input line with autocomplete suggestions,
     * and an input line at the bottom of the terminal with a block cursor.
     */
    private function captureCommandPalette(): void
    {
        if (! $this->screenBuffer instanceof ScreenBuffer) {
            return;
        }

        // Calculate box width and position
        $boxWidth = min(60, $this->terminalWidth - 4);
        $startCol = 2;

        // Input line is at terminalHeight - 1
        $inputRow = $this->terminalHeight - 1;

        $overlayLines = [];
        $overlayStartRow = $inputRow;

        // Build suggestions box - dynamically sized but clears stale content above
        $maxSlots = 5; // Maximum number of suggestion slots
        $maxBoxHeight = $maxSlots + 2; // Max slots + top/bottom borders

        if ($this->commandPaletteSuggestions !== []) {
            $suggestionCount = count($this->commandPaletteSuggestions);
            $visibleCount = min($maxSlots, $suggestionCount);
            $visibleSuggestions = array_slice($this->commandPaletteSuggestions, 0, $visibleCount);
            $actualBoxHeight = $visibleCount + 2; // Visible items + borders

            // Always start overlay at the same row (for max height) to clear stale content
            $boxStartRow = $inputRow - 1 - $maxSlots - 2;
            $overlayStartRow = $boxStartRow;

            // Add empty clearing lines for unused rows above the actual box
            $clearingLines = $maxBoxHeight - $actualBoxHeight;
            for ($i = 0; $i < $clearingLines; $i++) {
                $overlayLines[] = ''; // Empty line - will just clear with \033[K
            }

            // Top border
            $overlayLines[] = '╭'.str_repeat('─', $boxWidth - 2).'╮';

            // Suggestion lines - handle both command and task suggestions
            foreach ($visibleSuggestions as $index => $suggestion) {
                if (isset($suggestion['command'])) {
                    // Command suggestion: show command name + description
                    $cmdName = $suggestion['command'];
                    $cmdDesc = $this->truncate((string) $suggestion['description'], $boxWidth - strlen($cmdName) - 6);
                    $content = sprintf('<fg=cyan>%s</> <fg=gray>%s</>', $cmdName, $cmdDesc);
                } else {
                    // Task suggestion: show ID + title
                    $displayId = substr((string) $suggestion['short_id'], 2, 6);
                    $titleTrunc = $this->truncate((string) $suggestion['title'], $boxWidth - 14);
                    $content = sprintf('[%s] %s', $displayId, $titleTrunc);
                }

                // Apply selection styling with explicit RGB for guaranteed contrast
                if ($index === $this->commandPaletteSuggestionIndex) {
                    $content = '<bg=#1e40af;fg=#ffffff>'.$this->stripAnsi($content).'</>';
                }

                $contentLen = $this->visibleLength($content);
                $padding = max(0, $boxWidth - $contentLen - 2);
                $overlayLines[] = '│'.$content.str_repeat(' ', $padding).'│';
            }

            // Bottom border
            $overlayLines[] = '╰'.str_repeat('─', $boxWidth - 2).'╯';
        } elseif (str_starts_with($this->commandPaletteInput, 'close ')) {
            // Show "No matching tasks" message
            $actualBoxHeight = 3; // Top border, message, bottom border
            $boxStartRow = $inputRow - 1 - $maxSlots - 2;
            $overlayStartRow = $boxStartRow;

            // Add empty clearing lines for unused rows above the actual box
            $clearingLines = $maxBoxHeight - $actualBoxHeight;
            for ($i = 0; $i < $clearingLines; $i++) {
                $overlayLines[] = ''; // Empty line - will just clear with \033[K
            }

            // Top border
            $overlayLines[] = '╭'.str_repeat('─', $boxWidth - 2).'╮';

            // Message line
            $message = 'No matching tasks';
            $messageLen = mb_strlen($message);
            $padding = max(0, $boxWidth - $messageLen - 2);
            $overlayLines[] = '│'.$message.str_repeat(' ', $padding).'│';

            // Bottom border
            $overlayLines[] = '╰'.str_repeat('─', $boxWidth - 2).'╯';
        } else {
            // Suggestions empty and not a task-search command - clear any stale suggestion box
            // Check if input looks like a complete command followed by arguments
            $hasCompleteCommand = false;
            foreach (array_keys(self::PALETTE_COMMANDS) as $cmd) {
                if (str_starts_with($this->commandPaletteInput, $cmd.' ')) {
                    $hasCompleteCommand = true;
                    break;
                }
            }

            if ($hasCompleteCommand) {
                // Add empty clearing lines to wipe stale suggestion box
                $boxStartRow = $inputRow - 1 - $maxSlots - 2;
                $overlayStartRow = $boxStartRow;
                for ($i = 0; $i < $maxBoxHeight; $i++) {
                    $overlayLines[] = ''; // Empty line clears with \033[K
                }
            }
        }

        // Build input line with block cursor
        $input = $this->commandPaletteInput;
        $cursor = $this->commandPaletteCursor;

        // Split input at cursor position
        $before = mb_substr($input, 0, $cursor);
        $after = mb_substr($input, $cursor);

        // Build input line with block cursor
        if ($cursor < mb_strlen($input)) {
            // Cursor is on a character
            $charAtCursor = mb_substr($input, $cursor, 1);
            $inputLine = '> /'.$before.'<bg=white;fg=black>'.$charAtCursor.'</>'.mb_substr($after, 1);
        } else {
            // Cursor is at the end - show block cursor on space
            $inputLine = '> /'.$before.'<bg=white;fg=black> </>';
        }

        // If we have suggestions, add empty lines to bridge to input line, then add input
        if ($overlayLines !== []) {
            // Calculate gap between end of suggestions box and input line
            $suggestionsEndRow = $overlayStartRow + count($overlayLines);
            $gap = $inputRow - $suggestionsEndRow;
            for ($i = 0; $i < $gap; $i++) {
                $overlayLines[] = ''; // Empty lines (won't render anything visible)
            }
        } else {
            $overlayStartRow = $inputRow;
        }

        $overlayLines[] = $inputLine;

        // Store as overlay layer 2 (command palette renders on top of modals)
        $this->screenBuffer->setOverlay(2, $overlayStartRow, $startCol, $overlayLines);
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

            // Render overlay layers on top using cursor positioning
            $this->renderOverlays();

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

        // Render overlay layers on top using cursor positioning
        $this->renderOverlays();

        // Render selection highlight overlay if active
        $this->renderSelectionHighlight();
    }

    /**
     * Render all overlay layers using cursor positioning.
     * Overlays are rendered on top of the main buffer content.
     */
    private function renderOverlays(): void
    {
        if (! $this->screenBuffer instanceof ScreenBuffer || ! $this->screenBuffer->hasOverlays()) {
            return;
        }

        $formatter = $this->getOutput()->getFormatter();

        foreach ($this->screenBuffer->getOverlays() as $overlay) {
            $startRow = $overlay['startRow'];
            $startCol = $overlay['startCol'];
            // Only clear to end of line for left-aligned overlays (like command palette)
            // Centered overlays (like modals) shouldn't clear or they'd erase the right column
            $clearToEnd = $startCol <= 2;

            foreach ($overlay['lines'] as $i => $line) {
                $row = $startRow + $i;
                if ($row > $this->terminalHeight) {
                    break;
                }

                // Position cursor at the overlay's column position and render the line
                $this->getOutput()->write(sprintf("\033[%d;%dH", $row, $startCol));
                $formatted = $formatter->format($line);
                $this->getOutput()->write($formatted);
                // Clear stale characters for left-aligned overlays only
                if ($clearToEnd) {
                    $this->getOutput()->write("\033[K");
                }
            }
        }
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

        // Build footer (key instructions only) - use counts from IPC client when available
        $blockedCount = $this->ipcClient?->getBlockedCount() ?? $blockedTasks->count();
        $doneCount = $this->ipcClient?->getDoneCount() ?? $doneTasks->count();
        $footerParts = [];
        $footerParts[] = '<fg=gray>Shift+Tab: '.($paused ? 'resume' : 'pause').'</>';
        $footerParts[] = '<fg=gray>b: blocked ('.$blockedCount.')</>';
        $footerParts[] = '<fg=gray>d: done ('.$doneCount.')</>';
        $footerParts[] = '<fg=gray>q: exit</>';
        $footerLine = implode(' <fg=#555>|</> ', $footerParts);

        // Connection status indicator (green = connected, red = disconnected)
        $isConnected = $this->ipcClient?->isConnected() ?? false;
        $connectionIndicator = $isConnected ? '<fg=green>●</>' : '<fg=red>●</>';

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
        $statusLine = $this->buildStatusLine($paused, $isConnected);

        $statusPadding = max(0, (int) floor(($this->terminalWidth - $this->visibleLength($statusLine)) / 2));
        $this->getOutput()->write(sprintf("\033[%d;1H%s%s", $this->terminalHeight - 1, str_repeat(' ', $statusPadding), $statusLine));

        // Position footer at bottom of screen with connection indicator at far right
        $footerVisibleLen = $this->visibleLength($footerLine);
        $indicatorVisibleLen = 1; // The ● character
        $paddingAmount = max(0, (int) floor(($this->terminalWidth - $footerVisibleLen) / 2));
        $rightPadding = max(0, $this->terminalWidth - $paddingAmount - $footerVisibleLen - $indicatorVisibleLen - 1);
        $this->getOutput()->write(sprintf("\033[%d;1H%s%s%s%s ", $this->terminalHeight, str_repeat(' ', $paddingAmount), $footerLine, str_repeat(' ', $rightPadding), $connectionIndicator));

        // Render modals if active - use lazy-loaded data from IPC client
        if ($this->showBlockedModal) {
            $blockedData = $this->ipcClient?->getBlockedTasks();
            if ($blockedData === null) {
                // Still loading - show placeholder
                $this->info('Loading blocked tasks...');
            } else {
                $this->renderModal('Blocked Tasks', $this->hydrateTasksForModal($blockedData), 'blocked', $this->blockedModalScroll);
            }
        } elseif ($this->showDoneModal) {
            $doneData = $this->ipcClient?->getDoneTasks();
            if ($doneData === null) {
                // Still loading - show placeholder
                $this->info('Loading done tasks...');
            } else {
                $this->renderModal('Done Tasks', $this->hydrateTasksForModal($doneData), 'done', $this->doneModalScroll);
            }
        }
    }

    /**
     * Get all board data from a single snapshot.
     *
     * @return array{ready: Collection, in_progress: Collection, review: Collection, blocked: Collection, human: Collection, done: Collection}
     */
    private function getBoardData(): array
    {
        // Use IPC client state if connected (normal TUI mode)
        if ($this->ipcClient?->isConnected()) {
            $boardState = $this->ipcClient->getBoardState();

            // Convert arrays to Collections for compatibility with existing rendering code
            return [
                'ready' => collect($boardState['ready'] ?? []),
                'in_progress' => collect($boardState['in_progress'] ?? []),
                'review' => collect($boardState['review'] ?? []),
                'blocked' => collect($boardState['blocked'] ?? []),
                'human' => collect($boardState['human'] ?? []),
                'done' => collect($boardState['done'] ?? []),
            ];
        }

        // Fallback for --once mode (standalone, no runner needed)
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
        $selfGuidedIcon = $task->agent === 'selfguided' ? '∞' : '';
        $autoClosedIcon = '';
        if ($style === 'done') {
            $labels = $task->labels ?? [];
            $autoClosedIcon = is_array($labels) && in_array('auto-closed', $labels, true) ? '🤖' : '';
        }

        $icons = array_filter([$consumeIcon, $failedIcon, $selfGuidedIcon, $autoClosedIcon]);
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
        // Use epic_short_id from IPC serialization, fall back to relationship for standalone mode
        $epicId = $task->epic_short_id ?? $task->epic?->short_id;
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
        $selfGuidedIcon = $task->agent === 'selfguided' ? '∞' : '';
        $icons = array_filter([$consumeIcon, $failedIcon, $selfGuidedIcon]);
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
        // Use epic_short_id from IPC serialization, fall back to relationship for standalone mode
        $epicId = $task->epic_short_id ?? $task->epic?->short_id;
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

        if ($this->commandPaletteActive) {
            return $this->handleCommandPaletteInput();
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

                // Shift+Tab: ESC [ Z (3 bytes) - toggle pause via IPC
                if ($len >= 3 && $buf[2] === 'Z') {
                    if ($this->ipcClient?->isConnected()) {
                        if ($paused) {
                            $this->ipcClient->sendResume();
                            $statusLines[] = $this->formatStatus('▶', 'Resume command sent...', 'green');
                        } else {
                            $this->ipcClient->sendPause();
                            $statusLines[] = $this->formatStatus('⏸', 'Pause command sent...', 'yellow');
                        }
                    } else {
                        // Fallback for standalone mode (--once)
                        $paused = ! $paused;
                        $statusLines[] = $paused
                            ? $this->formatStatus('⏸', 'PAUSED - press Shift+Tab to resume', 'yellow')
                            : $this->formatStatus('▶', 'Resumed - looking for tasks...', 'green');
                    }

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

        if ($char === '/' && ! $this->showBlockedModal && ! $this->showDoneModal) {
            $this->activateCommandPalette();

            return 1;
        }

        switch ($char) {
            case 'b':
            case 'B':
                $this->showBlockedModal = ! $this->showBlockedModal;
                if ($this->showBlockedModal) {
                    $this->showDoneModal = false;
                    $this->doneModalScroll = 0;
                    $this->blockedModalScroll = 0;
                    // Request blocked tasks if not already loaded
                    if ($this->ipcClient?->isConnected() && ! $this->ipcClient->hasBlockedTasks()) {
                        $this->ipcClient->requestBlockedTasks();
                    }
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
                    // Request done tasks if not already loaded
                    if ($this->ipcClient?->isConnected() && ! $this->ipcClient->hasDoneTasks()) {
                        $this->ipcClient->requestDoneTasks();
                    }
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
     * Activate the command palette.
     */
    private function activateCommandPalette(): void
    {
        $this->commandPaletteActive = true;
        $this->commandPaletteInput = '';
        $this->commandPaletteCursor = 0;
        $this->commandPaletteSuggestionIndex = -1;
        $this->selectionStart = null;
        $this->selectionEnd = null;
        $this->updateCommandPaletteSuggestions(); // Show commands immediately
        $this->forceRefresh = true;
    }

    /**
     * Deactivate the command palette.
     */
    private function deactivateCommandPalette(): void
    {
        $this->commandPaletteActive = false;
        $this->commandPaletteInput = '';
        $this->commandPaletteCursor = 0;
        $this->commandPaletteSuggestionIndex = -1;
        $this->commandPaletteSuggestions = [];
        $this->forceRefresh = true;
    }

    /**
     * Handle keyboard input when command palette is active.
     *
     * @return int Bytes consumed (0=incomplete, -1=exit)
     */
    private function handleCommandPaletteInput(): int
    {
        $buf = $this->inputBuffer;
        $len = strlen($buf);
        if ($len === 0) {
            return 0;
        }

        // Escape sequence
        if ($buf[0] === "\x1b") {
            // Bare ESC (only ESC with no following chars) - treat as escape immediately
            if ($len === 1) {
                $this->deactivateCommandPalette();
                $this->inputBuffer = '';

                return 1;
            }

            // CSI sequences (ESC [)
            if ($buf[1] === '[') {
                if ($len < 3) {
                    return 0; // Need more data
                }

                // Mouse events (ESC [ M <btn> <x> <y>) - ignore but consume
                if ($buf[2] === 'M') {
                    if ($len < 6) {
                        return 0; // Need more data for mouse event
                    }

                    $this->inputBuffer = substr($buf, 6);

                    return 6; // Consume mouse event without action
                }

                // Arrow keys
                switch ($buf[2]) {
                    case 'A': // Up arrow
                        $this->commandPaletteSuggestionUp();
                        $this->inputBuffer = substr($buf, 3);

                        return 3;
                    case 'B': // Down arrow
                        $this->commandPaletteSuggestionDown();
                        $this->inputBuffer = substr($buf, 3);

                        return 3;
                    case 'C': // Right arrow
                        $this->commandPaletteCursorRight();
                        $this->inputBuffer = substr($buf, 3);

                        return 3;
                    case 'D': // Left arrow
                        $this->commandPaletteCursorLeft();
                        $this->inputBuffer = substr($buf, 3);

                        return 3;
                }
            }

            // Bare ESC or ESC+other -> deactivate
            $this->deactivateCommandPalette();
            $this->inputBuffer = substr($buf, 1);

            return 1;
        }

        // Single character handling
        $char = $buf[0];
        $this->inputBuffer = substr($buf, 1);
        // Remove consumed byte
        // Enter (carriage return or newline)
        if ($char === "\r" || $char === "\n") {
            $this->executeCommandPalette();

            return 1;
        }

        // Backspace (DEL or BS)
        if ($char === "\x7f" || $char === "\x08") {
            $this->commandPaletteBackspace();

            return 1;
        }

        // Ctrl+A (move to start)
        if ($char === "\x01") {
            $this->commandPaletteCursor = 0;

            return 1;
        }

        // Ctrl+E (move to end)
        if ($char === "\x05") {
            $this->commandPaletteCursor = mb_strlen($this->commandPaletteInput);

            return 1;
        }

        // Ctrl+C (cancel)
        if ($char === "\x03") {
            $this->deactivateCommandPalette();

            return 1;
        }

        // Tab (accept suggestion)
        if ($char === "\t") {
            $this->acceptCurrentSuggestion();

            return 1;
        }

        // Printable characters
        $ord = ord($char);
        if ($ord >= 32 && $ord < 127) {
            $this->commandPaletteInsertChar($char);

            return 1;
        }

        // Unknown character - already consumed
        return 1;
    }

    /**
     * Insert a character at the cursor position in the command palette input.
     */
    private function commandPaletteInsertChar(string $char): void
    {
        $input = $this->commandPaletteInput;
        $cursor = $this->commandPaletteCursor;
        $before = mb_substr($input, 0, $cursor);
        $after = mb_substr($input, $cursor);
        $this->commandPaletteInput = $before.$char.$after;
        $this->commandPaletteCursor++;
        $this->updateCommandPaletteSuggestions();
    }

    /**
     * Remove the character before the cursor in the command palette input.
     */
    private function commandPaletteBackspace(): void
    {
        if ($this->commandPaletteCursor > 0) {
            $input = $this->commandPaletteInput;
            $cursor = $this->commandPaletteCursor;
            $before = mb_substr($input, 0, $cursor - 1);
            $after = mb_substr($input, $cursor);
            $this->commandPaletteInput = $before.$after;
            $this->commandPaletteCursor--;
            $this->updateCommandPaletteSuggestions();
        }
    }

    /**
     * Move the cursor left in the command palette input.
     */
    private function commandPaletteCursorLeft(): void
    {
        if ($this->commandPaletteCursor > 0) {
            $this->commandPaletteCursor--;
        }
    }

    /**
     * Move the cursor right in the command palette input.
     */
    private function commandPaletteCursorRight(): void
    {
        $inputLength = mb_strlen($this->commandPaletteInput);
        if ($this->commandPaletteCursor < $inputLength) {
            $this->commandPaletteCursor++;
        }
    }

    /**
     * Move to the previous suggestion in the command palette.
     */
    private function commandPaletteSuggestionUp(): void
    {
        if ($this->commandPaletteSuggestionIndex > -1) {
            $this->commandPaletteSuggestionIndex--;
        }
    }

    /**
     * Move to the next suggestion in the command palette.
     */
    private function commandPaletteSuggestionDown(): void
    {
        $suggestionCount = count($this->commandPaletteSuggestions);
        if ($this->commandPaletteSuggestionIndex < $suggestionCount - 1) {
            $this->commandPaletteSuggestionIndex++;
        }
    }

    /** Available commands in the command palette */
    private const PALETTE_COMMANDS = [
        'add' => 'Create a new task',
        'close' => 'Mark a task as done',
        'pause' => 'Pause task consumption',
        'reload' => 'Reload configuration',
        'reopen' => 'Reopen a closed or failed task',
        'resume' => 'Resume task consumption',
    ];

    /**
     * Accept the currently selected suggestion and update the input.
     */
    private function acceptCurrentSuggestion(): void
    {
        $index = $this->commandPaletteSuggestionIndex;
        if ($index < 0 || $index >= count($this->commandPaletteSuggestions)) {
            return;
        }

        $selected = $this->commandPaletteSuggestions[$index];

        // Check if this is a command suggestion (has 'command' key)
        if (is_array($selected) && isset($selected['command'])) {
            $this->commandPaletteInput = $selected['command'].' ';
            $this->commandPaletteCursor = mb_strlen($this->commandPaletteInput);
            $this->commandPaletteSuggestionIndex = -1;
            $this->updateCommandPaletteSuggestions();

            return;
        }

        // Task suggestion - has short_id
        if (isset($selected->short_id) || (is_array($selected) && isset($selected['short_id']))) {
            $shortId = is_object($selected) ? $selected->short_id : $selected['short_id'];
            // Determine which command we're completing for
            $prefix = str_starts_with($this->commandPaletteInput, 'reopen ') ? 'reopen ' : 'close ';
            $this->commandPaletteInput = $prefix.$shortId;
            $this->commandPaletteCursor = mb_strlen($this->commandPaletteInput);
            $this->updateCommandPaletteSuggestions();
        }
    }

    /**
     * Update command palette suggestions based on current input.
     */
    private function updateCommandPaletteSuggestions(): void
    {
        $input = $this->commandPaletteInput;

        // Check if input matches a command with arguments (e.g., "close ")
        if (str_starts_with($input, 'close ')) {
            $this->updateTaskSuggestions(mb_substr($input, 6));

            return;
        }

        // Check if input matches reopen command with arguments
        if (str_starts_with($input, 'reopen ')) {
            $this->updateReopenTaskSuggestions(mb_substr($input, 7));

            return;
        }

        // Show matching commands
        $this->updateCommandSuggestions($input);
    }

    /**
     * Update suggestions with matching commands.
     */
    private function updateCommandSuggestions(string $input): void
    {
        $inputLower = mb_strtolower($input);
        $suggestions = [];

        foreach (self::PALETTE_COMMANDS as $command => $description) {
            // Show all commands if input is empty, or filter by prefix match
            if ($input === '' || str_starts_with($command, $inputLower)) {
                $suggestions[] = [
                    'command' => $command,
                    'description' => $description,
                ];
            }
        }

        $this->commandPaletteSuggestions = $suggestions;
        $this->clampSuggestionIndex();
    }

    /**
     * Update suggestions with matching tasks for the close command.
     */
    private function updateTaskSuggestions(string $searchTerm): void
    {
        // Get all non-done tasks
        $tasks = $this->taskService->all()->filter(fn (Task $t): bool => $t->status !== TaskStatus::Done);

        // Filter by partial match (case-insensitive) on short_id OR title
        if ($searchTerm !== '') {
            $searchTermLower = mb_strtolower($searchTerm);
            $tasks = $tasks->filter(function (Task $t) use ($searchTermLower): bool {
                $shortIdLower = mb_strtolower($t->short_id);
                $titleLower = mb_strtolower($t->title);

                return str_contains($shortIdLower, $searchTermLower) || str_contains($titleLower, $searchTermLower);
            });
        }

        // Sort by priority, take first 10
        $this->commandPaletteSuggestions = $tasks
            ->sortBy('priority')
            ->take(10)
            ->map(fn (Task $t): array => [
                'short_id' => $t->short_id,
                'title' => $t->title,
            ])
            ->values()
            ->toArray();

        $this->clampSuggestionIndex();
    }

    /**
     * Update suggestions with reopenable tasks for the reopen command.
     * Shows failed tasks + last 5 done tasks.
     */
    private function updateReopenTaskSuggestions(string $searchTerm): void
    {
        // Get failed tasks (in_progress but process died)
        $failed = $this->taskService->failed();

        // Get last 5 done tasks
        $done = $this->taskService->all()
            ->filter(fn (Task $t): bool => $t->status === TaskStatus::Done)
            ->sortByDesc('updated_at')
            ->take(5);

        // Combine: failed first, then done
        $tasks = $failed->merge($done)->unique('short_id');

        // Filter by partial match (case-insensitive) on short_id OR title
        if ($searchTerm !== '') {
            $searchTermLower = mb_strtolower($searchTerm);
            $tasks = $tasks->filter(function (Task $t) use ($searchTermLower): bool {
                $shortIdLower = mb_strtolower($t->short_id);
                $titleLower = mb_strtolower($t->title);

                return str_contains($shortIdLower, $searchTermLower) || str_contains($titleLower, $searchTermLower);
            });
        }

        // Take first 10 results
        $this->commandPaletteSuggestions = $tasks
            ->take(10)
            ->map(fn (Task $t): array => [
                'short_id' => $t->short_id,
                'title' => $t->title,
                'status' => $t->status->value,
            ])
            ->values()
            ->toArray();

        $this->clampSuggestionIndex();
    }

    /**
     * Clamp the suggestion index to valid range.
     */
    private function clampSuggestionIndex(): void
    {
        $count = count($this->commandPaletteSuggestions);
        if ($this->commandPaletteSuggestionIndex >= $count) {
            $this->commandPaletteSuggestionIndex = $count > 0 ? $count - 1 : -1;
        }
    }

    /**
     * Execute the command palette command.
     */
    private function executeCommandPalette(): void
    {
        // Trim input
        $input = trim($this->commandPaletteInput);

        // Handle pause command
        if ($input === 'pause') {
            $this->ipcClient?->sendPause();
            $this->toast?->show('Paused', 'success');
            $this->deactivateCommandPalette();
            $this->forceRefresh = true;

            return;
        }

        // Handle resume command
        if ($input === 'resume') {
            $this->ipcClient?->sendResume();
            $this->toast?->show('Resumed', 'success');
            $this->deactivateCommandPalette();
            $this->forceRefresh = true;

            return;
        }

        // Handle reload command
        if ($input === 'reload') {
            $this->ipcClient?->sendReloadConfig();
            $this->toast?->show('Config reloaded', 'success');
            $this->deactivateCommandPalette();
            $this->forceRefresh = true;

            return;
        }

        // Handle add command - creates task with just a title
        if (preg_match('/^add\s+(.+)$/', $input, $matches)) {
            $title = trim($matches[1]);

            if ($title === '') {
                $this->toast?->show('Task title required', 'error');
                $this->deactivateCommandPalette();
                $this->forceRefresh = true;

                return;
            }

            $taskId = $this->ipcClient?->createTaskWithResponse(['title' => $title]);

            if ($taskId !== null) {
                $this->toast?->show('Created: '.$taskId, 'success');
            } else {
                $this->toast?->show('Failed to create task', 'error');
            }

            $this->deactivateCommandPalette();
            $this->forceRefresh = true;

            return;
        }

        // Parse /close command
        if (preg_match('/^close\s+(\S+)/', $input, $matches)) {
            $taskIdInput = $matches[1];

            // If suggestionIndex >= 0 and valid, use that task's short_id instead
            if ($this->commandPaletteSuggestionIndex >= 0 && $this->commandPaletteSuggestionIndex < count($this->commandPaletteSuggestions)) {
                $selected = $this->commandPaletteSuggestions[$this->commandPaletteSuggestionIndex];
                if (isset($selected['short_id'])) {
                    $taskIdInput = $selected['short_id'];
                }
            }

            // Find task
            $task = $this->taskService->find($taskIdInput);

            // Validate
            if (! $task instanceof Task) {
                $this->toast?->show('Task not found: '.$taskIdInput, 'error');
                $this->deactivateCommandPalette();
                $this->forceRefresh = true;

                return;
            }

            if ($task->status === TaskStatus::Done) {
                $this->toast?->show('Task already done', 'warning');
                $this->deactivateCommandPalette();
                $this->forceRefresh = true;

                return;
            }

            // Execute
            $this->ipcClient?->sendTaskDone($taskIdInput);
            $this->checkEpicCompletionSound($taskIdInput);
            $this->toast?->show('Closed: '.$task->short_id, 'success');
            $this->deactivateCommandPalette();
            $this->forceRefresh = true;

            return;
        }

        // Parse /reopen command
        if (preg_match('/^reopen\s+(\S+)/', $input, $matches)) {
            $taskIdInput = $matches[1];

            // If suggestionIndex >= 0 and valid, use that task's short_id instead
            if ($this->commandPaletteSuggestionIndex >= 0 && $this->commandPaletteSuggestionIndex < count($this->commandPaletteSuggestions)) {
                $selected = $this->commandPaletteSuggestions[$this->commandPaletteSuggestionIndex];
                if (isset($selected['short_id'])) {
                    $taskIdInput = $selected['short_id'];
                }
            }

            // Find task
            $task = $this->taskService->find($taskIdInput);

            // Validate
            if (! $task instanceof Task) {
                $this->toast?->show('Task not found: '.$taskIdInput, 'error');
                $this->deactivateCommandPalette();
                $this->forceRefresh = true;

                return;
            }

            // Only allow reopening done, in_progress (failed), or review tasks
            if (! in_array($task->status, [TaskStatus::Done, TaskStatus::InProgress, TaskStatus::Review], true)) {
                $this->toast?->show('Cannot reopen: '.$task->status->value, 'warning');
                $this->deactivateCommandPalette();
                $this->forceRefresh = true;

                return;
            }

            // Execute
            $this->ipcClient?->sendTaskReopen($taskIdInput);
            $this->toast?->show('Reopened: '.$task->short_id, 'success');
            $this->deactivateCommandPalette();
            $this->forceRefresh = true;

            return;
        }

        // Handle unknown command or empty input
        if ($input !== '' && ! str_starts_with($input, 'close') && ! str_starts_with($input, 'add') && ! str_starts_with($input, 'reopen')) {
            $this->toast?->show('Unknown command: '.$input, 'error');
        }

        // Always call deactivateCommandPalette() and set forceRefresh=true at end
        $this->deactivateCommandPalette();
        $this->forceRefresh = true;
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

    /**
     * Check if any IPC control flag is set.
     */
    private function hasIpcControlFlag(): bool
    {
        if ($this->option('status')) {
            return true;
        }

        if ($this->option('pause')) {
            return true;
        }

        if ($this->option('resume') || $this->option('unpause')) {
            return true;
        }

        if ($this->option('stop')) {
            return true;
        }

        if ($this->option('restart')) {
            return true;
        }

        return (bool) $this->option('force');
    }

    /**
     * Handle IPC control flags (--status, --pause, --resume, --stop, --force, --restart).
     */
    private function handleIpcControl(): int
    {
        $ip = $this->option('ip');
        $this->ipcClient = new ConsumeIpcClient($ip);
        $pidFilePath = $this->fuelContext->getPidFilePath();
        $port = $this->option('port') ? (int) $this->option('port') : $this->configService->getConsumePort();
        $isRemote = $ip !== '127.0.0.1';

        // Check if runner is alive (skip for remote runners - PID file is local only)
        if (! $isRemote && ! $this->ipcClient->isRunnerAlive($pidFilePath)) {
            $this->error('Runner is not running');

            return self::FAILURE;
        }

        try {
            $this->ipcClient->connect($port);
            $this->ipcClient->attach();
        } catch (\RuntimeException $runtimeException) {
            $this->error('Failed to connect to runner: '.$runtimeException->getMessage());

            return self::FAILURE;
        }

        try {
            if ($this->option('status')) {
                return $this->handleStatusCommand();
            }

            if ($this->option('pause')) {
                $this->ipcClient->sendPause();
                $this->info('Pause command sent to runner');

                return self::SUCCESS;
            }

            if ($this->option('resume') || $this->option('unpause')) {
                $this->ipcClient->sendResume();
                $this->info('Resume command sent to runner');

                return self::SUCCESS;
            }

            if ($this->option('stop')) {
                $this->ipcClient->sendStop();
                $this->info('Stop command sent to runner');

                return self::SUCCESS;
            }

            if ($this->option('force')) {
                $this->ipcClient->sendStop();
                $this->info('Force stop command sent to runner');

                return self::SUCCESS;
            }

            if ($this->option('restart')) {
                return $this->handleRestartCommand($pidFilePath, $port);
            }
        } finally {
            $this->ipcClient->disconnect();
        }

        return self::SUCCESS;
    }

    /**
     * Handle --start flag: start runner daemon in background and exit.
     */
    private function handleStartDaemon(): int
    {
        $ipcClient = new ConsumeIpcClient($this->option('ip'));
        $pidFilePath = $this->fuelContext->getPidFilePath();
        $port = $this->option('port') ? (int) $this->option('port') : $this->configService->getConsumePort();

        // Check if already running
        if ($ipcClient->isRunnerAlive($pidFilePath)) {
            $pidData = json_decode(@file_get_contents($pidFilePath), true);
            $pid = $pidData['pid'] ?? 'unknown';
            $this->info(sprintf('Runner is already running (PID: %s)', $pid));

            return self::SUCCESS;
        }

        // Start runner in background
        $this->info('Starting runner daemon...');
        $ipcClient->startRunner($this->fuelContext->getFuelBinaryPath(), $port);

        // Wait for runner to be ready
        try {
            $ipcClient->waitForServer($port, 5);
        } catch (\RuntimeException $runtimeException) {
            $this->error('Failed to start runner: '.$runtimeException->getMessage());

            return self::FAILURE;
        }

        // Read PID from file
        $pidData = json_decode(@file_get_contents($pidFilePath), true);
        $pid = $pidData['pid'] ?? 'unknown';

        $this->info(sprintf('Runner daemon started (PID: %s)', $pid));

        return self::SUCCESS;
    }

    /**
     * Handle --status command: print runner state summary.
     */
    private function handleStatusCommand(): int
    {
        $this->ipcClient->requestSnapshot();

        // Wait briefly for snapshot response
        $deadline = time() + 2;
        while (time() < $deadline) {
            $events = $this->ipcClient->pollEvents();
            foreach ($events as $event) {
                $this->ipcClient->applyEvent($event);
            }

            usleep(50000);
        }

        $boardState = $this->ipcClient->getBoardState();
        $activeProcesses = $this->ipcClient->getActiveProcesses();
        $paused = $this->ipcClient->isPaused();

        $this->line('<fg=white;options=bold>Runner Status</>');
        $this->line('');
        $this->line(sprintf('  State: %s', $paused ? '<fg=yellow>PAUSED</>' : '<fg=green>RUNNING</>'));
        $this->line(sprintf('  Active processes: <fg=cyan>%d</>', count($activeProcesses)));
        $this->line('');
        $this->line('<fg=white;options=bold>Board Summary</>');

        foreach ($boardState as $status => $tasks) {
            $count = is_array($tasks) ? count($tasks) : $tasks->count();
            $this->line(sprintf('  %s: <fg=cyan>%d</>', ucfirst($status), $count));
        }

        return self::SUCCESS;
    }

    /**
     * Handle --restart command: stop the runner and start it again.
     */
    private function handleRestartCommand(string $pidFilePath, int $port): int
    {
        // Send stop command to runner
        $this->ipcClient->sendStop();
        $this->info('Stopping runner...');

        // Disconnect from the runner
        $this->ipcClient->disconnect();

        // Wait for runner to stop (check PID file and process)
        $maxWaitSeconds = 10;
        $startTime = time();
        while (time() - $startTime < $maxWaitSeconds) {
            if (! $this->ipcClient->isRunnerAlive($pidFilePath)) {
                break;
            }

            usleep(200000); // 200ms
        }

        // Verify runner has stopped
        if ($this->ipcClient->isRunnerAlive($pidFilePath)) {
            $this->error('Failed to stop runner within timeout');

            return self::FAILURE;
        }

        $this->info('Runner stopped successfully');

        // Start the runner again
        $this->info('Starting runner...');
        try {
            $this->ipcClient->startRunner($this->fuelContext->getFuelBinaryPath(), $port);

            // Wait for server to be ready
            $this->ipcClient->waitForServer($port, 10);

            $this->info('Runner restarted successfully');

            return self::SUCCESS;
        } catch (\RuntimeException $runtimeException) {
            $this->error('Failed to restart runner: '.$runtimeException->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Handle an IPC event and update status lines.
     */
    private function handleIpcEvent(IpcMessage $event, array &$statusLines): void
    {
        // Apply event to IPC client state
        $this->ipcClient?->applyEvent($event);

        // Add status lines based on event type
        match ($event->type()) {
            'task_spawned' => $statusLines[] = $this->handleTaskSpawnedIpcEvent($event),
            'task_completed' => $statusLines[] = $this->handleTaskCompletedIpcEvent($event),
            'status_line' => $statusLines[] = $this->handleStatusLineIpcEvent($event),
            'health_change' => $statusLines[] = $this->handleHealthChangeIpcEvent($event),
            'review_completed' => $statusLines[] = $this->handleReviewCompletedIpcEvent($event),
            'output_chunk' => $this->spinnerFrame++, // Just spin the activity indicator
            default => null,
        };

        // Filter null entries
        $statusLines = array_filter($statusLines);
    }

    /**
     * Format task spawned event for status line.
     */
    private function handleTaskSpawnedIpcEvent(IpcMessage $event): ?string
    {
        if (! $event instanceof TaskSpawnedEvent) {
            return null;
        }

        return $this->formatStatus('🚀', sprintf('Spawned %s with %s', $event->taskId(), $event->agent()), 'cyan');
    }

    /**
     * Format task completed event for status line.
     */
    private function handleTaskCompletedIpcEvent(IpcMessage $event): ?string
    {
        if (! $event instanceof TaskCompletedEvent) {
            return null;
        }

        $icon = $event->exitCode() === 0 ? '✓' : '✗';
        $color = $event->exitCode() === 0 ? 'green' : 'red';

        return $this->formatStatus($icon, sprintf('Completed %s (exit %d)', $event->taskId(), $event->exitCode()), $color);
    }

    /**
     * Format status line event for display.
     */
    private function handleStatusLineIpcEvent(IpcMessage $event): ?string
    {
        if (! $event instanceof StatusLineEvent) {
            return null;
        }

        $color = match ($event->level()) {
            'error' => 'red',
            'warn' => 'yellow',
            default => 'gray',
        };

        return $this->formatStatus('•', $event->text(), $color);
    }

    /**
     * Format health change event for status line.
     */
    private function handleHealthChangeIpcEvent(IpcMessage $event): ?string
    {
        if (! $event instanceof HealthChangeEvent) {
            return null;
        }

        return $this->formatStatus('⚕', sprintf('Agent %s: %s', $event->agent(), $event->status()), 'yellow');
    }

    /**
     * Format review completed event for status line.
     */
    private function handleReviewCompletedIpcEvent(IpcMessage $event): ?string
    {
        if (! $event instanceof ReviewCompletedEvent) {
            return null;
        }

        $taskId = $event->taskId();

        if ($event->passed()) {
            // Review passed
            $this->checkEpicCompletionSound($taskId);
            if ($event->wasAlreadyDone()) {
                return $this->formatStatus('✓', sprintf('Review passed for %s (was already done)', $taskId), 'green');
            }

            return $this->formatStatus('✓', sprintf('Review passed for %s', $taskId), 'green');
        }

        // Review failed
        $issuesSummary = $event->issues() === [] ? 'issues found' : implode(', ', $event->issues());

        return $this->formatStatus('⚠', sprintf('Review found issues for %s (reopened): %s', $taskId, $issuesSummary), 'yellow');
    }
}
