<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Ipc\Commands\BrowserCloseCommand as BrowserCloseIpcCommand;
use App\Ipc\Commands\BrowserCreateCommand as BrowserCreateIpcCommand;
use App\Ipc\Commands\BrowserGotoCommand as BrowserGotoIpcCommand;
use App\Ipc\Commands\BrowserScreenshotCommand as BrowserScreenshotIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use App\Services\ConsumeIpcProtocol;
use App\Services\FuelContext;
use DateTimeImmutable;
use LaravelZero\Framework\Commands\Command;

class BrowserScreenshotCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'browser:screenshot
        {page_id? : Page ID to screenshot (optional if --url provided)}
        {path? : Path to save screenshot (omit to return base64)}
        {--url= : URL to screenshot (creates temporary context, takes screenshot, closes)}
        {--path= : Path to save screenshot (alternative to positional argument)}
        {--full-page : Capture full scrollable page}
        {--width=1280 : Viewport width (only with --url)}
        {--height=720 : Viewport height (only with --url)}
        {--dark : Use dark color scheme (only with --url)}
        {--json : Output as JSON}';

    protected $description = 'Take a screenshot of a browser page via the consume daemon';

    private ?string $pageId = null;

    private ?string $path = null;

    private bool $fullPage = false;

    private ?string $url = null;

    private int $width = 1280;

    private int $height = 720;

    private ?string $colorScheme = null;

    /**
     * Handle the command - either quick screenshot with URL or existing page screenshot.
     */
    public function handle(): int
    {
        $this->url = $this->option('url');
        $this->fullPage = (bool) $this->option('full-page');

        // When --url is provided, first positional is path (no page_id needed)
        // Otherwise, first positional is page_id and second is path
        if ($this->url !== null) {
            $this->pageId = null;
            $this->path = $this->argument('page_id') ?? $this->argument('path') ?? $this->option('path');
        } else {
            $this->pageId = $this->argument('page_id');
            $this->path = $this->argument('path') ?? $this->option('path');
        }
        $this->width = (int) $this->option('width');
        $this->height = (int) $this->option('height');
        $this->colorScheme = $this->option('dark') ? 'dark' : 'light';

        // If URL provided without page_id, do quick screenshot flow
        if ($this->url !== null && $this->pageId === null) {
            return $this->handleQuickScreenshot();
        }

        // Otherwise, require page_id for existing page screenshot
        if ($this->pageId === null) {
            return $this->outputError('Either page_id or --url is required');
        }

        return $this->handleExistingPageScreenshot();
    }

    /**
     * Handle quick screenshot: create context, goto URL, screenshot, close.
     */
    private function handleQuickScreenshot(): int
    {
        $client = app(ConsumeIpcClient::class);
        $protocol = new ConsumeIpcProtocol;

        // Check if daemon is running
        $pidFilePath = app(FuelContext::class)->getPidFilePath();
        if (! $client->isRunnerAlive($pidFilePath)) {
            return $this->outputError('Consume daemon is not running. Start it with: fuel consume');
        }

        try {
            // Read port and connect
            $pidData = json_decode(file_get_contents($pidFilePath), true);
            $port = $pidData['port'] ?? 0;
            if ($port === 0) {
                return $this->outputError('Invalid port in PID file');
            }

            $client->connect($port);
            $client->attach();

            // Generate unique context/page IDs for this quick screenshot
            $contextId = 'quick-'.substr(md5(uniqid()), 0, 8);
            $pageId = $contextId.'-page';

            // Step 1: Create context with viewport and color scheme
            $requestId = $protocol->generateRequestId();
            $client->sendCommand(new BrowserCreateIpcCommand(
                contextId: $contextId,
                pageId: $pageId,
                viewport: ['width' => $this->width, 'height' => $this->height],
                userAgent: null,
                colorScheme: $this->colorScheme,
                timestamp: new DateTimeImmutable,
                instanceId: $client->getInstanceId(),
                requestId: $requestId
            ));

            $response = $this->waitForResponse($client, $requestId, 10);
            if (! $response instanceof BrowserResponseEvent || ! $response->success) {
                $client->disconnect();

                return $this->outputError('Failed to create browser context: '.($response->error ?? 'timeout'));
            }

            // Step 2: Navigate to URL
            $requestId = $protocol->generateRequestId();
            $client->sendCommand(new BrowserGotoIpcCommand(
                pageId: $pageId,
                url: $this->url,
                waitUntil: 'load',
                timeout: null,
                timestamp: new DateTimeImmutable,
                instanceId: $client->getInstanceId(),
                requestId: $requestId
            ));

            $response = $this->waitForResponse($client, $requestId, 30);
            if (! $response instanceof BrowserResponseEvent || ! $response->success) {
                // Try to clean up context even if goto failed
                $this->closeContext($client, $protocol, $contextId);
                $client->disconnect();

                return $this->outputError('Failed to navigate to URL: '.($response->error ?? 'timeout'));
            }

            // Step 3: Take screenshot
            $requestId = $protocol->generateRequestId();
            $client->sendCommand(new BrowserScreenshotIpcCommand(
                pageId: $pageId,
                path: $this->path,
                fullPage: $this->fullPage,
                timestamp: new DateTimeImmutable,
                instanceId: $client->getInstanceId(),
                requestId: $requestId
            ));

            $response = $this->waitForResponse($client, $requestId, 30);
            $screenshotResult = $response;

            // Step 4: Close context (always try to clean up)
            $this->closeContext($client, $protocol, $contextId);

            $client->detach();
            $client->disconnect();

            if (! $screenshotResult instanceof BrowserResponseEvent || ! $screenshotResult->success) {
                return $this->outputError('Failed to take screenshot: '.($screenshotResult->error ?? 'timeout'));
            }

            // Output success
            $this->outputScreenshotSuccess($screenshotResult, $pageId);

            return self::SUCCESS;

        } catch (\Throwable $throwable) {
            return $this->outputError('Failed to communicate with daemon: '.$throwable->getMessage());
        }
    }

    /**
     * Handle screenshot of an existing page.
     */
    private function handleExistingPageScreenshot(): int
    {
        $client = app(ConsumeIpcClient::class);
        $protocol = new ConsumeIpcProtocol;

        // Check if daemon is running
        $pidFilePath = app(FuelContext::class)->getPidFilePath();
        if (! $client->isRunnerAlive($pidFilePath)) {
            return $this->outputError('Consume daemon is not running. Start it with: fuel consume');
        }

        try {
            $pidData = json_decode(file_get_contents($pidFilePath), true);
            $port = $pidData['port'] ?? 0;
            if ($port === 0) {
                return $this->outputError('Invalid port in PID file');
            }

            $client->connect($port);
            $client->attach();

            $requestId = $protocol->generateRequestId();
            $client->sendCommand(new BrowserScreenshotIpcCommand(
                pageId: $this->pageId,
                path: $this->path,
                fullPage: $this->fullPage,
                timestamp: new DateTimeImmutable,
                instanceId: $client->getInstanceId(),
                requestId: $requestId
            ));

            $response = $this->waitForResponse($client, $requestId, 30);

            $client->detach();
            $client->disconnect();

            if (! $response instanceof BrowserResponseEvent) {
                return $this->outputError('Timeout waiting for browser response');
            }

            if (! $response->success) {
                return $this->outputError($response->error ?? 'Screenshot failed');
            }

            $this->outputScreenshotSuccess($response, $this->pageId);

            return self::SUCCESS;

        } catch (\Throwable $throwable) {
            return $this->outputError('Failed to communicate with daemon: '.$throwable->getMessage());
        }
    }

    /**
     * Wait for a BrowserResponseEvent with matching request ID.
     */
    private function waitForResponse(ConsumeIpcClient $client, string $requestId, int $timeout): ?BrowserResponseEvent
    {
        $deadline = time() + $timeout;

        while (time() < $deadline) {
            $events = $client->pollEvents();

            foreach ($events as $event) {
                if ($event instanceof BrowserResponseEvent && $event->requestId() === $requestId) {
                    return $event;
                }

                if (! $event instanceof BrowserResponseEvent) {
                    $client->applyEvent($event);
                }
            }

            usleep(50000); // 50ms
        }

        return null;
    }

    /**
     * Close a browser context (best effort, ignores errors).
     */
    private function closeContext(ConsumeIpcClient $client, ConsumeIpcProtocol $protocol, string $contextId): void
    {
        try {
            $requestId = $protocol->generateRequestId();
            $client->sendCommand(new BrowserCloseIpcCommand(
                contextId: $contextId,
                timestamp: new DateTimeImmutable,
                instanceId: $client->getInstanceId(),
                requestId: $requestId
            ));

            // Wait briefly for close to complete
            $this->waitForResponse($client, $requestId, 5);
        } catch (\Throwable) {
            // Ignore cleanup errors
        }
    }

    /**
     * Output screenshot success message.
     */
    private function outputScreenshotSuccess(BrowserResponseEvent $response, string $pageId): void
    {
        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'url' => $this->url,
                'page_id' => $pageId,
                'path' => $this->path,
                'full_page' => $this->fullPage,
                'result' => $response->result,
            ]);
        } elseif ($this->path !== null) {
            $savedPath = $response->result['path'] ?? $this->path;
            $this->info(sprintf('Screenshot saved to %s', $savedPath));
        } elseif (isset($response->result['base64'])) {
            $this->line($response->result['base64']);
        } else {
            $this->info('Screenshot captured successfully');
            if ($response->result !== null) {
                $this->line('Result: '.json_encode($response->result, JSON_PRETTY_PRINT));
            }
        }
    }
}
