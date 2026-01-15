<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\ConsumeSnapshot;
use App\Enums\ConsumeCommandType;
use App\Enums\ConsumeEventType;
use App\Ipc\Commands\AttachCommand;
use App\Ipc\Commands\BrowserCloseCommand;
use App\Ipc\Commands\BrowserCreateCommand;
use App\Ipc\Commands\BrowserGotoCommand;
use App\Ipc\Commands\BrowserPageCommand;
use App\Ipc\Commands\BrowserRunCommand;
use App\Ipc\Commands\BrowserScreenshotCommand;
use App\Ipc\Commands\BrowserStatusCommand;
use App\Ipc\Commands\DependencyAddCommand;
use App\Ipc\Commands\DetachCommand;
use App\Ipc\Commands\PauseCommand;
use App\Ipc\Commands\ReloadConfigCommand;
use App\Ipc\Commands\RequestBlockedTasksCommand;
use App\Ipc\Commands\RequestCompletedTasksCommand;
use App\Ipc\Commands\RequestDoneTasksCommand;
use App\Ipc\Commands\RequestSnapshotCommand;
use App\Ipc\Commands\ResumeCommand;
use App\Ipc\Commands\SetTaskReviewCommand;
use App\Ipc\Commands\StopCommand;
use App\Ipc\Commands\TaskCreateCommand;
use App\Ipc\Commands\TaskReopenCommand;
use App\Ipc\Commands\TaskStartCommand;
use App\Ipc\Events\BlockedTasksEvent;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\Events\CompletedTasksEvent;
use App\Ipc\Events\ConfigReloadedEvent;
use App\Ipc\Events\DoneTasksEvent;
use App\Ipc\Events\ErrorEvent;
use App\Ipc\Events\HealthChangeEvent;
use App\Ipc\Events\HelloEvent;
use App\Ipc\Events\OutputChunkEvent;
use App\Ipc\Events\ReviewCompletedEvent;
use App\Ipc\Events\SnapshotEvent;
use App\Ipc\Events\StatusLineEvent;
use App\Ipc\Events\TaskCompletedEvent;
use App\Ipc\Events\TaskCreateResponseEvent;
use App\Ipc\Events\TaskSpawnedEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;
use JsonException;
use Ramsey\Uuid\Uuid;

