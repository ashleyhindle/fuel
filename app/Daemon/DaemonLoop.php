<?php

declare(strict_types=1);

namespace App\Daemon;

use App\Daemon\Helpers\CommandHandlers;
use App\Ipc\IpcMessage;
use App\Models\Task;
use App\Services\ConsumeIpcServer;
use App\Services\ProcessManager;
use App\Services\RunService;
use App\Services\TaskService;

/**
 * Thin orchestrator for the daemon components.
 *
 * Responsibilities:
 * - Compose daemon components together
 * - Run main loop and tick logic
 * - Coordinate component interactions
 * - Handle IPC command callbacks
 */
final readonly class DaemonLoop
{
    private CommandHandlers $commandHandlers;

    public function __construct(
        private LifecycleManager $lifecycleManager,
        private TaskSpawner $taskSpawner,
        private CompletionHandler $completionHandler,
        private ?ReviewManager $reviewManager,
        private IpcCommandDispatcher $ipcCommandDispatcher,
        private SnapshotManager $snapshotManager,
        private ConsumeIpcServer $ipcServer,
        private ProcessManager $processManager,
        private TaskService $taskService,
        private RunService $runService,
        private BrowserCommandHandler $browserCommandHandler,
    ) {
        $this->commandHandlers = app(CommandHandlers::class);
    }

    /**
     * Run the main daemon loop.
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
    public function run(): void
    {
        while (! $this->lifecycleManager->isShuttingDown()) {
            // Accept new client connections
            $clientCount = $this->ipcServer->getClientCount();
            $this->ipcServer->accept();

            // If new clients connected, send them HelloEvent and SnapshotEvent
            if ($this->ipcServer->getClientCount() > $clientCount) {
                $this->snapshotManager->broadcastHelloAndSnapshot();
            }

            // Poll IPC commands from clients
            $this->pollIpcCommands();

            // Check for shutdown signal (handled by ProcessManager)
            if ($this->processManager->isShuttingDown()) {
                $this->lifecycleManager->stop(true); // Treat signal as equivalent to stop() call
                break;
            }

            // Check for health changes and broadcast events
            $this->snapshotManager->checkHealthChanges();

            // Run tick to handle spawning, completions, and reviews
            $this->tick();

            // Periodically refresh snapshot cache (every 2 seconds)
            // This ensures new clients get fresh-ish data without waiting for a full rebuild
            if ($this->snapshotManager->shouldBroadcastSnapshot()) {
                $this->snapshotManager->refreshCache();
                // Only broadcast if clients are connected
                if ($this->ipcServer->getClientCount() > 0) {
                    $this->snapshotManager->broadcastSnapshotIfChanged();
                }
            }

            // Sleep for interval (60ms for responsiveness)
            usleep(60000);
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
        if (! $this->lifecycleManager->isPaused() && ! $this->lifecycleManager->isShuttingDown()) {
            // Update TaskSpawner's shutting down state
            $this->taskSpawner->setShuttingDown($this->lifecycleManager->isShuttingDown());

            $readyTasks = $this->taskSpawner->getCachedReadyTasks();

            if ($readyTasks->isNotEmpty()) {
                // Sort tasks by priority then creation date (FIFO within priority)
                $sortedTasks = $readyTasks->sortBy([
                    ['priority', 'asc'],
                    ['created_at', 'asc'],
                ])->values();

                // Try to spawn tasks until we can't spawn any more
                foreach ($sortedTasks as $task) {
                    $this->taskSpawner->trySpawnTask($task, null, function (string $taskId, string $runId, string $agentName): void {
                        // Broadcast TaskSpawnedEvent when task is spawned
                        $this->snapshotManager->broadcastTaskSpawned($taskId, $runId, $agentName);
                    });
                }
            }
        }

        // Step 2: Poll all running processes and handle completions
        $completions = $this->completionHandler->pollAndHandleCompletions();

        // Broadcast completion events and invalidate cache for each handled completion
        foreach ($completions as $completion) {
            if (! $completion->isReview()) {
                $task = $this->taskService->find($completion->taskId);
                $latestRun = $task instanceof Task ? $this->runService->getLatestRun($completion->taskId) : null;
                $runId = $latestRun?->short_id;

                // Broadcast TaskCompletedEvent to IPC clients
                $this->snapshotManager->broadcastTaskCompleted($completion->taskId, $runId, $completion->exitCode, $completion->type);

                // Clean up ring buffer for this task
                $this->snapshotManager->cleanupTaskBuffer($completion->taskId);

                // Invalidate task cache so spawner sees new ready tasks
                $this->taskSpawner->invalidateTaskCache();
            }
        }

        // Step 3: Check for completed reviews
        if ($this->reviewManager instanceof ReviewManager) {
            $this->reviewManager->checkCompletedReviews();
        }
    }

    /**
     * Poll IPC commands from connected clients and handle them.
     */
    private function pollIpcCommands(): void
    {
        $this->ipcCommandDispatcher->pollIpcCommands(
            onPause: fn () => $this->snapshotManager->broadcastSnapshot(),
            onResume: fn () => $this->snapshotManager->broadcastSnapshot(),
            onStop: fn (bool $graceful) => $this->commandHandlers->handleStop($graceful),
            onSnapshot: fn (string $clientId) => $this->snapshotManager->sendSnapshot($clientId),
            onTaskStart: fn (IpcMessage $message) => $this->commandHandlers->handleTaskStart($message),
            onTaskReopen: fn (IpcMessage $message) => $this->commandHandlers->handleTaskReopen($message),
            onTaskDone: fn (IpcMessage $message) => $this->commandHandlers->handleTaskDone($message),
            onTaskCreate: fn (IpcMessage $message) => $this->commandHandlers->handleTaskCreate($message, $this->ipcServer),
            onDependencyAdd: fn (IpcMessage $message) => $this->commandHandlers->handleDependencyAdd($message),
            onReloadConfig: fn () => $this->snapshotManager->broadcastConfigReloaded(),
            onBrowserCreate: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserCreate($message),
            onBrowserPage: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserPage($message),
            onBrowserGoto: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserGoto($message),
            onBrowserRun: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserRun($message),
            onBrowserScreenshot: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserScreenshot($message),
            onBrowserClose: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserClose($message),
            onBrowserStatus: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserStatus($message),
        );
    }
}
