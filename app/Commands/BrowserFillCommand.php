<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Ipc\Commands\BrowserFillCommand as BrowserFillIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use App\Services\ConsumeIpcProtocol;
use App\Services\FuelContext;
use DateTimeImmutable;
use LaravelZero\Framework\Commands\Command;

class BrowserFillCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'browser:fill
        {page_id : Page ID to fill input on}
        {selector? : CSS selector of input to fill}
        {--value= : Value to fill into the input (required)}
        {--ref= : Element ref from snapshot (e.g. @e2)}
        {--json : Output as JSON}';

    protected $description = 'Fill an input field on a browser page';

    /**
     * Handle the command.
     */
    public function handle(): int
    {
        $pageId = $this->argument('page_id');
        $selector = $this->argument('selector');
        $value = $this->option('value');
        $ref = $this->option('ref');

        // Validate value is provided
        if (! $value) {
            return $this->outputError('--value option is required');
        }

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
            $client->sendCommand(new BrowserFillIpcCommand(
                pageId: $pageId,
                selector: $selector,
                value: $value,
                ref: $ref,
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
                return $this->outputError($response->error ?? 'Fill failed');
            }

            $this->outputFillSuccess($selector ?? $ref, $value);

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
     * Output fill success message.
     */
    private function outputFillSuccess(string $target, string $value): void
    {
        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'message' => "Filled $target with: $value",
            ]);
        } else {
            $this->info("✓ Filled $target with: $value");
        }
    }
}
