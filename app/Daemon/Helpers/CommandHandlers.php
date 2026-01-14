<?php

declare(strict_types=1);

namespace App\Daemon\Helpers;

use App\Daemon\LifecycleManager;
use App\Daemon\SnapshotManager;
use App\Daemon\TaskSpawner;
use App\Enums\TaskStatus;
use App\Ipc\Commands\DependencyAddCommand;
use App\Ipc\Commands\TaskCreateCommand;
use App\Ipc\Commands\TaskDoneCommand;
use App\Ipc\Commands\TaskReopenCommand;
use App\Ipc\Commands\TaskStartCommand;
use App\Ipc\Events\BlockedTasksEvent;
use App\Ipc\Events\DoneTasksEvent;
use App\Ipc\Events\TaskCreateResponseEvent;
use App\Ipc\IpcMessage;
use App\Models\Task;
use App\Services\ConsumeIpcServer;
use App\Services\ProcessManager;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

/**
 * Handles IPC command execution for DaemonLoop.
 * Extracted to reduce DaemonLoop size.
 */
final readonly class CommandHandlers
{
    public function __construct(
        private TaskService $taskService,
        private TaskSpawner $taskSpawner,
        private ProcessManager $processManager,
        private SnapshotManager $snapshotManager,
        private LifecycleManager $lifecycleManager,
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

    public function handleTaskStart(IpcMessage $message): void
    {
        if ($message instanceof TaskStartCommand) {
            try {
                $task = $this->taskService->find($message->taskId);
                if ($task instanceof Task) {
                    $this->taskSpawner->trySpawnTask($task, $message->agentOverride, function (string $taskId, string $runId, string $agentName): void {
                        $this->snapshotManager->broadcastTaskSpawned($taskId, $runId, $agentName);
                    });
                    $this->snapshotManager->broadcastSnapshot();
                }
            } catch (\RuntimeException) {
                // Task not found or spawn failed - ignore
            }
        }
    }

    public function handleTaskReopen(IpcMessage $message): void
    {
        if ($message instanceof TaskReopenCommand) {
            try {
                $this->taskService->reopen($message->taskId);
                $this->taskSpawner->invalidateTaskCache();
                $this->snapshotManager->broadcastSnapshot();
            } catch (\RuntimeException) {
                // Task not found or cannot be reopened - ignore
            }
        }
    }

    public function handleTaskDone(IpcMessage $message): void
    {
        if ($message instanceof TaskDoneCommand) {
            try {
                $params = ['ids' => [$message->taskId]];
                if ($message->reason !== null) {
                    $params['--reason'] = $message->reason;
                }

                if ($message->commitHash !== null) {
                    $params['--commit'] = $message->commitHash;
                }

                Artisan::call('done', $params);
                $this->taskSpawner->invalidateTaskCache();
                $this->snapshotManager->broadcastSnapshot();
            } catch (\RuntimeException) {
                // Task not found or cannot be marked done - ignore
            }
        }
    }

    public function handleTaskCreate(IpcMessage $message, ConsumeIpcServer $ipcServer): void
    {
        if ($message instanceof TaskCreateCommand) {
            try {
                $taskData = ['title' => $message->title];

                if ($message->description !== null) {
                    $taskData['description'] = $message->description;
                }

                if ($message->priority !== null) {
                    $taskData['priority'] = $message->priority;
                }

                if ($message->type !== null) {
                    $taskData['type'] = $message->type;
                }

                if ($message->labels !== null) {
                    $taskData['labels'] = $message->labels;
                }

                if ($message->complexity !== null) {
                    $taskData['complexity'] = $message->complexity;
                }

                if ($message->epicId !== null) {
                    $taskData['epic_id'] = $message->epicId;
                }

                if ($message->blockedBy !== null) {
                    $taskData['blocked_by'] = $message->blockedBy;
                }

                $task = $this->taskService->create($taskData);
                $this->taskSpawner->invalidateTaskCache();
                $this->snapshotManager->broadcastSnapshot();

                $response = new TaskCreateResponseEvent(
                    taskId: $task->short_id,
                    success: true,
                    error: null,
                    timestamp: new \DateTimeImmutable,
                    instanceId: $this->lifecycleManager->getInstanceId(),
                    requestId: $message->getRequestId()
                );
                $ipcServer->broadcast($response);
            } catch (\RuntimeException $e) {
                $response = new TaskCreateResponseEvent(
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

    public function handleDependencyAdd(IpcMessage $message): void
    {
        if ($message instanceof DependencyAddCommand) {
            try {
                $this->taskService->addDependency($message->taskId, $message->blockerTaskId);
                $this->taskSpawner->invalidateTaskCache();
                $this->snapshotManager->broadcastSnapshot();
            } catch (\RuntimeException) {
                // Dependency add failed - ignore
            }
        }
    }

    /**
     * Handle request for done tasks - sends only to the requesting client.
     */
    public function handleRequestDoneTasks(string $clientId, ConsumeIpcServer $ipcServer): void
    {
        $allTasks = $this->taskService->all();
        $doneTasks = $allTasks->filter(fn ($t): bool => $t->status === TaskStatus::Done)
            ->sortByDesc('updated_at')
            ->values();

        // Serialize tasks for IPC transfer
        $tasksArray = $doneTasks->map(function ($task) {
            if (method_exists($task, 'attributesToArray')) {
                $data = $task->attributesToArray();
                if (method_exists($task, 'relationLoaded') && $task->relationLoaded('epic') && $task->epic) {
                    $data['epic_short_id'] = $task->epic->short_id;
                }

                return $data;
            }

            return $task->toArray();
        })->toArray();

        $event = new DoneTasksEvent(
            tasks: $tasksArray,
            total: count($tasksArray),
            instanceId: $this->lifecycleManager->getInstanceId()
        );

        $ipcServer->sendTo($clientId, $event);
    }

    /**
     * Handle request for blocked tasks - sends only to the requesting client.
     */
    public function handleRequestBlockedTasks(string $clientId, ConsumeIpcServer $ipcServer): void
    {
        $blockedTasks = $this->taskService->blocked();

        // Serialize tasks for IPC transfer
        $tasksArray = $blockedTasks->map(function ($task) {
            if (method_exists($task, 'attributesToArray')) {
                $data = $task->attributesToArray();
                if (method_exists($task, 'relationLoaded') && $task->relationLoaded('epic') && $task->epic) {
                    $data['epic_short_id'] = $task->epic->short_id;
                }

                return $data;
            }

            return $task->toArray();
        })->toArray();

        $event = new BlockedTasksEvent(
            tasks: $tasksArray,
            total: count($tasksArray),
            instanceId: $this->lifecycleManager->getInstanceId()
        );

        $ipcServer->sendTo($clientId, $event);
    }
}