final class ConsumeIpcProtocol
{
    /**
     * Encode an IpcMessage to a JSON line with newline.
     */
    public function encode(IpcMessage $message): string
    {
        return json_encode($message->toArray(), JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Decode a JSON line to an IpcMessage or ErrorEvent.
     * Returns ErrorEvent for malformed JSON or unknown types.
     */
    public function decode(string $line, string $instanceId): IpcMessage
    {
        // Strip any trailing newlines/whitespace
        $line = trim($line);

        // Parse JSON
        try {
            $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            return new ErrorEvent(
                message: 'Malformed JSON: '.$jsonException->getMessage(),
                instanceId: $instanceId
            );
        }

        // Validate that we have an array
        if (! is_array($data)) {
            return new ErrorEvent(
                message: 'Expected JSON object, got '.gettype($data),
                instanceId: $instanceId
            );
        }

        // Validate required fields
        if (! isset($data['type'])) {
            return new ErrorEvent(
                message: 'Missing required field: type',
                instanceId: $instanceId
            );
        }

        // Decode based on type
        return $this->decodeByType($data, $instanceId);
    }

    /**
     * Generate a unique request ID (UUID v4).
     */
    public function generateRequestId(): string
    {
        return Uuid::uuid4()->toString();
    }

    /**
     * Generate a unique instance ID (UUID v4).
     */
    public function generateInstanceId(): string
    {
        return Uuid::uuid4()->toString();
    }

    /**
     * Decode the message based on its type field.
     */
    private function decodeByType(array $data, string $instanceId): IpcMessage
    {
        $type = $data['type'];

        // Try to match against command types
        try {
            $commandType = ConsumeCommandType::fromString($type);

            return match ($commandType) {
                ConsumeCommandType::Attach => AttachCommand::fromArray($data),
                ConsumeCommandType::Detach => DetachCommand::fromArray($data),
                ConsumeCommandType::Pause => PauseCommand::fromArray($data),
                ConsumeCommandType::Resume => ResumeCommand::fromArray($data),
                ConsumeCommandType::Stop => StopCommand::fromArray($data),
                ConsumeCommandType::ReloadConfig => ReloadConfigCommand::fromArray($data),
                ConsumeCommandType::RequestSnapshot => RequestSnapshotCommand::fromArray($data),
                ConsumeCommandType::SetTaskReviewEnabled => SetTaskReviewCommand::fromArray($data),
                ConsumeCommandType::TaskStart => TaskStartCommand::fromArray($data),
                ConsumeCommandType::TaskReopen => TaskReopenCommand::fromArray($data),
                ConsumeCommandType::TaskCreate => TaskCreateCommand::fromArray($data),
                ConsumeCommandType::DependencyAdd => DependencyAddCommand::fromArray($data),
                ConsumeCommandType::BrowserCreate => BrowserCreateCommand::fromArray($data),
                ConsumeCommandType::BrowserPage => BrowserPageCommand::fromArray($data),
                ConsumeCommandType::BrowserGoto => BrowserGotoCommand::fromArray($data),
                ConsumeCommandType::BrowserRun => BrowserRunCommand::fromArray($data),
                ConsumeCommandType::BrowserScreenshot => BrowserScreenshotCommand::fromArray($data),
                ConsumeCommandType::BrowserClose => BrowserCloseCommand::fromArray($data),
                ConsumeCommandType::BrowserStatus => BrowserStatusCommand::fromArray($data),
                ConsumeCommandType::RequestDoneTasks => RequestDoneTasksCommand::fromArray($data),
                ConsumeCommandType::RequestBlockedTasks => RequestBlockedTasksCommand::fromArray($data),
                ConsumeCommandType::RequestCompletedTasks => RequestCompletedTasksCommand::fromArray($data),
            };
        } catch (\ValueError) {
            // Not a command type, try event types
        }

        // Try to match against event types
        try {
            $eventType = ConsumeEventType::fromString($type);

            return match ($eventType) {
                ConsumeEventType::Hello => $this->decodeHelloEvent($data),
                ConsumeEventType::Snapshot => $this->decodeSnapshotEvent($data),
                ConsumeEventType::StatusLine => $this->decodeStatusLineEvent($data),
                ConsumeEventType::TaskSpawned => $this->decodeTaskSpawnedEvent($data),
                ConsumeEventType::TaskCompleted => $this->decodeTaskCompletedEvent($data),
                ConsumeEventType::HealthChange => $this->decodeHealthChangeEvent($data),
                ConsumeEventType::OutputChunk => $this->decodeOutputChunkEvent($data),
                ConsumeEventType::Error => $this->decodeErrorEvent($data),
                ConsumeEventType::ReviewCompleted => $this->decodeReviewCompletedEvent($data),
                ConsumeEventType::TaskCreateResponse => $this->decodeTaskCreateResponseEvent($data),
                ConsumeEventType::BrowserResponse => $this->decodeBrowserResponseEvent($data),
                ConsumeEventType::DoneTasks => $this->decodeDoneTasksEvent($data),
                ConsumeEventType::BlockedTasks => $this->decodeBlockedTasksEvent($data),
                ConsumeEventType::CompletedTasks => $this->decodeCompletedTasksEvent($data),
                ConsumeEventType::ConfigReloaded => $this->decodeConfigReloadedEvent($data),
            };
        } catch (\ValueError) {
            // Unknown type
        }

        // Unknown type - return error event
        return new ErrorEvent(
            message: 'Unknown message type: '.$type,
            instanceId: $instanceId
        );
    }

    private function decodeHelloEvent(array $data): HelloEvent
    {
        return new HelloEvent(
            version: $data['version'] ?? '',
            instanceId: $data['instance_id'] ?? '',
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : null,
            requestId: $data['request_id'] ?? null
        );
    }

    private function decodeSnapshotEvent(array $data): SnapshotEvent
    {
        $snapshotData = $data['snapshot'] ?? [];

        // Convert board state arrays back to Collections
        $boardState = [];
        foreach (['ready', 'in_progress', 'review', 'blocked', 'human', 'done'] as $status) {
            $tasks = $snapshotData['board_state'][$status] ?? [];
            $boardState[$status] = collect($tasks);
        }

        // Convert snapshot array back to ConsumeSnapshot DTO
        $snapshot = new ConsumeSnapshot(
            boardState: $boardState,
            activeProcesses: $snapshotData['active_processes'] ?? [],
            healthSummary: $snapshotData['health_summary'] ?? [],
            runnerState: $snapshotData['runner_state'] ?? ['paused' => false, 'started_at' => null, 'instance_id' => ''],
            config: $snapshotData['config'] ?? ['interval_seconds' => 5, 'agents' => []],
            epics: $snapshotData['epics'] ?? [],
            doneCount: $snapshotData['done_count'] ?? 0,
            blockedCount: $snapshotData['blocked_count'] ?? 0
        );

        return new SnapshotEvent(
            snapshot: $snapshot,
            instanceId: $data['instance_id'] ?? '',
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : null,
            requestId: $data['request_id'] ?? null
        );
    }

    private function decodeStatusLineEvent(array $data): StatusLineEvent
    {
        return new StatusLineEvent(
            level: $data['level'] ?? 'info',
            text: $data['text'] ?? '',
            instanceId: $data['instance_id'] ?? '',
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : null,
            requestId: $data['request_id'] ?? null
        );
    }

    private function decodeTaskSpawnedEvent(array $data): TaskSpawnedEvent
    {
        return new TaskSpawnedEvent(
            taskId: $data['task_id'] ?? '',
            runId: $data['run_id'] ?? '',
            agent: $data['agent'] ?? '',
            instanceId: $data['instance_id'] ?? '',
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : null,
            requestId: $data['request_id'] ?? null
        );
    }

    private function decodeTaskCompletedEvent(array $data): TaskCompletedEvent
    {
        return new TaskCompletedEvent(
            taskId: $data['task_id'] ?? '',
            runId: $data['run_id'] ?? '',
            exitCode: $data['exit_code'] ?? 0,
            completionType: $data['completion_type'] ?? 'success',
            instanceId: $data['instance_id'] ?? '',
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : null,
            requestId: $data['request_id'] ?? null
        );
    }

    private function decodeHealthChangeEvent(array $data): HealthChangeEvent
    {
        return new HealthChangeEvent(
            agent: $data['agent'] ?? '',
            status: $data['status'] ?? 'healthy',
            instanceId: $data['instance_id'] ?? '',
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : null,
            requestId: $data['request_id'] ?? null
        );
    }

    private function decodeOutputChunkEvent(array $data): OutputChunkEvent
    {
        return new OutputChunkEvent(
            taskId: $data['task_id'] ?? '',
            runId: $data['run_id'] ?? '',
            stream: $data['stream'] ?? 'stdout',
            chunk: $data['chunk'] ?? '',
            instanceId: $data['instance_id'] ?? '',
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : null,
            requestId: $data['request_id'] ?? null
        );
    }

    private function decodeErrorEvent(array $data): ErrorEvent
    {
        return new ErrorEvent(
            message: $data['message'] ?? '',
            instanceId: $data['instance_id'] ?? '',
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : null,
            requestId: $data['request_id'] ?? null
        );
    }

    private function decodeReviewCompletedEvent(array $data): ReviewCompletedEvent
    {
        return new ReviewCompletedEvent(
            taskId: $data['task_id'] ?? '',
            passed: $data['passed'] ?? false,
            issues: $data['issues'] ?? [],
            wasAlreadyDone: $data['was_already_done'] ?? false,
            instanceId: $data['instance_id'] ?? '',
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : null,
            requestId: $data['request_id'] ?? null
        );
    }

    private function decodeTaskCreateResponseEvent(array $data): TaskCreateResponseEvent
    {
        return new TaskCreateResponseEvent(
            taskId: $data['task_id'] ?? '',
            success: $data['success'] ?? false,
            error: $data['error'] ?? null,
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : new DateTimeImmutable,
            instanceId: $data['instance_id'] ?? '',
            requestId: $data['request_id'] ?? null
        );
    }

    private function decodeBrowserResponseEvent(array $data): BrowserResponseEvent
    {
        return BrowserResponseEvent::fromArray($data);
    }

    private function decodeDoneTasksEvent(array $data): DoneTasksEvent
    {
        return new DoneTasksEvent(
            tasks: $data['tasks'] ?? [],
            total: $data['total'] ?? 0,
            instanceId: $data['instance_id'] ?? '',
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : null,
            requestId: $data['request_id'] ?? null
        );
    }

    private function decodeBlockedTasksEvent(array $data): BlockedTasksEvent
    {
        return new BlockedTasksEvent(
            tasks: $data['tasks'] ?? [],
            total: $data['total'] ?? 0,
            instanceId: $data['instance_id'] ?? '',
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : null,
            requestId: $data['request_id'] ?? null
        );
    }

    private function decodeCompletedTasksEvent(array $data): CompletedTasksEvent
    {
        return new CompletedTasksEvent(
            tasks: $data['tasks'] ?? [],
            total: $data['total'] ?? 0,
            instanceId: $data['instance_id'] ?? '',
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : null,
            requestId: $data['request_id'] ?? null
        );
    }

    private function decodeConfigReloadedEvent(array $data): ConfigReloadedEvent
    {
        return new ConfigReloadedEvent(
            instanceId: $data['instance_id'] ?? '',
            timestamp: isset($data['timestamp']) ? new DateTimeImmutable($data['timestamp']) : null,
            requestId: $data['request_id'] ?? null
        );
    }
}
