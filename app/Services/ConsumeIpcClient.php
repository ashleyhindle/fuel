<?php

declare(strict_types=1);

namespace App\Services;

use App\Ipc\Commands\AttachCommand;
use App\Ipc\Commands\DetachCommand;
use App\Ipc\Commands\PauseCommand;
use App\Ipc\Commands\ReloadConfigCommand;
use App\Ipc\Commands\RequestBlockedTasksCommand;
use App\Ipc\Commands\RequestDoneTasksCommand;
use App\Ipc\Commands\RequestSnapshotCommand;
use App\Ipc\Commands\ResumeCommand;
use App\Ipc\Commands\SetTaskReviewCommand;
use App\Ipc\Commands\StopCommand;
use App\Ipc\Commands\TaskCreateCommand;
use App\Ipc\Commands\TaskDoneCommand;
use App\Ipc\Events\BlockedTasksEvent;
use App\Ipc\Events\DoneTasksEvent;
use App\Ipc\Events\HealthChangeEvent;
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
    private $socket;

    /** Protocol handler for encoding/decoding messages */
    private readonly ConsumeIpcProtocol $protocol;

    /** Unique instance ID for this client session */
    private readonly string $instanceId;

    /** Board state from latest snapshot */
    private array $boardState = [];

    /** Active processes from latest snapshot */
    private array $activeProcesses = [];

    /** Agent health summary from latest snapshot */
    private array $healthSummary = [];

    /** Done tasks (lazy-loaded on demand) */
    private ?array $doneTasks = null;

    /** Blocked tasks (lazy-loaded on demand) */
    private ?array $blockedTasks = null;

    /** Count of done tasks from snapshot (for footer display) */
    private int $doneCount = 0;

    /** Count of blocked tasks from snapshot (for footer display) */
    private int $blockedCount = 0;

    /** Runner state (paused, started_at, instance_id) */
    private array $runnerState = [];

    /** Epics data keyed by short_id */
    private array $epics = [];

    /** Whether currently connected to runner */
    private bool $connected = false;

    /** Whether HelloEvent has been received during attach */
    private bool $receivedHello = false;

    /** Whether SnapshotEvent has been received during attach */
    private bool $receivedSnapshot = false;

    /** Port number for reconnection */
    private ?int $port = null;

    /** Last reconnection attempt timestamp */
    private ?float $lastReconnectAttempt = null;

    /** Reconnection interval in seconds */
    private const RECONNECT_INTERVAL = 2.0;

    public function __construct(/** IP address for TCP connections */
        private readonly string $ip = '127.0.0.1')
    {
        $this->protocol = new ConsumeIpcProtocol;
        $this->instanceId = $this->protocol->generateInstanceId();
    }

    /**
     * Check if the consume runner is alive by reading PID file.
     */
    public function isRunnerAlive(string $pidFilePath): bool
    {
        if (! file_exists($pidFilePath)) {
            return false;
        }

        // Open lock file with shared lock for reading
        $lockFile = $pidFilePath.'.lock';
        $lock = @fopen($lockFile, 'c');

        // If we can't open lock file, fall back to direct read (backward compatibility)
        if ($lock === false) {
            $content = @file_get_contents($pidFilePath);
            if ($content === false) {
                return false;
            }
        } else {
            try {
                // Acquire shared lock for reading
                if (! flock($lock, LOCK_SH)) {
                    return false;
                }

                // Re-check existence after acquiring lock
                if (! file_exists($pidFilePath)) {
                    return false;
                }

                $content = file_get_contents($pidFilePath);
                if ($content === false) {
                    return false;
                }
            } finally {
                // Release lock and close lock file
                flock($lock, LOCK_UN);
                fclose($lock);
            }
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
        $result = shell_exec(sprintf('ps -p %d -o pid=', $pid));

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
        $socket = @stream_socket_client(sprintf('tcp://%s:%d', $this->ip, $port), $errno, $errstr, 0.1);
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
        $host = sprintf('%s:%d', $this->ip, $port);

        while ($waited < $maxWait) {
            $socket = @stream_socket_client('tcp://'.$host, $errno, $errstr, 1);
            if ($socket !== false) {
                fclose($socket);

                return; // Server is ready
            }

            usleep(100000); // 100ms
            $waited += 0.1;
        }

        throw new \RuntimeException(sprintf('Runner not ready on %s:%d after %d seconds', $this->ip, $port, $maxWait));
    }

    /**
     * Connect to the runner via TCP.
     *
     * @throws \RuntimeException If connection fails
     */
    public function connect(int $port): void
    {
        $socket = @stream_socket_client(sprintf('tcp://%s:%d', $this->ip, $port), $errno, $errstr, 5);

        if ($socket === false) {
            throw new \RuntimeException(sprintf('Failed to connect to runner on %s:%d: %s (%s)', $this->ip, $port, $errstr, $errno));
        }

        // Keep socket in blocking mode initially for reliable attach
        // Will be set to non-blocking after successful attach
        stream_set_blocking($socket, true);

        $this->socket = $socket;
        $this->port = $port;
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
     * Actively check if the connection is still alive.
     * Uses socket_read with MSG_PEEK to detect closed connections without consuming data.
     * Updates the connected state and returns the result.
     */
    public function checkConnection(): bool
    {
        if ($this->socket === null) {
            $this->connected = false;

            return false;
        }

        if (stream_get_meta_data($this->socket)['timed_out']) {
            $this->connected = false;

            return false;
        }

        // Try to read with MSG_PEEK - this forces the OS to check the connection state
        // without consuming any actual data from the buffer
        $socketResource = socket_import_stream($this->socket);
        if ($socketResource === false) {
            // Can't import stream, fall back to feof check
            if (feof($this->socket)) {
                $this->connected = false;

                return false;
            }

            return $this->connected;
        }

        // Set non-blocking for the peek
        socket_set_nonblock($socketResource);

        // MSG_PEEK (2) + MSG_DONTWAIT (64) = 66
        $result = @socket_recv($socketResource, $buf, 1, MSG_PEEK | MSG_DONTWAIT);

        // socket_recv returns:
        // - false on error (connection lost)
        // - 0 when connection is closed gracefully
        // - positive number if data is available (connection alive)
        // - false with EAGAIN/EWOULDBLOCK if no data but connection alive
        if ($result === false) {
            $error = socket_last_error($socketResource);
            socket_clear_error($socketResource);

            // EAGAIN (11) or EWOULDBLOCK (11 on Linux, 35 on macOS) means no data but connection OK
            if ($error === 11 || $error === 35 || $error === SOCKET_EAGAIN || $error === SOCKET_EWOULDBLOCK) {
                return $this->connected;
            }

            // Any other error means connection is dead
            $this->connected = false;

            return false;
        }

        if ($result === 0) {
            // Graceful close - peer sent FIN
            $this->connected = false;

            return false;
        }

        // Data available, connection is alive
        return $this->connected;
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

        // Wait for both Hello and Snapshot events
        $deadline = time() + 5;
        $gotHello = false;
        $gotSnapshot = false;

        // Set up blocking reads with short timeout
        stream_set_blocking($this->socket, true);
        stream_set_timeout($this->socket, 0, 100000); // 100ms read timeout

        while (time() < $deadline && (! $gotHello || ! $gotSnapshot)) {
            $event = $this->readEvent();
            if ($event instanceof IpcMessage) {
                if ($event->type() === 'hello') {
                    $gotHello = true;
                } elseif ($event->type() === 'snapshot') {
                    $gotSnapshot = true;
                    if ($event instanceof SnapshotEvent) {
                        $this->applySnapshotEvent($event);
                    }
                }
            }
        }

        if (! $gotHello) {
            throw new \RuntimeException('Did not receive HelloEvent from runner');
        }

        if (! $gotSnapshot) {
            throw new \RuntimeException('Did not receive SnapshotEvent from runner');
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

        while ($event instanceof IpcMessage) {
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
     * Also checks for connection loss and attempts reconnection if needed.
     *
     * @return array<IpcMessage> Array of received events
     */
    public function pollEvents(): array
    {
        // If disconnected, try to reconnect periodically
        if (! $this->connected || $this->socket === null) {
            $this->tryReconnect();

            return [];
        }

        // Actively check if connection is still alive (detects server shutdown)
        if (! $this->checkConnection()) {
            return [];
        }

        $events = [];

        while (($event = $this->readEvent()) instanceof IpcMessage) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Attempt to reconnect to the runner daemon.
     * Only tries every RECONNECT_INTERVAL seconds to avoid hammering.
     */
    private function tryReconnect(): void
    {
        if ($this->port === null) {
            return;
        }

        $now = microtime(true);
        if ($this->lastReconnectAttempt !== null && ($now - $this->lastReconnectAttempt) < self::RECONNECT_INTERVAL) {
            return;
        }

        $this->lastReconnectAttempt = $now;

        try {
            // Clean up old socket if exists
            if ($this->socket !== null) {
                @fclose($this->socket);
                $this->socket = null;
            }

            // Check if server is ready before attempting connection
            if (! $this->isServerReady($this->port)) {
                return;
            }

            // Try to connect and attach
            $this->connect($this->port);
            $this->attach();
        } catch (\Throwable) {
            // Silently fail - we'll try again later
            $this->connected = false;
        }
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
            'done_tasks' => $this->handleDoneTasksEvent($event),
            'blocked_tasks' => $this->handleBlockedTasksEvent($event),
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
     * Get runner status in one simple call.
     *
     * Handles connect, attach, snapshot, and disconnect internally.
     * Returns null if daemon is not running or connection fails.
     *
     * @return array{state: string, active_processes: int, pid: int}|null
     */
    public static function getStatus(string $pidFilePath): ?array
    {
        $client = new self;

        if (! $client->isRunnerAlive($pidFilePath)) {
            return null;
        }

        $pidData = json_decode(file_get_contents($pidFilePath) ?: '', true);
        if (! is_array($pidData) || ! isset($pidData['port'])) {
            return null;
        }

        try {
            $client->connect((int) $pidData['port']);
            $client->attach();

            $result = [
                'state' => $client->isPaused() ? 'PAUSED' : 'RUNNING',
                'active_processes' => count($client->getActiveProcesses()),
                'pid' => (int) ($pidData['pid'] ?? 0),
            ];

            $client->disconnect();

            return $result;
        } catch (\Throwable) {
            try {
                $client->disconnect();
            } catch (\Throwable) {
            }

            return null;
        }
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
    public function sendStop(string $mode = 'graceful'): void
    {
        $cmd = new StopCommand(
            mode: $mode,
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
            type: $taskData['type'] ?? null,
            complexity: $taskData['complexity'] ?? null,
            epicId: $taskData['epic_id'] ?? null,
            blockedBy: $taskData['blocked_by'] ?? null,
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
                if ($event instanceof IpcMessage) {
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
     * Detects disconnection and updates connected state.
     */
    private function readEvent(): ?IpcMessage
    {
        if ($this->socket === null) {
            return null;
        }

        $line = fgets($this->socket);
        if ($line === false) {
            // Check if connection is actually closed
            if (feof($this->socket)) {
                $this->connected = false;
            }

            return null;
        }

        return $this->protocol->decode(trim($line), $this->instanceId);
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

        // Update counts from snapshot (for footer display)
        $this->doneCount = $snapshot->doneCount;
        $this->blockedCount = $snapshot->blockedCount;

        // Clear cached lazy-loaded data (snapshot changed, cache may be stale)
        $this->doneTasks = null;
        $this->blockedTasks = null;
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

    /**
     * Handle done tasks event - store lazy-loaded done tasks.
     */
    private function handleDoneTasksEvent(IpcMessage $event): void
    {
        if (! $event instanceof DoneTasksEvent) {
            return;
        }

        $this->doneTasks = $event->tasks();
        $this->doneCount = $event->total();
    }

    /**
     * Handle blocked tasks event - store lazy-loaded blocked tasks.
     */
    private function handleBlockedTasksEvent(IpcMessage $event): void
    {
        if (! $event instanceof BlockedTasksEvent) {
            return;
        }

        $this->blockedTasks = $event->tasks();
        $this->blockedCount = $event->total();
    }

    /**
     * Request done tasks from runner.
     */
    public function requestDoneTasks(): void
    {
        $cmd = new RequestDoneTasksCommand(
            timestamp: new DateTimeImmutable,
            instanceId: $this->instanceId
        );
        $this->sendMessage($cmd);
    }

    /**
     * Request blocked tasks from runner.
     */
    public function requestBlockedTasks(): void
    {
        $cmd = new RequestBlockedTasksCommand(
            timestamp: new DateTimeImmutable,
            instanceId: $this->instanceId
        );
        $this->sendMessage($cmd);
    }

    /**
     * Get done tasks (lazy-loaded).
     * Returns null if not yet loaded, array of tasks if loaded.
     */
    public function getDoneTasks(): ?array
    {
        return $this->doneTasks;
    }

    /**
     * Get blocked tasks (lazy-loaded).
     * Returns null if not yet loaded, array of tasks if loaded.
     */
    public function getBlockedTasks(): ?array
    {
        return $this->blockedTasks;
    }

    /**
     * Get done task count from snapshot (for footer display).
     */
    public function getDoneCount(): int
    {
        return $this->doneCount;
    }

    /**
     * Get blocked task count from snapshot (for footer display).
     */
    public function getBlockedCount(): int
    {
        return $this->blockedCount;
    }

    /**
     * Check if done tasks have been loaded.
     */
    public function hasDoneTasks(): bool
    {
        return $this->doneTasks !== null;
    }

    /**
     * Check if blocked tasks have been loaded.
     */
    public function hasBlockedTasks(): bool
    {
        return $this->blockedTasks !== null;
    }

    /**
     * Clear cached done tasks (e.g., when snapshot changes).
     */
    public function clearDoneTasks(): void
    {
        $this->doneTasks = null;
    }

    /**
     * Clear cached blocked tasks (e.g., when snapshot changes).
     */
    public function clearBlockedTasks(): void
    {
        $this->blockedTasks = null;
    }
}
