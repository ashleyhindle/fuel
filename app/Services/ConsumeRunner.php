<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AgentHealthTrackerInterface;
use App\Contracts\ReviewServiceInterface;
use App\DTO\ConsumeSnapshot;
use App\Enums\FailureType;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Process\CompletionResult;
use App\Process\CompletionType;
use App\Process\ProcessType;
use App\Process\ReviewResult;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * Long-lived runner process that owns agent pipes and manages the consume loop.
 *
 * Responsibilities:
 * - Write and maintain PID file
 * - Start/stop IPC server
 * - Manage pause/resume state
 * - Run main consume loop (poll IPC, check shutdown, spawn tasks)
 * - Register signal handlers via ProcessManager
 * - Provide snapshot of current state
 * - Spawn tasks and handle completions
 */
final class ConsumeRunner
{
    /** Flag indicating if runner is paused (stops accepting new tasks) */
    private bool $paused = true;

    /** Flag indicating if runner is shutting down */
    private bool $shuttingDown = false;

    /** Unique instance identifier (UUID v4) */
    private readonly string $instanceId;

    /** When the runner was started */
    private readonly DateTimeImmutable $startedAt;

    /** Cache TTL for task data in seconds */
    private const TASK_CACHE_TTL = 2;

    /** @var array{tasks: Collection|null, ready: Collection|null, failed: Collection|null, timestamp: int} */
    private array $taskCache = ['tasks' => null, 'ready' => null, 'failed' => null, 'timestamp' => 0];

    /** @var array<string, int> Track retry attempts per task */
    private array $taskRetryAttempts = [];

    /** @var array<string, string> Track original task status before review (to handle already-done tasks) */
    private array $preReviewTaskStatus = [];

    /** @var array<string, string> Ring buffer for last 4KB of output per active process (taskId => output) */
    private array $outputRingBuffers = [];

    /** Ring buffer size limit (4KB per process) */
    private const RING_BUFFER_SIZE = 4096;

    /** @var array<string, string> Track previous agent health status for change detection (agent => status) */
    private array $previousHealthStatus = [];

    /** Timestamp of last snapshot broadcast (for periodic refresh) */
    private int $lastSnapshotBroadcast = 0;

    /** Interval in seconds between periodic snapshot broadcasts */
    private const SNAPSHOT_BROADCAST_INTERVAL = 2;

    /** Hash of last broadcast snapshot (for change detection) */
    private ?string $lastSnapshotHash = null;

    /** Whether automatic task reviews are enabled */
    private bool $taskReviewEnabled = false;

    public function __construct(
        private readonly ConsumeIpcServer $ipcServer,
        private readonly ProcessManager $processManager,
        private readonly ConsumeIpcProtocol $protocol,
        private readonly TaskService $taskService,
        private readonly ConfigService $configService,
        private readonly RunService $runService,
        private readonly BackoffStrategy $backoffStrategy,
        private readonly TaskPromptBuilder $promptBuilder,
        private readonly FuelContext $fuelContext,
        private readonly ?AgentHealthTrackerInterface $healthTracker = null,
        private readonly ?ReviewServiceInterface $reviewService = null,
    ) {
        $this->instanceId = $this->protocol->generateInstanceId();
        $this->startedAt = new DateTimeImmutable;
    }

    /**
     * Start the runner.
     *
     * - Check for stale PID file and delete if PID is dead
     * - Write new PID file
     * - Start IPC server
     * - Register signal handlers via ProcessManager
     * - Register output callback for IPC broadcasting
     * - Enter main loop
     * - Cleanup after main loop exits
     *
     * @param  bool  $taskReviewEnabled  Whether to enable automatic task reviews
     */
    public function start(bool $taskReviewEnabled = false): void
    {
        $this->taskReviewEnabled = $taskReviewEnabled;
        // Check for stale PID file
        $this->cleanupStalePidFile();

        // Write PID file
        $this->writePidFile();

        // Start IPC server
        $this->ipcServer->start();

        // Register signal handlers via ProcessManager
        $this->processManager->registerSignalHandlers();

        // Register output callback for broadcasting output chunks to IPC clients
        $this->processManager->setOutputCallback(function (string $taskId, string $stream, string $chunk): void {
            $this->handleOutputChunk($taskId, $stream, $chunk);
        });

        // Enter main loop
        $this->mainLoop();

        // Cleanup after main loop exits (only if stop() was explicitly called or ProcessManager signaled shutdown)
        // Skip cleanup if testing flag is set or if loop exited for other reasons
        if ($this->shuttingDown && $this->stopCalled && ! $this->skipCleanup) {
            $this->cleanup();
        }
    }

