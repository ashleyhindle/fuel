<?php

declare(strict_types=1);

namespace App\Services;

use App\Ipc\Commands\AttachCommand;
use App\Ipc\Commands\DetachCommand;
use App\Ipc\Commands\PauseCommand;
use App\Ipc\Commands\ReloadConfigCommand;
use App\Ipc\Commands\RequestSnapshotCommand;
use App\Ipc\Commands\ResumeCommand;
use App\Ipc\Commands\SetTaskReviewCommand;
use App\Ipc\Commands\StopCommand;
use App\Ipc\Commands\TaskCreateCommand;
use App\Ipc\Commands\TaskDoneCommand;
use App\Ipc\Events\HealthChangeEvent;
use App\Ipc\Events\HelloEvent;
use App\Ipc\Events\SnapshotEvent;
use App\Ipc\Events\TaskCompletedEvent;
use App\Ipc\Events\TaskCreateResponseEvent;
use App\Ipc\Events\TaskSpawnedEvent;
use App\Ipc\IpcMessage;
use App\Models\Task;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * IPC client for communicating with the consume runner daemon.
 *
 * Handles socket connection, protocol encoding/decoding, and state management
 * from IPC events. ConsumeCommand delegates all runner communication to this class.
 */
class ConsumeIpcClient
{
    /** @var resource|null Unix domain socket connection */
    private $socket = null;

    /** Protocol handler for encoding/decoding messages */
    private ConsumeIpcProtocol $protocol;

    /** Unique instance ID for this client session */
    private string $instanceId;

    /** Board state from latest snapshot */
    private array $boardState = [];

    /** Active processes from latest snapshot */
    private array $activeProcesses = [];

    /** Agent health summary from latest snapshot */
    private array $healthSummary = [];

    /** Runner state (paused, started_at, instance_id) */
    private array $runnerState = [];

    /** Epics data keyed by short_id */
    private array $epics = [];

    /** Whether currently connected to runner */
    private bool $connected = false;

    /** Buffer for incomplete messages */
    private string $readBuffer = '';

    /** Whether HelloEvent has been received during attach */
    private bool $receivedHello = false;

    /** Whether SnapshotEvent has been received during attach */
    private bool $receivedSnapshot = false;

    /** IP address for TCP connections */
    private string $ip;

    public function __construct(string $ip = '127.0.0.1')
    {
        $this->protocol = new ConsumeIpcProtocol;
        $this->instanceId = $this->protocol->generateInstanceId();
        $this->ip = $ip;
    }

    /**
     * Check if the consume runner is alive by reading PID file.
     */
    public function isRunnerAlive(string $pidFilePath): bool
    {
        if (! file_exists($pidFilePath)) {
            return false;
        }

        $content = file_get_contents($pidFilePath);
        if ($content === false) {
            return false;
        }

        $data = json_decode($content, true);
        if (! is_array($data) || ! isset($data['pid'])) {
            return false;
        }

        $pid = (int) $data['pid'];

        // Use posix_kill to check if process is alive
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        // Fallback: check if PID exists in process table
        $result = shell_exec("ps -p $pid -o pid=");

        return $result !== null && trim($result) === (string) $pid;
    }

    /**
     * Start the consume runner in the background.
     *
     * @throws \RuntimeException If fork fails
     */
    public function startRunner(string $fuelBinPath): void
    {
        if (! function_exists('pcntl_fork')) {
            throw new \RuntimeException('pcntl extension required to start runner');
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new \RuntimeException('Failed to fork runner process');
        }

        if ($pid === 0) {
            // Child process: exec the runner command
            pcntl_exec($fuelBinPath, ['consume:runner']);
            // If exec fails, exit child
            exit(1);
        }

        // Parent process continues
    }

