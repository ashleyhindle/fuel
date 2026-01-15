<?php

declare(strict_types=1);

namespace App\Daemon;

use App\Ipc\Commands\SetTaskReviewCommand;
use App\Ipc\Commands\StopCommand;
use App\Ipc\IpcMessage;
use App\Services\ConfigService;
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
 * - Handle config reload command
 */
final readonly class IpcCommandDispatcher
{
    public function __construct(
        private ConsumeIpcServer $ipcServer,
        private LifecycleManager $lifecycleManager,
        private CompletionHandler $completionHandler,
        private ConfigService $configService,
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
     * @param  callable  $onReloadConfig  Callback when config reload command is received
     * @param  callable  $onBrowserCreate  Callback when browser create command is received
     * @param  callable  $onBrowserPage  Callback when browser page command is received
     * @param  callable  $onBrowserGoto  Callback when browser goto command is received
     * @param  callable  $onBrowserRun  Callback when browser run command is received
     * @param  callable  $onBrowserScreenshot  Callback when browser screenshot command is received
     * @param  callable  $onBrowserSnapshot  Callback when browser snapshot command is received
     * @param  callable  $onBrowserClose  Callback when browser close command is received
     * @param  callable  $onBrowserStatus  Callback when browser status command is received
     * @param  callable  $onRequestDoneTasks  Callback when done tasks are requested
     * @param  callable  $onRequestBlockedTasks  Callback when blocked tasks are requested
     * @param  callable  $onRequestCompletedTasks  Callback when completed tasks are requested
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
        callable $onReloadConfig,
        callable $onBrowserCreate,
        callable $onBrowserPage,
        callable $onBrowserGoto,
        callable $onBrowserRun,
        callable $onBrowserScreenshot,
        callable $onBrowserSnapshot,
        callable $onBrowserClick,
        callable $onBrowserFill,
        callable $onBrowserType,
        callable $onBrowserText,
        callable $onBrowserHtml,
        callable $onBrowserWait,
        callable $onBrowserClose,
        callable $onBrowserStatus,
        callable $onRequestDoneTasks,
        callable $onRequestBlockedTasks,
        callable $onRequestCompletedTasks,
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
                    onReloadConfig: $onReloadConfig,
                    onBrowserCreate: $onBrowserCreate,
                    onBrowserPage: $onBrowserPage,
                    onBrowserGoto: $onBrowserGoto,
                    onBrowserRun: $onBrowserRun,
                    onBrowserScreenshot: $onBrowserScreenshot,
                    onBrowserSnapshot: $onBrowserSnapshot,
                    onBrowserClick: $onBrowserClick,
                    onBrowserFill: $onBrowserFill,
                    onBrowserType: $onBrowserType,
                    onBrowserText: $onBrowserText,
                    onBrowserHtml: $onBrowserHtml,
                    onBrowserClose: $onBrowserClose,
                    onBrowserStatus: $onBrowserStatus,
                    onRequestDoneTasks: $onRequestDoneTasks,
                    onRequestBlockedTasks: $onRequestBlockedTasks,
                    onRequestCompletedTasks: $onRequestCompletedTasks,
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
     * @param  callable  $onReloadConfig  Callback when config reload command is received
     * @param  callable  $onBrowserCreate  Callback when browser create command is received
     * @param  callable  $onBrowserPage  Callback when browser page command is received
     * @param  callable  $onBrowserGoto  Callback when browser goto command is received
     * @param  callable  $onBrowserRun  Callback when browser run command is received
     * @param  callable  $onBrowserScreenshot  Callback when browser screenshot command is received
     * @param  callable  $onBrowserSnapshot  Callback when browser snapshot command is received
     * @param  callable  $onBrowserClose  Callback when browser close command is received
     * @param  callable  $onBrowserStatus  Callback when browser status command is received
     * @param  callable  $onRequestDoneTasks  Callback when done tasks are requested
     * @param  callable  $onRequestBlockedTasks  Callback when blocked tasks are requested
     * @param  callable  $onRequestCompletedTasks  Callback when completed tasks are requested
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
        callable $onReloadConfig,
        callable $onBrowserCreate,
        callable $onBrowserPage,
        callable $onBrowserGoto,
        callable $onBrowserRun,
        callable $onBrowserScreenshot,
        callable $onBrowserSnapshot,
        callable $onBrowserClick,
        callable $onBrowserFill,
        callable $onBrowserType,
        callable $onBrowserText,
        callable $onBrowserHtml,
        callable $onBrowserWait,
        callable $onBrowserClose,
        callable $onBrowserStatus,
        callable $onRequestDoneTasks,
        callable $onRequestBlockedTasks,
        callable $onRequestCompletedTasks,
    ): void {
        // Debug: Log incoming command
        @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
            "[%s] IpcCommandDispatcher received: type=%s, class=%s\n",
            date('H:i:s'),
            $message->type(),
            $message::class
        ), FILE_APPEND);

        // Handle commands based on message type
        match ($message->type()) {
            'pause' => $this->handlePauseCommand($onPause),
            'resume' => $this->handleResumeCommand($onResume),
            'stop' => $this->handleStopCommand($message, $onStop),
            'request_snapshot' => $onSnapshot($clientId),
            'set_task_review_enabled' => $this->handleSetTaskReviewCommand($message),
            'reload_config' => $this->handleReloadConfigCommand($onReloadConfig),
            // Task mutation commands
            'task_start' => $onTaskStart($message),
            'task_reopen' => $onTaskReopen($message),
            'task_done' => $onTaskDone($message),
            'task_create' => $onTaskCreate($message),
            'dependency_add' => $onDependencyAdd($message),
            // Browser commands
            'browser_create' => $onBrowserCreate($message),
            'browser_page' => $onBrowserPage($message),
            'browser_goto' => $onBrowserGoto($message),
            'browser_run' => $onBrowserRun($message),
            'browser_screenshot' => $onBrowserScreenshot($message),
            'browser_snapshot' => $onBrowserSnapshot($message),
            'browser_click' => $onBrowserClick($message),
            'browser_fill' => $onBrowserFill($message),
            'browser_type' => $onBrowserType($message),
            'browser_text' => $onBrowserText($message),
            'browser_html' => $onBrowserHtml($message),
            'browser_wait' => $onBrowserWait($message),
            'browser_close' => $onBrowserClose($message),
            'browser_status' => $onBrowserStatus($message),
            // Lazy-loaded data requests (send to requesting client only)
            'request_done_tasks' => $onRequestDoneTasks($clientId),
            'request_blocked_tasks' => $onRequestBlockedTasks($clientId),
            'request_completed_tasks' => $onRequestCompletedTasks($clientId),
            // attach/detach handled implicitly via accept() detecting new connections
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
        if ($message instanceof SetTaskReviewCommand) {
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
        if ($message instanceof StopCommand) {
            $graceful = $message->mode === 'graceful';
            $onStop($graceful);
        } else {
            // Default to graceful stop
            $onStop(true);
        }
    }

    /**
     * Handle reload config command - reload config and invoke callback.
     *
     * @param  callable  $onReloadConfig  Callback to invoke after reloading config
     */
    private function handleReloadConfigCommand(callable $onReloadConfig): void
    {
        $this->configService->reload();
        $onReloadConfig();
    }
}