    /** @var bool Whether to perform graceful shutdown (vs force) */
    private bool $gracefulShutdown = true;

    /** @var bool Whether stop() was explicitly called (vs external signal) */
    private bool $stopCalled = false;

    /** @var bool Whether to skip cleanup (for testing) */
    private bool $skipCleanup = false;

    /**
     * Set whether to skip cleanup on shutdown (for testing).
     */
    public function setSkipCleanup(bool $skip): void
    {
        $this->skipCleanup = $skip;
    }

    /**
     * Initiate runner shutdown.
     *
     * This method sets the shutdown flag and optionally kills processes immediately.
     * Actual cleanup (stopping IPC server, deleting PID file) happens after the main
     * loop exits in cleanup().
     *
     * @param  bool  $graceful  If true, wait for in-flight tasks; if false, kill immediately
     */
    public function stop(bool $graceful = true): void
    {
        $this->shuttingDown = true;
        $this->stopCalled = true;
        $this->gracefulShutdown = $graceful;

        // For force shutdown, kill processes immediately
        // For graceful shutdown, processes will be handled in cleanup()
        if (! $graceful) {
            foreach ($this->processManager->getActiveProcesses() as $process) {
                $this->processManager->kill($process->getTaskId());
            }
        }
    }

    /**
     * Cleanup after main loop exits.
     *
     * This method performs cleanup tasks that should happen after the main loop
     * has exited (stopping IPC server, shutting down processes, deleting PID file).
     */
    private function cleanup(): void
    {
        // Shutdown processes if graceful mode
        if ($this->gracefulShutdown) {
            $this->processManager->shutdown();
        }

        // Stop IPC server
        $this->ipcServer->stop();

        // Clean up PID file
        $this->deletePidFile();
    }

    /**
     * Pause the runner (stop accepting new tasks).
     */
    public function pause(): void
    {
        $this->paused = true;
    }

    /**
     * Resume the runner (start accepting new tasks).
     */
    public function resume(): void
    {
        $this->paused = false;
    }

    /**
     * Check if runner is paused.
     */
    public function isPaused(): bool
    {
        return $this->paused;
    }

    /**
     * Check if runner is shutting down.
     */
    public function isShuttingDown(): bool
    {
        return $this->shuttingDown;
    }

    /**
     * Get the unique instance identifier.
     */
    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    /**
     * Get a snapshot of the current runner state.
     *
     * This method provides a minimal snapshot with runner state only.
     * Full snapshots with board data should be created by the consume loop
     * using ConsumeSnapshot::fromBoardData().
     */
    public function getSnapshot(): ConsumeSnapshot
    {
        return new ConsumeSnapshot(
            boardState: [
                'ready' => collect(),
                'in_progress' => collect(),
                'review' => collect(),
                'blocked' => collect(),
                'human' => collect(),
                'done' => collect(),
            ],
            activeProcesses: [],
            healthSummary: [],
            runnerState: [
                'paused' => $this->paused,
                'started_at' => $this->startedAt->getTimestamp(),
                'instance_id' => $this->instanceId,
            ],
            config: [
                'interval_seconds' => 5, // Default interval
                'agents' => [],
            ]
        );
    }

    /**
     * Main loop for the runner.
     *
     * Structure:
     * - Accept new client connections and send initial events
     * - Poll IPC commands from clients
     * - Check for shutdown signal
     * - Check for health changes
     * - Run tick() to handle spawning, completions, and reviews
     * - Sleep for interval
     * - Repeat
     */
    private function mainLoop(): void
    {
        while (! $this->shuttingDown) {
            // Accept new client connections
            $clientCount = $this->ipcServer->getClientCount();
            $this->ipcServer->accept();

            // If new clients connected, send them HelloEvent and SnapshotEvent
            if ($this->ipcServer->getClientCount() > $clientCount) {
                $this->broadcastHelloAndSnapshot();
            }

            // Poll IPC commands from clients
            $this->pollIpcCommands();

            // Check for shutdown signal (handled by ProcessManager)
            if ($this->processManager->isShuttingDown()) {
                $this->shuttingDown = true;
                $this->stopCalled = true; // Treat signal as equivalent to stop() call
                break;
            }

            // Check for health changes and broadcast events
            $this->checkHealthChanges();

            // Run tick to handle spawning, completions, and reviews
            $this->tick();

            // Periodically check for external changes (reopen, done, add, etc.) and broadcast if changed
            $now = time();
            if ($this->ipcServer->getClientCount() > 0 && ($now - $this->lastSnapshotBroadcast) >= self::SNAPSHOT_BROADCAST_INTERVAL) {
                $this->broadcastSnapshotIfChanged();
                $this->lastSnapshotBroadcast = $now;
            }

            // Sleep for interval (default 100ms for responsiveness)
            usleep(100000);
        }
    }