    /**
     * Check if TCP server is ready (non-blocking).
     */
    public function isServerReady(int $port): bool
    {
        $socket = @stream_socket_client("tcp://{$this->ip}:{$port}", $errno, $errstr, 0.1);
        if ($socket !== false) {
            fclose($socket);

            return true;
        }

        return false;
    }

    /**
     * Wait for TCP server to be ready (max wait time).
     *
     * @throws \RuntimeException If server not ready within timeout
     */
    public function waitForServer(int $port, int $maxWait = 5): void
    {
        $waited = 0;
        $host = "{$this->ip}:{$port}";

        while ($waited < $maxWait) {
            $socket = @stream_socket_client("tcp://{$host}", $errno, $errstr, 1);
            if ($socket !== false) {
                fclose($socket);

                return; // Server is ready
            }
            usleep(100000); // 100ms
            $waited += 0.1;
        }

        throw new \RuntimeException("Runner not ready on {$this->ip}:{$port} after {$maxWait} seconds");
    }

    /**
     * Connect to the runner via TCP.
     *
     * @throws \RuntimeException If connection fails
     */
    public function connect(int $port): void
    {
        $socket = @stream_socket_client("tcp://{$this->ip}:{$port}", $errno, $errstr, 5);

        if ($socket === false) {
            throw new \RuntimeException("Failed to connect to runner on {$this->ip}:{$port}: {$errstr} ({$errno})");
        }

        // Keep socket in blocking mode initially for reliable attach
        // Will be set to non-blocking after successful attach
        stream_set_blocking($socket, true);

        $this->socket = $socket;
    }

    /**
     * Disconnect from the runner.
     */
    public function disconnect(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }

