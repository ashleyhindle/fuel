<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Ipc\Commands\BrowserTypeCommand as BrowserTypeIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use App\Services\ConsumeIpcProtocol;
use App\Services\FuelContext;
use DateTimeImmutable;
use LaravelZero\Framework\Commands\Command;

class BrowserTypeCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'browser:type
        {page_id : Page ID to type on}
        {text : Text to type}
        {selector? : CSS selector of element to type into}
        {--ref= : Element ref from snapshot (e.g. @e2)}
        {--delay=0 : Delay between keystrokes in milliseconds}
        {--json : Output as JSON}';

    protected $description = 'Type text into an element on a browser page';

    /**
     * Handle the command.
     */
    public function handle(): int
    {
        $pageId = $this->argument('page_id');
        $selector = $this->argument('selector');
        $text = $this->argument('text');
        $ref = $this->option('ref');
        $delay = (int) $this->option('delay');

        // Validate that either selector or ref is provided
        if (! $selector && ! $ref) {
            return $this->outputError('Must provide either a selector or --ref option');
        }

        if ($selector && $ref) {
            return $this->outputError('Cannot provide both selector and --ref option');
        }

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
            $client->sendCommand(new BrowserTypeIpcCommand(
                pageId: $pageId,
                selector: $selector,
                text: $text,
                ref: $ref,
                delay: $delay,
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
                return $this->outputError($response->error ?? 'Type failed');
            }

            $this->outputTypeSuccess($selector ?? $ref, $text);

            return self::SUCCESS;

        } catch (\Throwable $e) {
            return $this->outputError('Failed to communicate with daemon: '.$e->getMessage());
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
     * Output type success message.
     */
    private function outputTypeSuccess(string $target, string $text): void
    {
        $displayText = strlen($text) > 50 ? substr($text, 0, 47).'...' : $text;

        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'message' => "Typed into $target: $displayText",
            ]);
        } else {
            $this->info("✓ Typed into $target: $displayText");
        }
    }
}
