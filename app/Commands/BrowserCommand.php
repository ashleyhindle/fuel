<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use App\Services\ConsumeIpcClient;
use App\Services\ConsumeIpcProtocol;
use App\Services\FuelContext;
use DateTimeImmutable;
use LaravelZero\Framework\Commands\Command;

/**
 * Abstract base class for browser-related commands that communicate with the consume daemon.
 *
 * This class provides common boilerplate for:
 * - Checking if the daemon is running
 * - Connecting and attaching to the daemon
 * - Generating request IDs
 * - Sending IPC commands
 * - Waiting for browser responses
 * - Disconnecting from the daemon
 * - Handling output (JSON or text)
 */
abstract class BrowserCommand extends Command
{
    use HandlesJsonOutput;

    /**
     * Build the specific IPC command for this browser operation.
     *
     * @param  string  $requestId  The generated request ID for tracking the response
     * @param  string  $instanceId  The instance ID from the IPC client
     * @param  DateTimeImmutable  $timestamp  The timestamp for the command
     * @return IpcMessage The IPC command to send to the daemon
     */
    abstract protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage;

    /**
     * Handle the successful response from the browser daemon.
     *
     * @param  BrowserResponseEvent  $response  The successful response event
     */
    abstract protected function handleSuccess(BrowserResponseEvent $response): void;

    /**
     * Get the timeout in seconds for waiting for the browser response.
     * Override this in subclasses if you need a different timeout.
     *
     * @return int Timeout in seconds
     */
    protected function getResponseTimeout(): int
    {
        return 10;
    }

    /**
     * Main command handler with common boilerplate for browser commands.
     */
    public function handle(): int
    {
        // Connect to daemon
        $client = app(ConsumeIpcClient::class);

        // Check if daemon is running
        $pidFilePath = app(FuelContext::class)->getPidFilePath();
        if (! $client->isRunnerAlive($pidFilePath)) {
            return $this->outputError('Consume daemon is not running. Start it with: fuel consume');
        }

        try {
            // Read port from PID file
            $pidData = json_decode(file_get_contents($pidFilePath), true);
            $port = $pidData['port'] ?? 0;

            if ($port === 0) {
                return $this->outputError('Invalid port in PID file');
            }

            // Connect and attach
            $client->connect($port);
            $client->attach();

            // Generate request ID
            $protocol = new ConsumeIpcProtocol;
            $requestId = $protocol->generateRequestId();

            // Build the IPC command (may throw InvalidArgumentException for validation errors)
            try {
                $command = $this->buildIpcCommand(
                    $requestId,
                    $client->getInstanceId(),
                    new DateTimeImmutable
                );
            } catch (\InvalidArgumentException $e) {
                // Return validation errors directly without wrapping
                $client->detach();
                $client->disconnect();

                return $this->outputError($e->getMessage());
            }

            $client->sendCommand($command);

            // Wait for BrowserResponseEvent with matching requestId
            $response = $this->waitForBrowserResponse($client, $requestId, $this->getResponseTimeout());

            $client->detach();
            $client->disconnect();

            if (! $response instanceof BrowserResponseEvent) {
                return $this->outputError('Timeout waiting for browser response');
            }

            if (! $response->success) {
                return $this->outputError($response->error ?? 'Browser operation failed');
            }

            // Handle the successful response
            $this->handleSuccess($response);

            return self::SUCCESS;

        } catch (\Throwable $throwable) {
            return $this->outputError('Failed to communicate with daemon: '.$throwable->getMessage());
        }
    }

    /**
     * Wait for a BrowserResponseEvent with matching request ID.
     *
     * @param  ConsumeIpcClient  $client  The IPC client to poll events from
     * @param  string  $requestId  The request ID to match
     * @param  int  $timeoutSeconds  Timeout in seconds
     * @return BrowserResponseEvent|null The matching response event or null if timeout
     */
    protected function waitForBrowserResponse(
        ConsumeIpcClient $client,
        string $requestId,
        int $timeoutSeconds
    ): ?BrowserResponseEvent {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $events = $client->pollEvents();

            foreach ($events as $event) {
                if ($event instanceof BrowserResponseEvent && $event->requestId() === $requestId) {
                    return $event;
                }

                // Apply other events to maintain state
                if (! $event instanceof BrowserResponseEvent) {
                    $client->applyEvent($event);
                }
            }

            usleep(50000); // 50ms
        }

        return null;
    }
}
