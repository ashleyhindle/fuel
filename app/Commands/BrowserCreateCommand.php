<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Ipc\Commands\BrowserCreateCommand as BrowserCreateIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use DateTimeImmutable;
use LaravelZero\Framework\Commands\Command;

class BrowserCreateCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'browser:create
        {context_id : Browser context ID to create}
        {--viewport= : Viewport size as JSON (e.g., {"width":1280,"height":720})}
        {--user-agent= : Custom user agent string}
        {--json : Output as JSON}';

    protected $description = 'Create a new browser context via the consume daemon';

    public function handle(): int
    {
        $contextId = $this->argument('context_id');
        $viewportOption = $this->option('viewport');
        $userAgent = $this->option('user-agent');

        // Parse viewport JSON if provided
        $viewport = null;
        if ($viewportOption !== null) {
            $decoded = json_decode($viewportOption, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->outputError('Invalid viewport JSON: '.json_last_error_msg());
            }
            $viewport = $decoded;
        }

        // Connect to daemon
        $client = app(ConsumeIpcClient::class);

        // Check if daemon is running
        $pidFilePath = base_path('.fuel/consume-runner.pid');
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

            // Send BrowserCreate command with request ID
            $protocol = new \App\Services\ConsumeIpcProtocol;
            $requestId = $protocol->generateRequestId();

            $command = new BrowserCreateIpcCommand(
                contextId: $contextId,
                viewport: $viewport,
                userAgent: $userAgent,
                timestamp: new DateTimeImmutable,
                instanceId: $client->getInstanceId(),
                requestId: $requestId
            );

            $client->sendCommand($command);

            // Wait for BrowserResponseEvent with matching requestId
            $response = $this->waitForBrowserResponse($client, $requestId, 10);

            $client->detach();
            $client->disconnect();

            if ($response === null) {
                return $this->outputError('Timeout waiting for browser response');
            }

            if (! $response->success) {
                return $this->outputError($response->error ?? 'Browser operation failed');
            }

            // Output success
            if ($this->option('json')) {
                $this->outputJson([
                    'success' => true,
                    'context_id' => $contextId,
                    'result' => $response->result,
                ]);
            } else {
                $this->info("Browser context '{$contextId}' created successfully");
                if ($response->result !== null) {
                    $this->line('Result: '.json_encode($response->result, JSON_PRETTY_PRINT));
                }
            }

            return self::SUCCESS;

        } catch (\Throwable $e) {
            return $this->outputError('Failed to communicate with daemon: '.$e->getMessage());
        }
    }

    /**
     * Wait for a BrowserResponseEvent with matching request ID.
     */
    private function waitForBrowserResponse(ConsumeIpcClient $client, string $requestId, int $timeoutSeconds): ?BrowserResponseEvent
    {
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
