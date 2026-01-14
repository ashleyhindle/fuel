<?php

declare(strict_types=1);

namespace App\Daemon\Helpers;

use App\Daemon\LifecycleManager;
use App\Daemon\SnapshotManager;
use App\Daemon\TaskSpawner;
use App\Services\ProcessManager;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

/**
 * Handles IPC command execution for DaemonLoop.
 * Extracted to reduce DaemonLoop size.
 */
final class CommandHandlers
{
    public function __construct(
        private readonly TaskService $taskService,
        private readonly TaskSpawner $taskSpawner,
        private readonly ProcessManager $processManager,
        private readonly SnapshotManager $snapshotManager,
        private readonly LifecycleManager $lifecycleManager,
    ) {}

    public function handleStop(bool $graceful): void
    {
        $this->lifecycleManager->stop($graceful);
        if (! $graceful) {
            foreach ($this->processManager->getActiveProcesses() as $process) {
                $this->processManager->kill($process->getTaskId());
            }
        }
    }

    public function handleTaskStart(\App\Ipc\IpcMessage $message): void
    {
        if ($message instanceof \App\Ipc\Commands\TaskStartCommand) {
            try {
                $task = $this->taskService->find($message->taskId);
                if ($task) {
                    $this->taskSpawner->trySpawnTask($task, $message->agentOverride, function (string $taskId, string $runId, string $agentName) {
                        $this->snapshotManager->broadcastTaskSpawned($taskId, $runId, $agentName);
                    });
                    $this->snapshotManager->broadcastSnapshot();
                }
            } catch (\RuntimeException $e) {
                // Task not found or spawn failed - ignore
            }
        }
    }

    public function handleTaskReopen(\App\Ipc\IpcMessage $message): void
    {
        if ($message instanceof \App\Ipc\Commands\TaskReopenCommand) {
            try {
                $this->taskService->reopen($message->taskId);
                $this->taskSpawner->invalidateTaskCache();
                $this->snapshotManager->broadcastSnapshot();
            } catch (\RuntimeException $e) {
                // Task not found or cannot be reopened - ignore
            }
        }
    }

    public function handleTaskDone(\App\Ipc\IpcMessage $message): void
    {
        if ($message instanceof \App\Ipc\Commands\TaskDoneCommand) {
            try {
                $params = ['ids' => [$message->taskId]];
                if (isset($message->reason)) {
                    $params['--reason'] = $message->reason;
                }
                if (isset($message->commitHash)) {
                    $params['--commit'] = $message->commitHash;
                }
                Artisan::call('done', $params);
                $this->taskSpawner->invalidateTaskCache();
                $this->snapshotManager->broadcastSnapshot();
            } catch (\RuntimeException $e) {
                // Task not found or cannot be marked done - ignore
            }
        }
    }

    public function handleTaskCreate(\App\Ipc\IpcMessage $message, \App\Services\ConsumeIpcServer $ipcServer): void
    {
        if ($message instanceof \App\Ipc\Commands\TaskCreateCommand) {
            try {
                $taskData = ['title' => $message->title];

                if (isset($message->description)) $taskData['description'] = $message->description;
                if (isset($message->priority)) $taskData['priority'] = $message->priority;
                if (isset($message->type)) $taskData['type'] = $message->type;
                if (isset($message->labels)) $taskData['labels'] = $message->labels;
                if (isset($message->complexity)) $taskData['complexity'] = $message->complexity;
                if (isset($message->epicId)) $taskData['epic_id'] = $message->epicId;
                if (isset($message->blockedBy)) $taskData['blocked_by'] = $message->blockedBy;

                $task = $this->taskService->create($taskData);
                $this->taskSpawner->invalidateTaskCache();
                $this->snapshotManager->broadcastSnapshot();

                $response = new \App\Ipc\Events\TaskCreateResponseEvent(
                    taskId: $task->short_id,
                    success: true,
                    error: null,
                    timestamp: new \DateTimeImmutable,
                    instanceId: $this->lifecycleManager->getInstanceId(),
                    requestId: $message->getRequestId()
                );
                $ipcServer->broadcast($response);
            } catch (\RuntimeException $e) {
                $response = new \App\Ipc\Events\TaskCreateResponseEvent(
                    taskId: '',
                    success: false,
                    error: $e->getMessage(),
                    timestamp: new \DateTimeImmutable,
                    instanceId: $this->lifecycleManager->getInstanceId(),
                    requestId: $message->getRequestId()
                );
                $ipcServer->broadcast($response);
            }
        }
    }

    public function handleDependencyAdd(\App\Ipc\IpcMessage $message): void
    {
        if ($message instanceof \App\Ipc\Commands\DependencyAddCommand) {
            try {
                $this->taskService->addDependency($message->taskId, $message->blockerTaskId);
                $this->taskSpawner->invalidateTaskCache();
                $this->snapshotManager->broadcastSnapshot();
            } catch (\RuntimeException $e) {
                // Dependency add failed - ignore
            }
        }
    }
}