    /**
     * Single tick of the consume loop.
     *
     * Responsibilities:
     * - Fill available slots (if not paused)
     * - Poll completions
     * - Check completed reviews
     */
    public function tick(): void
    {
        // Step 1: Fill available slots if not paused and not shutting down
        if (! $this->paused && ! $this->shuttingDown) {
            $readyTasks = $this->getCachedReadyTasks();

            if ($readyTasks->isNotEmpty()) {
                // Sort tasks by priority then creation date (FIFO within priority)
                $sortedTasks = $readyTasks->sortBy([
                    ['priority', 'asc'],
                    ['created_at', 'asc'],
                ])->values();

                // Try to spawn tasks until we can't spawn any more
                foreach ($sortedTasks as $task) {
                    $this->trySpawnTask($task, null);
                }
            }
        }

        // Step 2: Poll all running processes and handle completions
        $this->pollAndHandleCompletions();

        // Step 3: Check for completed reviews
        $this->checkCompletedReviews();
    }

    /**
     * Poll IPC commands from connected clients and handle them.
     */
    private function pollIpcCommands(): void
    {
        $commands = $this->ipcServer->poll();

        foreach ($commands as $clientId => $messages) {
            foreach ($messages as $message) {
                $this->handleIpcCommand($clientId, $message);
            }
        }
    }

    /**
     * Handle a single IPC command from a client.
     *
     * @param  string  $clientId  The client ID that sent the command
     * @param  \App\Ipc\IpcMessage  $message  The decoded IPC message
     */
    private function handleIpcCommand(string $clientId, \App\Ipc\IpcMessage $message): void
    {
        // Handle commands based on message type
        match ($message->type()) {
            'pause' => $this->handlePauseCommand(),
            'resume' => $this->handleResumeCommand(),
            'stop' => $this->handleStopCommand($message),
            'request_snapshot' => $this->sendSnapshot($clientId),
            'set_task_review_enabled' => $this->handleSetTaskReviewCommand($message),
            // Task mutation commands
            'task_start' => $this->handleTaskStartCommand($message),
            'task_reopen' => $this->handleTaskReopenCommand($message),
            'task_done' => $this->handleTaskDoneCommand($message),
            'task_create' => $this->handleTaskCreateCommand($message),
            'dependency_add' => $this->handleDependencyAddCommand($message),
            // Other commands (attach, detach, reload_config, set_interval) are handled in future tasks
            default => null,
        };
    }

    /**
     * Handle pause command - pause and broadcast updated state.
     */
    private function handlePauseCommand(): void
    {
        $this->pause();
        $this->broadcastSnapshot();
    }

    /**
     * Handle resume command - resume and broadcast updated state.
     */
    private function handleResumeCommand(): void
    {
        $this->resume();
        $this->broadcastSnapshot();
    }

    /**
     * Handle set task review enabled command.
     */
    private function handleSetTaskReviewCommand(\App\Ipc\IpcMessage $message): void
    {
        // Cast to SetTaskReviewCommand to access enabled property
        if ($message instanceof \App\Ipc\Commands\SetTaskReviewCommand) {
            $this->taskReviewEnabled = $message->enabled;
        }
    }

    /**
     * Handle task start command - start and spawn a task.
     */
    private function handleTaskStartCommand(\App\Ipc\IpcMessage $message): void
    {
        // Cast to TaskStartCommand to access taskId and agentOverride properties
        if ($message instanceof \App\Ipc\Commands\TaskStartCommand) {
            try {
                $task = $this->taskService->find($message->taskId);
                if ($task) {
                    // Spawn the task through the existing trySpawnTask method
                    $this->trySpawnTask($task, $message->agentOverride);
                    $this->broadcastSnapshot();
                }
            } catch (\RuntimeException $e) {
                // Task not found or spawn failed - ignore
            }
        }
    }

    /**
     * Handle task reopen command - reopen a task.
     */
    private function handleTaskReopenCommand(\App\Ipc\IpcMessage $message): void
    {
        // Cast to TaskReopenCommand to access taskId property
        if ($message instanceof \App\Ipc\Commands\TaskReopenCommand) {
            try {
                $this->taskService->reopen($message->taskId);
                $this->invalidateTaskCache();
                $this->broadcastSnapshot();
            } catch (\RuntimeException $e) {
                // Task not found or cannot be reopened - ignore
            }
        }
    }

