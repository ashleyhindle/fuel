<?php

declare(strict_types=1);

namespace App\Daemon;

use App\Daemon\Helpers\CommandHandlers;
use App\Ipc\IpcMessage;
use App\Models\Task;
use App\Services\ConfigService;
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
final class DaemonLoop
{
    private readonly CommandHandlers $commandHandlers;

    private int $lastConfigReload;

    public function __construct(
        private readonly LifecycleManager $lifecycleManager,
        private readonly TaskSpawner $taskSpawner,
        private readonly CompletionHandler $completionHandler,
        private readonly ?ReviewManager $reviewManager,
        private readonly IpcCommandDispatcher $ipcCommandDispatcher,
        private readonly SnapshotManager $snapshotManager,
        private readonly ConsumeIpcServer $ipcServer,
        private readonly ProcessManager $processManager,
        private readonly TaskService $taskService,
        private readonly RunService $runService,
        private readonly BrowserCommandHandler $browserCommandHandler,
        private readonly ConfigService $configService,
    ) {
        $this->commandHandlers = app(CommandHandlers::class);
        $this->lastConfigReload = time();
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
            try {
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

                // Periodically check for changes and broadcast (every 2 seconds)
                // This detects external changes (e.g., tasks added via `fuel add`)
                if ($this->snapshotManager->shouldBroadcastSnapshot() && $this->ipcServer->getClientCount() > 0) {
                    $this->snapshotManager->broadcastSnapshotIfChanged();
                }

                // Periodically reload config (every 10 seconds)
                if ($this->shouldReloadConfig()) {
                    $this->configService->reload();
                    $this->snapshotManager->broadcastConfigReloaded();
                }

                // Sleep for interval (60ms for responsiveness)
                usleep(60000);
            } catch (\Throwable $e) {
                $this->logException($e);

                // Dev: crash visibly so issues are noticed. Packaged: log and continue
                if (\Phar::running(false) === '') {
                    throw $e;
                }

                // Packaged binary: continue loop, daemon stays alive
            }
        }
    }

    /**
     * Log exception to .fuel/debug.log for post-mortem analysis.
     */
    private function logException(\Throwable $e): void
    {
        $logPath = getcwd().'/.fuel/debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $message = sprintf(
            "[%s] EXCEPTION: %s\n  File: %s:%d\n  Trace:\n%s\n",
            $timestamp,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        @file_put_contents($logPath, $message, FILE_APPEND);
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
            onBrowserSnapshot: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserSnapshot($message),
            onBrowserClick: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserClick($message),
            onBrowserFill: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserFill($message),
            onBrowserType: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserType($message),
            onBrowserText: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserText($message),
            onBrowserHtml: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserHtml($message),
            onBrowserWait: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserWait($message),
            onBrowserClose: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserClose($message),
            onBrowserStatus: fn (IpcMessage $message) => $this->browserCommandHandler->handleBrowserStatus($message),
            onRequestDoneTasks: fn (string $clientId) => $this->commandHandlers->handleRequestDoneTasks($clientId, $this->ipcServer),
            onRequestBlockedTasks: fn (string $clientId) => $this->commandHandlers->handleRequestBlockedTasks($clientId, $this->ipcServer),
            onRequestCompletedTasks: fn (string $clientId) => $this->commandHandlers->handleRequestCompletedTasks($clientId, $this->ipcServer),
        );
    }

    /**
     * Check if config should be reloaded (every 10 seconds).
     */
    private function shouldReloadConfig(): bool
    {
        $now = time();
        if (($now - $this->lastConfigReload) >= 10) {
            $this->lastConfigReload = $now;

            return true;
        }

        return false;
    }
}
