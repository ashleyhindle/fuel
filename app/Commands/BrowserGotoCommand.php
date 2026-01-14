<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Ipc\Commands\BrowserGotoCommand as BrowserGotoIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use DateTimeImmutable;
use LaravelZero\Framework\Commands\Command;

class BrowserGotoCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'browser:goto
        {page_id : Page ID to navigate}
        {url : URL to navigate to}
        {--wait-until=load : Wait until this event (load, domcontentloaded, networkidle)}
        {--timeout=30000 : Navigation timeout in milliseconds}
        {--json : Output as JSON}';

    protected $description = 'Navigate a browser page to a URL via the consume daemon';

    public function handle(): int
    {
        $pageId = $this->argument('page_id');
        $url = $this->argument('url');
        $waitUntil = $this->option('wait-until');
        $timeout = (int) $this->option('timeout');

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

            // Send BrowserGoto command with request ID
            $protocol = new \App\Services\ConsumeIpcProtocol;
            $requestId = $protocol->generateRequestId();

            $command = new BrowserGotoIpcCommand(
                pageId: $pageId,
                url: $url,
                waitUntil: $waitUntil,
                timeout: $timeout,
                timestamp: new DateTimeImmutable,
                instanceId: $client->getInstanceId(),
                requestId: $requestId
            );

            $client->sendCommand($command);

            // Wait for BrowserResponseEvent with matching requestId
            // Use timeout from option + 2 seconds buffer for IPC overhead
            $responseTimeout = (int) ceil($timeout / 1000) + 2;
            $response = $this->waitForBrowserResponse($client, $requestId, $responseTimeout);

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
                    'page_id' => $pageId,
                    'url' => $url,
                    'result' => $response->result,
                ]);
            } else {
                $this->info("Page '{$pageId}' navigated to '{$url}' successfully");
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
