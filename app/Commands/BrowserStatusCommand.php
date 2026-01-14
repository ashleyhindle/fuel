<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Ipc\Commands\BrowserStatusCommand as BrowserStatusIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use DateTimeImmutable;
use LaravelZero\Framework\Commands\Command;

class BrowserStatusCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'browser:status
        {--json : Output as JSON}';

    protected $description = 'Get browser daemon status via the consume daemon';

    public function handle(): int
    {
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

            // Send BrowserStatus command with request ID
            $protocol = new \App\Services\ConsumeIpcProtocol;
            $requestId = $protocol->generateRequestId();

            $command = new BrowserStatusIpcCommand(
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

            // Extract status data from result
            $result = $response->result ?? [];
            $browserLaunched = $result['browserLaunched'] ?? false;
            $contextsCount = $result['contextsCount'] ?? 0;
            $pagesCount = $result['pagesCount'] ?? 0;

            // Output status
            if ($this->option('json')) {
                $this->outputJson([
                    'success' => true,
                    'browserLaunched' => $browserLaunched,
                    'contextsCount' => $contextsCount,
                    'pagesCount' => $pagesCount,
                ]);
            } else {
                $this->info('Browser Daemon Status:');
                $this->line('  Browser Launched: '.($browserLaunched ? 'Yes' : 'No'));
                $this->line('  Contexts: '.$contextsCount);
                $this->line('  Pages: '.$pagesCount);
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