    /**
     * Handle task done command - mark a task as done.
     */
    private function handleTaskDoneCommand(\App\Ipc\IpcMessage $message): void
    {
        // Cast to TaskDoneCommand to access taskId and reason properties
        if ($message instanceof \App\Ipc\Commands\TaskDoneCommand) {
            try {
                // Use DoneCommand logic so future done enhancements apply automatically
                $params = [
                    'ids' => [$message->taskId],
                ];
                if (isset($message->reason)) {
                    $params['--reason'] = $message->reason;
                }
                if (isset($message->commitHash)) {
                    $params['--commit'] = $message->commitHash;
                }
                Artisan::call('done', $params);
                $this->invalidateTaskCache();
                $this->broadcastSnapshot();
            } catch (\RuntimeException $e) {
                // Task not found or cannot be marked done - ignore
            }
        }
    }

    /**
     * Handle task create command - create a new task.
     */
    private function handleTaskCreateCommand(\App\Ipc\IpcMessage $message): void
    {
        // Cast to TaskCreateCommand to access task properties
        if ($message instanceof \App\Ipc\Commands\TaskCreateCommand) {
            try {
                $taskData = [
                    'title' => $message->title,
                ];

                if (isset($message->description)) {
                    $taskData['description'] = $message->description;
                }
                if (isset($message->priority)) {
                    $taskData['priority'] = $message->priority;
                }
                if (isset($message->type)) {
                    $taskData['type'] = $message->type;
                }
                if (isset($message->labels)) {
                    $taskData['labels'] = $message->labels;
                }
                if (isset($message->complexity)) {
                    $taskData['complexity'] = $message->complexity;
                }
                if (isset($message->epicId)) {
                    $taskData['epic_id'] = $message->epicId;
                }
                if (isset($message->blockedBy)) {
                    $taskData['blocked_by'] = $message->blockedBy;
                }

                $this->taskService->create($taskData);
                $this->invalidateTaskCache();
                $this->broadcastSnapshot();
            } catch (\RuntimeException $e) {
                // Task creation failed - ignore
            }
        }
    }

    /**
     * Handle dependency add command - add a dependency between tasks.
     */
    private function handleDependencyAddCommand(\App\Ipc\IpcMessage $message): void
    {
        // Cast to DependencyAddCommand to access taskId and blockerTaskId properties
        if ($message instanceof \App\Ipc\Commands\DependencyAddCommand) {
            try {
                $this->taskService->addDependency($message->taskId, $message->blockerTaskId);
                $this->invalidateTaskCache();
                $this->broadcastSnapshot();
            } catch (\RuntimeException $e) {
                // Dependency add failed - ignore
            }
        }
    }

