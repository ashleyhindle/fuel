<?php

declare(strict_types=1);

namespace App\Services;

use App\Ipc\IpcMessage;

final class ConsumeIpcServer
{
    /** @var resource|null */
    private $socket = null;

    /** @var array<string, array{stream: resource, buffer: string}> */
    private array $clients = [];

    private ConsumeIpcProtocol $protocol;

    private ConfigService $configService;

    private const MAX_BUFFER_SIZE = 10485760; // 10MB - Allow larger snapshots

    private ?int $tcpPort = null;

    public function __construct(?ConsumeIpcProtocol $protocol = null, ?ConfigService $configService = null)
    {
        $this->protocol = $protocol ?? new ConsumeIpcProtocol;
        $this->configService = $configService ?? app(ConfigService::class);
    }

    /**
     * Create TCP socket and start listening on configured port.
     */
    public function start(): void
    {
        $port = $this->configService->getConsumePort();
        $this->startTcpSocket($port);
    }

    /**
     * Close socket and disconnect all clients.
     */
    public function stop(): void
    {
        // Close all client connections
        foreach ($this->clients as $clientId => $client) {
            if (is_resource($client['stream'])) {
                fclose($client['stream']);
            }
        }
        $this->clients = [];

        // Close server socket
        if ($this->socket !== null && is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Non-blocking accept of new connections.
     * Assigns a unique client ID to each connection.
     */
    public function accept(): void
    {
        if ($this->socket === null) {
            return;
        }

        // Set non-blocking mode
        stream_set_blocking($this->socket, false);

        $clientStream = @stream_socket_accept($this->socket, 0);

        if ($clientStream === false) {
            return; // No pending connections
        }

        // Generate unique client ID
        $clientId = uniqid('client_', true);

        // Store client with empty buffer
        $this->clients[$clientId] = [
            'stream' => $clientStream,
            'buffer' => '',
        ];

        // Set client stream to non-blocking
        stream_set_blocking($clientStream, false);
    }

    /**
     * Broadcast a message to all connected clients.
     */
    public function broadcast(IpcMessage $message): void
    {
        $encoded = $this->protocol->encode($message);

        foreach ($this->clients as $clientId => $client) {
            $this->writeToClient($clientId, $encoded);
            // Force immediate flush for large messages
            $this->flushClientBuffer($clientId);
        }
    }

    /**
     * Send a message to a specific client.
     */
    public function sendTo(string $clientId, IpcMessage $message): void
    {
        if (! isset($this->clients[$clientId])) {
            return;
        }

        $encoded = $this->protocol->encode($message);
        $this->writeToClient($clientId, $encoded);
    }

    /**
     * Read commands from all clients (non-blocking).
     * Returns array of [clientId => IpcMessage[]].
     *
     * @return array<string, array<IpcMessage>>
     */
    public function poll(): array
    {
        $commands = [];

        foreach ($this->clients as $clientId => $client) {
            $messages = $this->readFromClient($clientId);
            if (! empty($messages)) {
                $commands[$clientId] = $messages;
            }
        }

        return $commands;
    }

    /**
     * Get the number of connected clients.
     */
    public function getClientCount(): int
    {
        return count($this->clients);
    }

    /**
     * Disconnect a client that has exceeded buffer limits or is slow.
     */
    public function disconnectSlowClient(string $clientId): void
    {
        if (! isset($this->clients[$clientId])) {
            return;
        }

        $client = $this->clients[$clientId];
        if (is_resource($client['stream'])) {
            fclose($client['stream']);
        }

        unset($this->clients[$clientId]);
    }

    /**
     * Start TCP socket on localhost with specified port.
     */
    private function startTcpSocket(int $port): void
    {
        $socket = @stream_socket_server(
            "tcp://127.0.0.1:{$port}",
            $errno,
            $errstr
        );

        if ($socket === false) {
            if ($errno === 98 || $errno === 48) { // EADDRINUSE (Linux: 98, macOS: 48)
                throw new \RuntimeException(
                    "Port {$port} is already in use. Check for existing runner or change port in config."
                );
            }
            throw new \RuntimeException("Failed to create TCP socket on port {$port}: $errstr ($errno)");
        }

        $this->socket = $socket;
        $this->tcpPort = $port;
    }

    /**
     * Write data to a client's buffer and flush what we can.
     * Disconnects client if buffer exceeds limit.
     */
    private function writeToClient(string $clientId, string $data): void
    {
        if (! isset($this->clients[$clientId])) {
            return;
        }

        // Add to buffer
        $this->clients[$clientId]['buffer'] .= $data;

        // Check buffer size limit
        if (strlen($this->clients[$clientId]['buffer']) > self::MAX_BUFFER_SIZE) {
            // Log warning about large buffer before disconnecting
            error_log("[IPC Server] Client $clientId buffer exceeded ".self::MAX_BUFFER_SIZE.' bytes, disconnecting');
            $this->disconnectSlowClient($clientId);

            return;
        }

        // Try to flush buffer multiple times if needed
        $this->flushClientBuffer($clientId);
    }

    /**
     * Flush as much of the client's write buffer as possible.
     * Attempts multiple writes for large buffers.
     */
    private function flushClientBuffer(string $clientId): void
    {
        if (! isset($this->clients[$clientId])) {
            return;
        }

        $client = $this->clients[$clientId];
        $buffer = $client['buffer'];

        if ($buffer === '') {
            return;
        }

        // Attempt to write the entire buffer in chunks if needed
        $totalToWrite = strlen($buffer);
        $totalWritten = 0;
        $maxAttempts = 10;
        $attempts = 0;

        while ($totalWritten < $totalToWrite && $attempts < $maxAttempts) {
            $remaining = substr($buffer, $totalWritten);
            $written = @fwrite($client['stream'], $remaining);

            if ($written === false) {
                // Write failed, disconnect client
                error_log("[IPC Server] Write failed for client $clientId after writing $totalWritten of $totalToWrite bytes");
                $this->disconnectSlowClient($clientId);

                return;
            }

            if ($written === 0) {
                // Can't write anymore right now, save remaining buffer
                break;
            }

            $totalWritten += $written;
            $attempts++;
        }

        // Remove written bytes from buffer
        $this->clients[$clientId]['buffer'] = substr($buffer, $totalWritten);

        if ($totalWritten > 0 && strlen($this->clients[$clientId]['buffer']) > 0) {
            // Log if we couldn't write everything
            error_log("[IPC Server] Partial write for client $clientId: wrote $totalWritten of $totalToWrite bytes, ".strlen($this->clients[$clientId]['buffer']).' bytes remaining in buffer');
        }
    }

    /**
     * Read available data from a client and parse into messages.
     * Returns array of decoded IpcMessage objects.
     *
     * @return array<IpcMessage>
     */
    private function readFromClient(string $clientId): array
    {
        if (! isset($this->clients[$clientId])) {
            return [];
        }

        $client = $this->clients[$clientId];
        $stream = $client['stream'];

        // Read available data
        $data = @fread($stream, 8192);

        if ($data === false || $data === '') {
            // Check if connection closed
            if (feof($stream)) {
                $this->disconnectSlowClient($clientId);
            }

            return [];
        }

        // Split by newlines to get complete messages
        $lines = explode("\n", $data);
        $messages = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Decode the message
            $message = $this->protocol->decode($line, $clientId);
            $messages[] = $message;
        }

        return $messages;
    }
}
