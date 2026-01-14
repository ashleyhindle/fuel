<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AgentHealthTrackerInterface;
use App\Daemon\CompletionHandler;
use App\Daemon\DaemonLoop;
use App\Daemon\IpcCommandDispatcher;
use App\Daemon\LifecycleManager;
use App\Daemon\ReviewManager;
use App\Daemon\SnapshotManager;
use App\Daemon\TaskSpawner;
use App\DTO\ConsumeSnapshot;

/**
 * Thin wrapper that manages daemon lifecycle.
 *
 * Responsibilities:
 * - Start/stop daemon components
 * - Manage lifecycle (PID file, IPC server, signal handlers)
 * - Delegate main loop execution to DaemonLoop
 * - Provide pause/resume/status methods
 */
final class ConsumeRunner
{
    private ?DaemonLoop $daemonLoop = null;

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
        private readonly LifecycleManager $lifecycleManager,
        private readonly TaskSpawner $taskSpawner,
        private readonly CompletionHandler $completionHandler,
        private readonly IpcCommandDispatcher $ipcCommandDispatcher,
        private readonly SnapshotManager $snapshotManager,
        private readonly ?ReviewManager $reviewManager = null,
        private readonly ?AgentHealthTrackerInterface $healthTracker = null,
    ) {}

    /**
     * Start the runner.
     *
     * - Check for stale PID file and delete if PID is dead
     * - Write new PID file
     * - Start IPC server
     * - Register signal handlers via ProcessManager
     * - Register output callback for IPC broadcasting
     * - Create DaemonLoop and run
     * - Cleanup after main loop exits
     *
     * @param  bool  $taskReviewEnabled  Whether to enable automatic task reviews
     */
    public function start(bool $taskReviewEnabled = false): void
    {
        $this->completionHandler->setTaskReviewEnabled($taskReviewEnabled);

        // Start lifecycle manager (checks stale PID, writes PID file)
        $port = $this->configService->getConsumePort();
        $this->lifecycleManager->start($port);

        // Set TaskSpawner's instance ID to match the runner's instance ID
        $this->taskSpawner->setInstanceId($this->lifecycleManager->getInstanceId());

        // Start IPC server
        $this->ipcServer->start();

        // Register signal handlers via ProcessManager
        $this->processManager->registerSignalHandlers();

        // Register output callback for broadcasting output chunks to IPC clients
        $this->processManager->setOutputCallback(function (string $taskId, string $stream, string $chunk): void {
            $this->snapshotManager->handleOutputChunk($taskId, $stream, $chunk);
        });

        // Create and run DaemonLoop
        $this->daemonLoop = new DaemonLoop(
            lifecycleManager: $this->lifecycleManager,
            taskSpawner: $this->taskSpawner,
            completionHandler: $this->completionHandler,
            reviewManager: $this->reviewManager,
            ipcCommandDispatcher: $this->ipcCommandDispatcher,
            snapshotManager: $this->snapshotManager,
            ipcServer: $this->ipcServer,
            processManager: $this->processManager,
            taskService: $this->taskService,
            runService: $this->runService,
        );
        $this->daemonLoop->run();

        // Cleanup after main loop exits (only if stop() was explicitly called or ProcessManager signaled shutdown)
        // Skip cleanup if testing flag is set or if loop exited for other reasons
        if ($this->lifecycleManager->isShuttingDown() && $this->lifecycleManager->isStopCalled() && ! $this->lifecycleManager->isSkipCleanup()) {
            $this->cleanup();
        }
    }

    /**
     * Set whether to skip cleanup on shutdown (for testing).
     */
    public function setSkipCleanup(bool $skip): void
    {
        $this->lifecycleManager->setSkipCleanup($skip);
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
        $this->lifecycleManager->stop($graceful);

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
        if ($this->lifecycleManager->isGracefulShutdown()) {
            $this->processManager->shutdown();
        }

        // Stop IPC server
        $this->ipcServer->stop();

        // Clean up lifecycle (deletes PID file)
        $this->lifecycleManager->cleanup();
    }

    /**
     * Pause the runner (stop accepting new tasks).
     */
    public function pause(): void
    {
        $this->lifecycleManager->pause();
    }

    /**
     * Resume the runner (start accepting new tasks).
     */
    public function resume(): void
    {
        $this->lifecycleManager->resume();
    }

    /**
     * Check if runner is paused.
     */
    public function isPaused(): bool
    {
        return $this->lifecycleManager->isPaused();
    }

    /**
     * Check if runner is shutting down.
     */
    public function isShuttingDown(): bool
    {
        return $this->lifecycleManager->isShuttingDown();
    }

    /**
     * Get the unique instance identifier.
     */
    public function getInstanceId(): string
    {
        return $this->lifecycleManager->getInstanceId();
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
                'paused' => $this->lifecycleManager->isPaused(),
                'started_at' => $this->lifecycleManager->getStartedAt()->getTimestamp(),
                'instance_id' => $this->lifecycleManager->getInstanceId(),
            ],
            config: [
                'interval_seconds' => 5, // Default interval
                'agents' => [],
            ]
        );
    }
}