    /**
     * Write PID file with runner metadata.
     *
     * Format: {"pid": 12345, "started_at": "2024-01-13T12:34:56+00:00", "instance_id": "uuid", "port": 9981}
     */
    private function writePidFile(): void
    {
        $pidFile = $this->fuelContext->getPidFilePath();
        $port = $this->configService->getConsumePort();

        $pidData = [
            'pid' => getmypid(),
            'started_at' => $this->startedAt->format(DateTimeImmutable::ATOM),
            'instance_id' => $this->instanceId,
            'port' => $port,
        ];

        // Create directory if needed
        $dir = dirname($pidFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Write PID file
        file_put_contents($pidFile, json_encode($pidData, JSON_THROW_ON_ERROR));
        chmod($pidFile, 0600);
    }

    /**
     * Delete PID file.
     */
    private function deletePidFile(): void
    {
        $pidFile = $this->fuelContext->getPidFilePath();
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
    }

    /**
     * Check for stale PID file and delete if PID is dead.
     */
    private function cleanupStalePidFile(): void
    {
        $pidFile = $this->fuelContext->getPidFilePath();

        if (! file_exists($pidFile)) {
            return;
        }

        // Read PID file
        $content = file_get_contents($pidFile);
        if ($content === false) {
            return;
        }

        // Parse JSON
        $data = json_decode($content, true);
        if (! is_array($data) || ! isset($data['pid'])) {
            // Malformed PID file, delete it
            unlink($pidFile);

            return;
        }

        // Check if PID is alive
        $pid = (int) $data['pid'];
        if (! ProcessManager::isProcessAlive($pid)) {
            // PID is dead, delete stale PID file
            unlink($pidFile);
        }
    }

    /**
     * Try to spawn a task if agent capacity allows.
     * Returns true if spawned, false if at capacity or agent unavailable.
     */
    private function trySpawnTask(
        Task $task,
        ?string $agentOverride
    ): bool {
        // Don't spawn new tasks if shutting down
        if ($this->shuttingDown) {
            return false;
        }

        $taskId = $task->short_id;
        $taskTitle = $task->title;

        // Build structured prompt with task details
        $cwd = $this->fuelContext->getProjectPath();
        $fullPrompt = $this->promptBuilder->build($task, $cwd);

        // Determine agent name for capacity check
        $agentName = $agentOverride;
        if ($agentName === null) {
            $complexity = $task->complexity ?? 'simple';
            try {
                $agentName = $this->configService->getAgentForComplexity($complexity);
            } catch (\RuntimeException $e) {
                return false;
            }
        }

        // Check capacity before attempting to spawn
        if (! $this->processManager->canSpawn($agentName)) {
            return false; // At capacity, can't spawn
        }

        // Check agent health / backoff before attempting to spawn
        if ($this->healthTracker instanceof AgentHealthTrackerInterface && ! $this->healthTracker->isAvailable($agentName)) {
            return false; // Agent in backoff, don't spawn
        }

        // Check if agent is dead (exceeded max_retries consecutive failures)
        if ($this->healthTracker instanceof AgentHealthTrackerInterface) {
            $maxRetries = $this->configService->getAgentMaxRetries($agentName);
            if ($this->healthTracker->isDead($agentName, $maxRetries)) {
                return false; // Agent is dead, don't assign work
            }
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

        // Create run entry with runner instance ID
        $runId = $this->runService->createRun($taskId, [
            'agent' => $runAgentName,
            'model' => $runModel,
            'started_at' => date('c'),
            'runner_instance_id' => $this->instanceId,
        ]);

        // Spawn via ProcessManager with run ID
        $result = $this->processManager->spawnForTask($task, $fullPrompt, $cwd, $agentOverride, $runId);

        if (! $result->success) {
            // Agent in backoff or spawn failed
            if ($result->isInBackoff()) {
                $this->taskService->reopen($taskId);
                $this->invalidateTaskCache();

                return false;
            }

            // Revert task state
            $this->taskService->reopen($taskId);
            $this->invalidateTaskCache();

            return false;
        }

        $process = $result->process;
        $pid = $process->getPid();

        // Store the process PID in the run entry
        $this->runService->updateRun($runId, [
            'pid' => $pid,
        ]);

        // Store the process PID in the task
        $this->taskService->update($taskId, [
            'consume_pid' => $pid,
        ]);

        // Broadcast TaskSpawnedEvent to IPC clients
        $this->broadcastTaskSpawned($taskId, $runId, $runAgentName);

        return true;
    }

    /**
     * Poll all running processes and handle completions.
     */
    private function pollAndHandleCompletions(): void
    {
        // Update session_id in run service as processes are polled
        // Skip review processes as they don't have run entries
        foreach ($this->processManager->getActiveProcesses() as $process) {
            if ($process->getProcessType() === ProcessType::Review) {
                continue;
            }

            if ($process->getSessionId() !== null) {
                $this->updateLatestRunIfTaskExists($process->getTaskId(), [
                    'session_id' => $process->getSessionId(),
                ]);
            }
        }

        $completions = $this->processManager->poll();

        foreach ($completions as $completion) {
            $this->handleCompletion($completion);
        }
    }

    /**
     * Handle a completed process result.
     */
    private function handleCompletion(CompletionResult $completion): void
    {
        // Review completions are handled separately by checkCompletedReviews()
        if ($completion->isReview()) {
            return;
        }

        $taskId = $completion->taskId;
        $agentName = $completion->agentName;

        // Get run ID before updating
        $task = $this->taskService->find($taskId);
        $latestRun = $task ? $this->runService->getLatestRun($taskId) : null;
        $runId = $latestRun?->short_id;

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

        $this->updateLatestRunIfTaskExists($taskId, $runData);

        // Clear PID from task
        $this->taskService->update($taskId, [
            'consume_pid' => null,
        ]);

        // Broadcast TaskCompletedEvent to IPC clients
        $this->broadcastTaskCompleted($taskId, $runId, $completion->exitCode, $completion->type);

        // Clean up ring buffer for this task
        unset($this->outputRingBuffers[$taskId]);

        // Handle by completion type
        match ($completion->type) {
            CompletionType::Success => $this->handleSuccess($completion),
            CompletionType::Failed => $this->handleFailure($completion),
            CompletionType::NetworkError => $this->handleNetworkError($completion),
            CompletionType::PermissionBlocked => $this->handlePermissionBlocked($completion, $agentName),
        };

        $this->invalidateTaskCache();
    }

    /**
     * Handle successful completion.
     */
    private function handleSuccess(CompletionResult $completion): void
    {
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
            // Task was deleted
            return;
        }

        // Trigger review as quality gate if enabled
        // Track original status to handle already-done tasks correctly
        $originalStatus = $task->status;
        $wasAlreadyDone = $originalStatus === TaskStatus::Done;

        if ($this->taskReviewEnabled && $this->reviewService instanceof ReviewServiceInterface) {
            // Trigger review if ReviewService is available
            try {
                // Store original status before triggering review
                if ($wasAlreadyDone) {
                    $this->preReviewTaskStatus[$taskId] = $originalStatus;
                }

                $reviewTriggered = $this->reviewService->triggerReview($taskId, $completion->agentName);
                if (! $reviewTriggered) {
                    // No review agent configured - auto-complete with warning
                    $this->fallbackAutoComplete($taskId, true);
                }
            } catch (\RuntimeException) {
                // Review failed to trigger - fall back to auto-complete
                $this->fallbackAutoComplete($taskId, false);
            }
        } else {
            // No ReviewService - fall back to auto-complete
            $this->fallbackAutoComplete($taskId, false);
        }
    }

    /**
     * Fall back to auto-completing the task when review is not available.
     *
     * @param  bool  $noReviewAgent  Whether this is due to no review agent configured
     */
    private function fallbackAutoComplete(string $taskId, bool $noReviewAgent = false): void
    {
        // Add 'auto-closed' label to indicate it wasn't self-reported
        $this->taskService->update($taskId, [
            'add_labels' => ['auto-closed'],
        ]);

        // Use DoneCommand logic so future done enhancements apply automatically
        Artisan::call('done', [
            'ids' => [$taskId],
            '--reason' => 'Auto-completed by consume (agent exit 0)',
        ]);
    }

    /**
     * Handle failure completion.
     */
    private function handleFailure(CompletionResult $completion): void
    {
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
        }
        // Max retries reached or not retryable - task stays in_progress and won't be retried
    }

