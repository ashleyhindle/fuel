<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Ipc\Commands\BrowserTextCommand as IpcBrowserTextCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use App\Services\ConsumeIpcProtocol;
use App\Services\FuelContext;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class BrowserTextCommand extends Command
{
    use HandlesJsonOutput;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'browser:text
        {page_id : The ID of the page to get text from}
        {selector? : CSS selector to target element}
        {--ref= : Element reference from snapshot (e.g. @e1)}
        {--json : Output as JSON}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Get text content from an element on a page';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pageId = $this->argument('page_id');
        $selector = $this->argument('selector');
        $ref = $this->option('ref');

        // Validate that either selector or ref is provided
        if (! $selector && ! $ref) {
            if ($this->option('json')) {
                $this->outputJson([
                    'error' => 'Either selector or --ref must be provided',
                ], self::FAILURE);

                return self::FAILURE;
            }
            $this->error('Either selector or --ref must be provided');

            return self::FAILURE;
        }

        $ipcClient = app(ConsumeIpcClient::class);
        $protocol = new ConsumeIpcProtocol;
        $pidFilePath = app(FuelContext::class)->getPidFilePath();

        // Ensure fuel consume is running
        if (! $ipcClient->isRunnerAlive($pidFilePath)) {
            if ($this->option('json')) {
                $this->outputJson([
                    'error' => 'Fuel consume is not running. Start it first with: fuel consume',
                ], self::FAILURE);

                return self::FAILURE;
            }
            $this->error('Fuel consume is not running. Start it first with: fuel consume');

            return self::FAILURE;
        }

        // Get port from PID file and connect
        try {
            $pidData = json_decode(file_get_contents($pidFilePath), true);
            $port = $pidData['port'] ?? 0;
            if ($port === 0) {
                if ($this->option('json')) {
                    $this->outputJson([
                        'error' => 'Invalid port in PID file',
                    ], self::FAILURE);

                    return self::FAILURE;
                }
                $this->error('Invalid port in PID file');

                return self::FAILURE;
            }

            $ipcClient->connect($port);
            $ipcClient->attach();

            $requestId = $protocol->generateRequestId();
            $ipcClient->sendCommand(new IpcBrowserTextCommand(
                pageId: $pageId,
                selector: $selector,
                ref: $ref,
                timestamp: new DateTimeImmutable,
                instanceId: $ipcClient->getInstanceId(),
                requestId: $requestId
            ));

            $response = $this->waitForResponse($ipcClient, $requestId, 5);

            $ipcClient->detach();
            $ipcClient->disconnect();

            if ($response === null) {
                if ($this->option('json')) {
                    $this->outputJson([
                        'error' => 'Timeout waiting for response',
                    ], self::FAILURE);

                    return self::FAILURE;
                }
                $this->error('Timeout waiting for response');

                return self::FAILURE;
            }

            if (! $response->success) {
                if ($this->option('json')) {
                    $this->outputJson([
                        'error' => $response->error ?? 'Failed to get text',
                    ], self::FAILURE);

                    return self::FAILURE;
                }
                $this->error($response->error ?? 'Failed to get text');

                return self::FAILURE;
            }

            // Output the text content
            if ($this->option('json')) {
                $this->outputJson($response->result);

                return self::SUCCESS;
            }

            $this->info($response->result['text'] ?? '');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            if ($this->option('json')) {
                $this->outputJson([
                    'error' => 'Failed to communicate with daemon: '.$e->getMessage(),
                ], self::FAILURE);

                return self::FAILURE;
            }
            $this->error('Failed to communicate with daemon: '.$e->getMessage());

            return self::FAILURE;
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
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