    /**
     * Check if connected to runner.
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->socket !== null;
    }

    /**
     * Attach to the runner: send AttachCommand and wait for Hello+Snapshot.
     *
     * @throws \RuntimeException If initial events not received
     */
    public function attach(): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException('Not connected to socket');
        }

        // Send AttachCommand
        $attachCmd = new AttachCommand(
            last_event_id: null,
            timestamp: new DateTimeImmutable,
            instanceId: $this->instanceId
        );
        $this->sendMessage($attachCmd);

        // Wait for HelloEvent
        $helloEvent = $this->waitForEvent('hello', 5);
        if ($helloEvent === null) {
            throw new \RuntimeException('Did not receive HelloEvent from runner');
        }

        // Wait for SnapshotEvent
        $snapshotEvent = $this->waitForEvent('snapshot', 5);
        if ($snapshotEvent === null) {
            throw new \RuntimeException('Did not receive SnapshotEvent from runner');
        }

        // Apply snapshot to initialize state
        if ($snapshotEvent instanceof SnapshotEvent) {
            $this->applySnapshotEvent($snapshotEvent);
        }

        $this->connected = true;
    }

    /**
     * Begin non-blocking attach: send AttachCommand and prepare to receive events.
     * Call pollAttachEvents() repeatedly until isAttachComplete() returns true.
     */
    public function beginAttach(): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException('Not connected to socket');
        }

        $this->receivedHello = false;
        $this->receivedSnapshot = false;

        // Send AttachCommand
        $attachCmd = new AttachCommand(
            last_event_id: null,
            timestamp: new DateTimeImmutable,
            instanceId: $this->instanceId
        );
        $this->sendMessage($attachCmd);

        // Set socket to non-blocking for polling
        stream_set_blocking($this->socket, false);
    }

    /**
     * Poll for attach events (Hello and Snapshot).
     * Returns true if new events were received.
     */
    public function pollAttachEvents(): bool
    {
        if ($this->socket === null) {
            return false;
        }

        $receivedNew = false;
        $event = $this->readEvent();

        while ($event !== null) {
            if ($event->type() === 'hello') {
                $this->receivedHello = true;
                $receivedNew = true;
            } elseif ($event->type() === 'snapshot') {
                $this->receivedSnapshot = true;
                $receivedNew = true;
                if ($event instanceof SnapshotEvent) {
                    $this->applySnapshotEvent($event);
                }
            }

            $event = $this->readEvent();
        }

        return $receivedNew;
    }

    /**
     * Check if attach is complete (received both Hello and Snapshot).
     */
    public function isAttachComplete(): bool
    {
        return $this->receivedHello && $this->receivedSnapshot;
    }

    /**
     * Finalize attach after polling is complete.
     *
     * @throws \RuntimeException If attach events were not received
     */
    public function finalizeAttach(): void
    {
        if (! $this->receivedHello) {
            throw new \RuntimeException('Did not receive HelloEvent from runner');
        }
        if (! $this->receivedSnapshot) {
            throw new \RuntimeException('Did not receive SnapshotEvent from runner');
        }

        $this->connected = true;
    }

    /**
     * Detach from the runner (sends DetachCommand).
     */
    public function detach(): void
    {
        if ($this->socket === null) {
            return;
        }

        $detachCmd = new DetachCommand(
            timestamp: new DateTimeImmutable,
            instanceId: $this->instanceId
        );
        $this->sendMessage($detachCmd);
        $this->connected = false;
    }

    /**
     * Set whether automatic task reviews are enabled on the runner.
     */
    public function setTaskReviewEnabled(bool $enabled): void
    {
        if ($this->socket === null) {
            return;
        }

        $cmd = new SetTaskReviewCommand(
            enabled: $enabled,
            timestamp: new DateTimeImmutable,
            instanceId: $this->instanceId
        );
        $this->sendMessage($cmd);
    }

    /**
     * Poll for all available events (non-blocking).
     *
     * @return array<IpcMessage> Array of received events
     */
    public function pollEvents(): array
    {
        $events = [];

        while (($event = $this->readEvent()) !== null) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Apply an event to update internal state.
     */
    public function applyEvent(IpcMessage $event): void
    {
        match ($event->type()) {
            'snapshot' => $this->applySnapshotEvent($event),
            'task_spawned' => $this->handleTaskSpawnedEvent($event),
            'task_completed' => $this->handleTaskCompletedEvent($event),
            'output_chunk' => null, // Just activity, no state change
            'status_line' => null, // Display only, no state change
            'health_change' => $this->handleHealthChangeEvent($event),
            'hello' => null, // Already handled in attach
            'error' => null, // Handle errors separately
            default => null,
        };
    }

    /**
     * Get current board state.
     *
     * @return array{ready: array, in_progress: array, review: array, blocked: array, human: array, done: array}
     */
    public function getBoardState(): array
    {
        return $this->boardState;
    }

    /**
     * Get active processes info.
     *
     * @return array<array{task_id: string, run_id: string, agent: string, pid: int, started_at: string}>
     */
    public function getActiveProcesses(): array
    {
        return $this->activeProcesses;
    }

    /**
     * Get agent health summary.
     */
    public function getHealthSummary(): array
    {
        return $this->healthSummary;
    }

    /**
     * Get epics data keyed by short_id.
     *
     * @return array<string, array{short_id: string, title: string, status: string}>
     */
    public function getEpics(): array
    {
        return $this->epics;
    }

    /**
     * Get a specific epic by short_id.
     *
     * @return array{short_id: string, title: string, status: string}|null
     */
    public function getEpic(string $shortId): ?array
    {
        return $this->epics[$shortId] ?? null;
    }

    /**
     * Check if runner is paused.
     */
    public function isPaused(): bool
    {
        return $this->runnerState['paused'] ?? true;
    }

    /**
     * Get the instance ID for this client session.
     */
    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    /**
     * Send pause command to runner.
     */
    public function sendPause(): void
    {
        $cmd = new PauseCommand(
            timestamp: new DateTimeImmutable,
            instanceId: $this->instanceId
        );
        $this->sendMessage($cmd);
    }

    /**
     * Send resume command to runner.
     */
    public function sendResume(): void
    {
        $cmd = new ResumeCommand(
            timestamp: new DateTimeImmutable,
            instanceId: $this->instanceId
        );
        $this->sendMessage($cmd);
    }

    /**
     * Send stop command to runner.
     */
    public function sendStop(): void
    {
        $cmd = new StopCommand(
            timestamp: new DateTimeImmutable,
            instanceId: $this->instanceId
        );
        $this->sendMessage($cmd);
    }

    /**
     * Send reload config command to runner.
     */
    public function sendReloadConfig(): void
    {
        $cmd = new ReloadConfigCommand(
            timestamp: new DateTimeImmutable,
            instanceId: $this->instanceId
        );
        $this->sendMessage($cmd);
    }

    /**
     * Send task done command to runner.
     */
    public function sendTaskDone(string $taskId, ?string $reason = null, ?string $commitHash = null): void
    {
        $cmd = new TaskDoneCommand(
            taskId: $taskId,
            reason: $reason,
            commitHash: $commitHash,
            timestamp: new DateTimeImmutable,
            instanceId: $this->instanceId
        );
        $this->sendMessage($cmd);
    }

    /**
     * Request a fresh snapshot from runner.
     */
    public function requestSnapshot(): void
    {
        $cmd = new RequestSnapshotCommand(
            timestamp: new DateTimeImmutable,
            instanceId: $this->instanceId
        );
        $this->sendMessage($cmd);
    }

    /**
     * Send a generic IPC command to the runner.
     *
     * This method allows sending any IPC command type, including custom commands
     * not explicitly handled by dedicated methods in this class.
     *
     * @param  IpcMessage  $command  The command to send to the runner
     */
    public function sendCommand(IpcMessage $command): void
    {
        $this->sendMessage($command);
    }

    /**
     * Send a TaskCreateCommand and wait for the response with the created task ID.
     *
     * @param  array  $taskData  Task data containing title, description, etc.
     * @param  int  $timeoutSeconds  Maximum time to wait for response
     * @return string|null The created task's ID, or null if creation failed
     */
    public function createTaskWithResponse(array $taskData, int $timeoutSeconds = 5): ?string
    {
        if ($this->socket === null) {
            return null;
        }

        // Generate a request ID for correlation
        $requestId = $this->protocol->generateRequestId();

        // Create and send the TaskCreateCommand with the request ID
        $command = new TaskCreateCommand(
            title: $taskData['title'],
            description: $taskData['description'] ?? null,
            labels: $taskData['labels'] ?? null,
            priority: $taskData['priority'] ?? null,
            timestamp: new DateTimeImmutable,
            instanceId: $this->instanceId,
            requestId: $requestId
        );

        $this->sendMessage($command);

        // Wait for the TaskCreateResponseEvent with matching request ID
        $deadline = time() + $timeoutSeconds;

        // Temporarily set socket to blocking mode for reliable response
        $wasBlocking = stream_get_meta_data($this->socket)['blocked'] ?? false;
        stream_set_blocking($this->socket, true);
        stream_set_timeout($this->socket, 1);

        try {
            while (time() < $deadline) {
                $event = $this->readEvent();
                if ($event !== null) {
                    // Check if it's a TaskCreateResponse with matching request ID
                    if ($event instanceof TaskCreateResponseEvent &&
                        $event->getRequestId() === $requestId) {
                        if ($event->success) {
                            return $event->taskId;
                        }

                        return null; // Creation failed
                    }
                    // Process other events normally (don't lose them)
                    if ($event->type() !== 'task_create_response') {
                        $this->applyEvent($event);
                    }
                }
                usleep(50000); // 50ms
            }

            return null; // Timeout
        } finally {
            // Restore original blocking mode
            stream_set_blocking($this->socket, $wasBlocking);
        }
    }

    /**
     * Send an IPC message to the runner.
     */
    private function sendMessage(IpcMessage $message): void
    {
        if ($this->socket === null) {
            return;
        }

        $encoded = $this->protocol->encode($message);
        fwrite($this->socket, $encoded);
    }

    /**
     * Read one event from socket (non-blocking).
     * Uses buffering to handle partial reads of large messages.
     */
    private function readEvent(): ?IpcMessage
    {
        if ($this->socket === null) {
            return null;
        }

        $line = fgets($this->socket);
        if ($line === false) {
            return null;
        }

        return $this->protocol->decode(trim($line), $this->instanceId);
    }

    /**
     * Wait for a specific event type (blocking with timeout).
     */
    private function waitForEvent(string $eventType, int $timeoutSeconds): ?IpcMessage
    {
        $deadline = time() + $timeoutSeconds;

        // Temporarily set socket to blocking mode for reliable reading during attach
        $wasBlocking = stream_get_meta_data($this->socket)['blocked'] ?? false;
        stream_set_blocking($this->socket, true);
        stream_set_timeout($this->socket, 1); // 1 second read timeout

        try {
            while (time() < $deadline) {
                // If we have partial data in buffer but no complete message,
                // wait a bit for more data to arrive
                if (strlen($this->readBuffer) > 0 && strpos($this->readBuffer, "\n") === false) {
                    usleep(100000); // 100ms to let more data arrive
                }

                $event = $this->readEvent();
                if ($event !== null) {
                    if ($event->type() === $eventType) {
                        return $event;
                    }
                }

                usleep(50000); // 50ms
            }

            return null;
        } finally {
            // Restore original blocking mode
            stream_set_blocking($this->socket, $wasBlocking);
        }
    }

    /**
     * Apply a snapshot event to update all state.
     */
    private function applySnapshotEvent(IpcMessage $event): void
    {
        if (! $event instanceof SnapshotEvent) {
            return;
        }

        $snapshot = $event->snapshot();

        // Hydrate task arrays back to Task models
        $this->boardState = $this->hydrateBoardState($snapshot->boardState);
        $this->activeProcesses = $snapshot->activeProcesses;
        $this->healthSummary = $snapshot->healthSummary ?? [];
        $this->runnerState = $snapshot->runnerState ?? [];
        $this->epics = $snapshot->epics ?? [];
    }

    /**
     * Hydrate board state arrays back to Task model collections.
     *
     * @param  array<string, array|Collection>  $boardState
     * @return array<string, Collection>
     */
    private function hydrateBoardState(array $boardState): array
    {
        $hydrated = [];

        foreach ($boardState as $status => $tasks) {
            // Convert array of task data to Collection of Task models
            $taskArray = $tasks instanceof Collection ? $tasks->toArray() : $tasks;
            $hydrated[$status] = Task::hydrate($taskArray);
        }

        return $hydrated;
    }

    /**
     * Handle task spawned event - update active processes.
     */
    private function handleTaskSpawnedEvent(IpcMessage $event): void
    {
        if (! $event instanceof TaskSpawnedEvent) {
            return;
        }

        $this->activeProcesses[$event->taskId()] = [
            'task_id' => $event->taskId(),
            'run_id' => $event->runId(),
            'agent' => $event->agent(),
            'started_at' => $event->timestamp()->format('c'),
            'last_output_time' => null, // Will be updated on next snapshot
        ];
    }

    /**
     * Handle task completed event - remove from active processes.
     */
    private function handleTaskCompletedEvent(IpcMessage $event): void
    {
        if (! $event instanceof TaskCompletedEvent) {
            return;
        }

        unset($this->activeProcesses[$event->taskId()]);
    }

    /**
     * Handle health change event - update health summary.
     */
    private function handleHealthChangeEvent(IpcMessage $event): void
    {
        if (! $event instanceof HealthChangeEvent) {
            return;
        }

        $this->healthSummary[$event->agent()] = [
            'status' => $event->status(),
        ];
    }
}