    /**
     * Handle network error completion.
     */
    private function handleNetworkError(CompletionResult $completion): void
    {
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
        }
        // Max retries reached - task stays in_progress and won't be retried
    }

    /**
     * Handle permission blocked completion.
     */
    private function handlePermissionBlocked(CompletionResult $completion, string $agentName): void
    {
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
    }

    /**
     * Check for completed reviews and process their results.
     */
    private function checkCompletedReviews(): void
    {
        if (! $this->reviewService instanceof ReviewServiceInterface) {
            return;
        }

        foreach ($this->reviewService->getPendingReviews() as $taskId) {
            if ($this->reviewService->isReviewComplete($taskId)) {
                // Get the review's original_status from ReviewService's tracking
                // Note: We need to get this before getReviewResult() which removes from pendingReviews
                $pendingReviewData = $this->reviewService->getPendingReviewData($taskId);
                $reviewId = $pendingReviewData['reviewId'] ?? null;
                $originalStatus = null;
                $wasAlreadyDone = false;

                if ($reviewId !== null) {
                    // Get the Review model to access original_status
                    $review = \App\Models\Review::where('short_id', $reviewId)->first();
                    $originalStatus = $review?->original_status;
                    $wasAlreadyDone = $originalStatus === TaskStatus::Done->value;
                }

                $result = $this->reviewService->getReviewResult($taskId);
                if (! $result instanceof ReviewResult) {
                    continue;
                }

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
                    } else {
                        // Task was in_progress - mark as done
                        Artisan::call('done', [
                            'ids' => [$taskId],
                            '--reason' => 'Review passed',
                        ]);
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
                        } catch (\RuntimeException $e) {
                            // Could not reopen - task may have been deleted or modified
                        }
                    } else {
                        // Task needs to be reopened so it can be retried
                        try {
                            $this->taskService->reopen($taskId);
                        } catch (\RuntimeException) {
                            // Could not reopen
                        }
                    }
                }

                // Broadcast ReviewCompletedEvent to IPC clients
                $this->broadcastReviewCompleted($taskId, $result->passed, $result->issues, $wasAlreadyDone);

                $this->invalidateTaskCache();
            }
        }
    }

    /**
     * Update the latest run for a task, skipping if the task no longer exists.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateLatestRunIfTaskExists(string $taskId, array $data): void
    {
        if (! $this->taskService->find($taskId) instanceof Task) {
            return;
        }

        $this->runService->updateLatestRun($taskId, $data);
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
     * Broadcast HelloEvent and SnapshotEvent to all connected clients.
     * Called when new clients connect.
     */
    private function broadcastHelloAndSnapshot(): void
    {
        // Send HelloEvent
        $helloEvent = new \App\Ipc\Events\HelloEvent(
            version: '1.0.0', // TODO: Get from app version
            instanceId: $this->instanceId
        );
        $this->ipcServer->broadcast($helloEvent);

        // Send SnapshotEvent with full board state
        try {
            $snapshot = $this->buildSnapshot();
            $snapshotEvent = new \App\Ipc\Events\SnapshotEvent(
                snapshot: $snapshot,
                instanceId: $this->instanceId
            );
            $this->ipcServer->broadcast($snapshotEvent);

            // Update hash for change detection
            $this->lastSnapshotHash = $this->hashSnapshot($snapshot);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Send SnapshotEvent to a specific client.
     */
    private function sendSnapshot(string $clientId): void
    {
        $snapshot = $this->buildSnapshot();
        $snapshotEvent = new \App\Ipc\Events\SnapshotEvent(
            snapshot: $snapshot,
            instanceId: $this->instanceId
        );
        $this->ipcServer->sendTo($clientId, $snapshotEvent);
    }

    /**
     * Broadcast SnapshotEvent to all connected clients.
     * Called after state changes (pause/resume).
     */
    private function broadcastSnapshot(): void
    {
        $snapshot = $this->buildSnapshot();
        $snapshotEvent = new \App\Ipc\Events\SnapshotEvent(
            snapshot: $snapshot,
            instanceId: $this->instanceId
        );
        $this->ipcServer->broadcast($snapshotEvent);

        // Update hash for change detection
        $this->lastSnapshotHash = $this->hashSnapshot($snapshot);
    }

    /**
     * Broadcast SnapshotEvent only if data has changed since last broadcast.
     * Used for periodic polling to detect external changes.
     */
    private function broadcastSnapshotIfChanged(): void
    {
        $snapshot = $this->buildSnapshot();
        $hash = $this->hashSnapshot($snapshot);

        if ($hash === $this->lastSnapshotHash) {
            return; // No changes, skip broadcast
        }

        $snapshotEvent = new \App\Ipc\Events\SnapshotEvent(
            snapshot: $snapshot,
            instanceId: $this->instanceId
        );
        $this->ipcServer->broadcast($snapshotEvent);
        $this->lastSnapshotHash = $hash;
    }

    /**
     * Generate a hash of the snapshot for change detection.
     * Excludes volatile fields like timestamps.
     */
    private function hashSnapshot(ConsumeSnapshot $snapshot): string
    {
        // Hash the board state task IDs and statuses (not full task data which may have volatile timestamps)
        $hashData = [
            'board' => [],
            'active' => array_keys($snapshot->activeProcesses),
            'paused' => $snapshot->runnerState['paused'] ?? false,
        ];

        foreach ($snapshot->boardState as $status => $tasks) {
            $hashData['board'][$status] = $tasks->pluck('short_id')->sort()->values()->toArray();
        }

        return md5(json_encode($hashData, JSON_THROW_ON_ERROR));
    }

    /**
     * Build a ConsumeSnapshot from current runner state.
     */
    private function buildSnapshot(): ConsumeSnapshot
    {
        // Get board data from task service
        $allTasks = $this->taskService->all();
        $boardData = [
            'ready' => $this->taskService->ready(),
            'in_progress' => $allTasks->filter(fn ($t) => $t->status === \App\Enums\TaskStatus::InProgress),
            'review' => $allTasks->filter(fn ($t) => $t->status === \App\Enums\TaskStatus::Review)
                ->sortByDesc('updated_at')
                ->values(),
            'blocked' => $this->taskService->blocked(),
            'human' => $allTasks->filter(fn ($t) => $t->status === \App\Enums\TaskStatus::Open && is_array($t->labels) && in_array('needs-human', $t->labels, true)),
            'done' => $allTasks->filter(fn ($t) => $t->status === \App\Enums\TaskStatus::Done)
                ->sortByDesc('updated_at')
                ->values(),
        ];

        // Get active processes
        $activeProcesses = $this->processManager->getActiveProcesses();

        // Get health statuses
        $healthStatuses = [];
        if ($this->healthTracker instanceof AgentHealthTrackerInterface) {
            $healthStatuses = $this->healthTracker->getAllHealthStatus();
        }

        // Get agent limits from config
        $agentLimits = $this->configService->getAgentLimits();

        // Get all epics referenced by tasks (for display)
        $epicIds = $allTasks->pluck('epic_id')->filter()->unique()->values()->toArray();
        $epics = $epicIds !== [] ? \App\Models\Epic::whereIn('id', $epicIds)->get()->all() : [];

        return ConsumeSnapshot::fromBoardData(
            boardData: $boardData,
            activeProcesses: $activeProcesses,
            healthStatuses: $healthStatuses,
            paused: $this->paused,
            startedAt: $this->startedAt->getTimestamp(),
            instanceId: $this->instanceId,
            intervalSeconds: 5, // Default interval
            agentLimits: $agentLimits,
            epics: $epics
        );
    }

    /**
     * Broadcast TaskSpawnedEvent to all connected clients.
     */
    private function broadcastTaskSpawned(string $taskId, string $runId, string $agent): void
    {
        $event = new \App\Ipc\Events\TaskSpawnedEvent(
            taskId: $taskId,
            runId: $runId,
            agent: $agent,
            instanceId: $this->instanceId
        );
        $this->ipcServer->broadcast($event);
    }

    /**
     * Broadcast TaskCompletedEvent to all connected clients.
     */
    private function broadcastTaskCompleted(string $taskId, ?string $runId, int $exitCode, CompletionType $completionType): void
    {
        // Map CompletionType to string for IPC
        $typeString = match ($completionType) {
            CompletionType::Success => 'success',
            CompletionType::Failed => 'failed',
            CompletionType::NetworkError => 'network_error',
            CompletionType::PermissionBlocked => 'permission_blocked',
        };

        $event = new \App\Ipc\Events\TaskCompletedEvent(
            taskId: $taskId,
            runId: $runId ?? '',
            exitCode: $exitCode,
            completionType: $typeString,
            instanceId: $this->instanceId
        );
        $this->ipcServer->broadcast($event);
    }

    /**
     * Broadcast ReviewCompletedEvent to all connected clients.
     */
    private function broadcastReviewCompleted(string $taskId, bool $passed, array $issues, bool $wasAlreadyDone): void
    {
        $event = new \App\Ipc\Events\ReviewCompletedEvent(
            taskId: $taskId,
            passed: $passed,
            issues: $issues,
            wasAlreadyDone: $wasAlreadyDone,
            instanceId: $this->instanceId
        );
        $this->ipcServer->broadcast($event);
    }

    /**
     * Check for health changes and broadcast HealthChangeEvents.
     */
    private function checkHealthChanges(): void
    {
        if (! $this->healthTracker instanceof AgentHealthTrackerInterface) {
            return;
        }

        $allHealth = $this->healthTracker->getAllHealthStatus();

        foreach ($allHealth as $health) {
            $agent = $health->agent;
            $currentStatus = $health->getStatus();

            // Check if status changed
            $previousStatus = $this->previousHealthStatus[$agent] ?? null;
            if ($previousStatus !== $currentStatus) {
                // Broadcast HealthChangeEvent
                $event = new \App\Ipc\Events\HealthChangeEvent(
                    agent: $agent,
                    status: $currentStatus,
                    instanceId: $this->instanceId
                );
                $this->ipcServer->broadcast($event);

                // Update previous status
                $this->previousHealthStatus[$agent] = $currentStatus;
            }
        }
    }

    /**
     * Handle output chunk from ProcessManager callback.
     * Adds to ring buffer and broadcasts to IPC clients (best-effort).
     */
    private function handleOutputChunk(string $taskId, string $stream, string $chunk): void
    {
        // Add to ring buffer (keep last 4KB per task)
        if (! isset($this->outputRingBuffers[$taskId])) {
            $this->outputRingBuffers[$taskId] = '';
        }

        $this->outputRingBuffers[$taskId] .= $chunk;

        // Trim to ring buffer size
        if (strlen($this->outputRingBuffers[$taskId]) > self::RING_BUFFER_SIZE) {
            $this->outputRingBuffers[$taskId] = substr($this->outputRingBuffers[$taskId], -self::RING_BUFFER_SIZE);
        }

        // Get run ID for this task
        $runId = null;
        foreach ($this->processManager->getActiveProcesses() as $process) {
            if ($process->getTaskId() === $taskId) {
                $runId = $process->getRunId();
                break;
            }
        }

        // Broadcast OutputChunkEvent (best-effort, clients may drop if slow)
        $event = new \App\Ipc\Events\OutputChunkEvent(
            taskId: $taskId,
            runId: $runId ?? '',
            stream: $stream,
            chunk: $chunk,
            instanceId: $this->instanceId
        );
        $this->ipcServer->broadcast($event);
    }

    /**
     * Handle StopCommand from IPC client.
     */
    private function handleStopCommand(\App\Ipc\IpcMessage $message): void
    {
        // Cast to StopCommand to access mode property
        if ($message instanceof \App\Ipc\Commands\StopCommand) {
            $graceful = $message->mode === 'graceful';
            $this->stop($graceful);
        } else {
            // Default to graceful stop
            $this->stop(true);
        }
    }
}
