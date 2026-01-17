<?php

declare(strict_types=1);

namespace App\Services;

use App\Ipc\IpcMessage;

final class ConsumeIpcServer
{
    /** @var resource|null */
    private $socket;

    /** @var array<string, array{stream: resource, buffer: string, readBuffer: string}> */
    private array $clients = [];

    private readonly ConfigService $configService;

    private const MAX_BUFFER_SIZE = 10485760;

    public function __construct(private readonly ConsumeIpcProtocol $protocol = new ConsumeIpcProtocol, ?ConfigService $configService = null)
    {
        $this->configService = $configService ?? app(ConfigService::class);
    }

    /**
     * Create TCP socket and start listening on configured port.
     *
     * @param  int|null  $port  Port number to bind to (null = use config)
     */
    public function start(?int $port = null): void
    {
        $port ??= $this->configService->getConsumePort();
        $this->startTcpSocket($port);
    }

    /**
     * Close socket and disconnect all clients.
     */
    public function stop(): void
    {
        // Close all client connections
        foreach ($this->clients as $client) {
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

        // Store client with empty buffers (write buffer and read buffer)
        $this->clients[$clientId] = [
            'stream' => $clientStream,
            'buffer' => '',
            'readBuffer' => '',
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

        foreach (array_keys($this->clients) as $clientId) {
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

        foreach (array_keys($this->clients) as $clientId) {
            $messages = $this->readFromClient($clientId);
            if ($messages !== []) {
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
     * Start TCP socket on all interfaces with specified port.
     */
    private function startTcpSocket(int $port): void
    {
        $socket = @stream_socket_server(
            'tcp://0.0.0.0:'.$port,
            $errno,
            $errstr
        );

        if ($socket === false) {
            if ($errno === 98 || $errno === 48) { // EADDRINUSE (Linux: 98, macOS: 48)
                throw new \RuntimeException(
                    sprintf('Port %d is already in use. Check for existing runner or change port in config.', $port)
                );
            }

            throw new \RuntimeException(sprintf('Failed to create TCP socket on port %d: %s (%s)', $port, $errstr, $errno));
        }

        $this->socket = $socket;
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

        // Partial writes are normal for large messages - buffer will be flushed on next tick
    }

    /**
     * Read available data from a client and parse into messages.
     * Uses read buffer to handle partial messages split across reads.
     * Returns array of decoded IpcMessage objects.
     *
     * @return array<IpcMessage>
     */
    private function readFromClient(string $clientId): array
    {
        if (! isset($this->clients[$clientId])) {
            return [];
        }

        $stream = $this->clients[$clientId]['stream'];

        // Read available data
        $data = @fread($stream, 8192);

        if ($data === false || $data === '') {
            // Check if connection closed
            if (feof($stream)) {
                $this->disconnectSlowClient($clientId);
            }

            return [];
        }

        // Append to read buffer
        $this->clients[$clientId]['readBuffer'] .= $data;

        // Check read buffer size limit
        if (strlen($this->clients[$clientId]['readBuffer']) > self::MAX_BUFFER_SIZE) {
            $this->disconnectSlowClient($clientId);

            return [];
        }

        // Extract complete messages (delimited by newlines)
        $messages = [];
        $buffer = $this->clients[$clientId]['readBuffer'];

        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);

            $line = trim($line);
            if ($line !== '') {
                // Decode the message
                $message = $this->protocol->decode($line, $clientId);
                $messages[] = $message;
            }
        }

        // Store remaining partial data back in read buffer
        $this->clients[$clientId]['readBuffer'] = $buffer;

        return $messages;
    }
}
