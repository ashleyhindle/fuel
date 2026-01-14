<?php

declare(strict_types=1);

namespace App\Daemon;

use App\Ipc\IpcMessage;
use App\Services\ConsumeIpcServer;

/**
 * Handles IPC command dispatching for the daemon.
 *
 * Responsibilities:
 * - Poll IPC commands from connected clients
 * - Route commands to appropriate handlers
 * - Handle pause/resume commands
 * - Handle set task review command
 * - Handle stop command
 */
final class IpcCommandDispatcher
{
    public function __construct(
        private readonly ConsumeIpcServer $ipcServer,
        private readonly LifecycleManager $lifecycleManager,
        private readonly CompletionHandler $completionHandler,
    ) {}

    /**
     * Poll IPC commands from connected clients and handle them.
     *
     * @param  callable  $onPause  Callback when pause command is received
     * @param  callable  $onResume  Callback when resume command is received
     * @param  callable  $onStop  Callback when stop command is received
     * @param  callable  $onSnapshot  Callback when snapshot is requested
     * @param  callable  $onTaskStart  Callback when task start command is received
     * @param  callable  $onTaskReopen  Callback when task reopen command is received
     * @param  callable  $onTaskDone  Callback when task done command is received
     * @param  callable  $onTaskCreate  Callback when task create command is received
     * @param  callable  $onDependencyAdd  Callback when dependency add command is received
     */
    public function pollIpcCommands(
        callable $onPause,
        callable $onResume,
        callable $onStop,
        callable $onSnapshot,
        callable $onTaskStart,
        callable $onTaskReopen,
        callable $onTaskDone,
        callable $onTaskCreate,
        callable $onDependencyAdd,
    ): void {
        $commands = $this->ipcServer->poll();

        foreach ($commands as $clientId => $messages) {
            foreach ($messages as $message) {
                $this->handleIpcCommand(
                    clientId: $clientId,
                    message: $message,
                    onPause: $onPause,
                    onResume: $onResume,
                    onStop: $onStop,
                    onSnapshot: $onSnapshot,
                    onTaskStart: $onTaskStart,
                    onTaskReopen: $onTaskReopen,
                    onTaskDone: $onTaskDone,
                    onTaskCreate: $onTaskCreate,
                    onDependencyAdd: $onDependencyAdd,
                );
            }
        }
    }

    /**
     * Handle a single IPC command from a client.
     *
     * @param  string  $clientId  The client ID that sent the command
     * @param  IpcMessage  $message  The decoded IPC message
     * @param  callable  $onPause  Callback when pause command is received
     * @param  callable  $onResume  Callback when resume command is received
     * @param  callable  $onStop  Callback when stop command is received
     * @param  callable  $onSnapshot  Callback when snapshot is requested
     * @param  callable  $onTaskStart  Callback when task start command is received
     * @param  callable  $onTaskReopen  Callback when task reopen command is received
     * @param  callable  $onTaskDone  Callback when task done command is received
     * @param  callable  $onTaskCreate  Callback when task create command is received
     * @param  callable  $onDependencyAdd  Callback when dependency add command is received
     */
    private function handleIpcCommand(
        string $clientId,
        IpcMessage $message,
        callable $onPause,
        callable $onResume,
        callable $onStop,
        callable $onSnapshot,
        callable $onTaskStart,
        callable $onTaskReopen,
        callable $onTaskDone,
        callable $onTaskCreate,
        callable $onDependencyAdd,
    ): void {
        // Handle commands based on message type
        match ($message->type()) {
            'pause' => $this->handlePauseCommand($onPause),
            'resume' => $this->handleResumeCommand($onResume),
            'stop' => $this->handleStopCommand($message, $onStop),
            'request_snapshot' => $onSnapshot($clientId),
            'set_task_review_enabled' => $this->handleSetTaskReviewCommand($message),
            // Task mutation commands
            'task_start' => $onTaskStart($message),
            'task_reopen' => $onTaskReopen($message),
            'task_done' => $onTaskDone($message),
            'task_create' => $onTaskCreate($message),
            'dependency_add' => $onDependencyAdd($message),
            // Other commands (attach, detach, reload_config, set_interval) are handled in future tasks
            default => null,
        };
    }

    /**
     * Handle pause command - pause the lifecycle and invoke callback.
     *
     * @param  callable  $onPause  Callback to invoke after pausing
     */
    private function handlePauseCommand(callable $onPause): void
    {
        $this->lifecycleManager->pause();
        $onPause();
    }

    /**
     * Handle resume command - resume the lifecycle and invoke callback.
     *
     * @param  callable  $onResume  Callback to invoke after resuming
     */
    private function handleResumeCommand(callable $onResume): void
    {
        $this->lifecycleManager->resume();
        $onResume();
    }

    /**
     * Handle set task review enabled command.
     *
     * @param  IpcMessage  $message  The SetTaskReviewCommand message
     */
    private function handleSetTaskReviewCommand(IpcMessage $message): void
    {
        // Cast to SetTaskReviewCommand to access enabled property
        if ($message instanceof \App\Ipc\Commands\SetTaskReviewCommand) {
            $this->completionHandler->setTaskReviewEnabled($message->enabled);
        }
    }

    /**
     * Handle StopCommand from IPC client.
     *
     * @param  IpcMessage  $message  The StopCommand message
     * @param  callable  $onStop  Callback to invoke for stopping
     */
    private function handleStopCommand(IpcMessage $message, callable $onStop): void
    {
        // Cast to StopCommand to access mode property
        if ($message instanceof \App\Ipc\Commands\StopCommand) {
            $graceful = $message->mode === 'graceful';
            $onStop($graceful);
        } else {
            // Default to graceful stop
            $onStop(true);
        }
    }
}
