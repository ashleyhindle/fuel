<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Ipc\Commands\BrowserScreenshotCommand as BrowserScreenshotIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use DateTimeImmutable;
use LaravelZero\Framework\Commands\Command;

class BrowserScreenshotCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'browser:screenshot
        {page_id : Page ID to screenshot}
        {--path= : Path to save the screenshot (optional, returns base64 if omitted)}
        {--full-page : Capture full scrollable page}
        {--json : Output as JSON}';

    protected $description = 'Take a screenshot of a browser page via the consume daemon';

    public function handle(): int
    {
        $pageId = $this->argument('page_id');
        $path = $this->option('path');
        $fullPage = $this->option('full-page');

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

            // Send BrowserScreenshot command with request ID
            $protocol = new \App\Services\ConsumeIpcProtocol;
            $requestId = $protocol->generateRequestId();

            $command = new BrowserScreenshotIpcCommand(
                pageId: $pageId,
                path: $path,
                fullPage: $fullPage,
                timestamp: new DateTimeImmutable,
                instanceId: $client->getInstanceId(),
                requestId: $requestId
            );

            $client->sendCommand($command);

            // Wait for BrowserResponseEvent with matching requestId
            $response = $this->waitForBrowserResponse($client, $requestId, 30);

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
                    'path' => $path,
                    'full_page' => $fullPage,
                    'result' => $response->result,
                ]);
            } else {
                if ($path !== null) {
                    $this->info("Screenshot of page '{$pageId}' saved to '{$path}'");
                } else {
                    $this->info("Screenshot of page '{$pageId}' captured successfully");
                }
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
