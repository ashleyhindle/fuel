<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Ipc\Commands\BrowserHtmlCommand as IpcBrowserHtmlCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use App\Services\FuelContext;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class BrowserHtmlCommand extends Command
{
    use HandlesJsonOutput;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'browser:html
        {page_id : The ID of the page to get HTML from}
        {selector? : CSS selector to target element}
        {--ref= : Element reference from snapshot (e.g. @e1)}
        {--inner : Return innerHTML instead of outerHTML}
        {--json : Output as JSON}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Get HTML content from an element on a page';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pageId = $this->argument('page_id');
        $selector = $this->argument('selector');
        $ref = $this->option('ref');
        $inner = $this->option('inner');

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
        } catch (\Throwable $e) {
            if ($this->option('json')) {
                $this->outputJson([
                    'error' => 'Failed to connect to daemon: '.$e->getMessage(),
                ], self::FAILURE);

                return self::FAILURE;
            }
            $this->error('Failed to connect to daemon: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            // Send browser html command
            $command = new IpcBrowserHtmlCommand(
                pageId: $pageId,
                selector: $selector,
                ref: $ref,
                inner: $inner
            );

            $ipcClient->send($command);

            // Wait for response
            $timeout = 5; // 5 seconds timeout
            $start = time();
            while (time() - $start < $timeout) {
                $messages = $ipcClient->receive();
                foreach ($messages as $message) {
                    // Check if this response matches our request
                    $messageRequestId = null;
                    if ($message instanceof BrowserResponseEvent) {
                        $messageRequestId = $message->getRequestId();
                    } elseif (isset($message->requestId)) {
                        $messageRequestId = $message->requestId;
                    }

                    if ($messageRequestId === $command->getRequestId()) {
                        // Handle error response
                        $error = null;
                        if ($message instanceof BrowserResponseEvent && $message->error) {
                            $error = $message->error;
                        } elseif (isset($message->error) && $message->error) {
                            $error = $message->error;
                        }

                        if ($error) {
                            if ($this->option('json')) {
                                $this->outputJson([
                                    'error' => $error,
                                ], self::FAILURE);

                                return self::FAILURE;
                            }
                            $this->error($error);

                            return self::FAILURE;
                        }

                        // Get data from response
                        $data = null;
                        if ($message instanceof BrowserResponseEvent && $message->result) {
                            $data = $message->result;
                        } elseif (isset($message->data)) {
                            $data = $message->data;
                        }

                        if ($data) {
                            // Output the HTML content
                            if ($this->option('json')) {
                                $this->outputJson($data);

                                return self::SUCCESS;
                            }

                            $this->info($data['html'] ?? '');

                            return self::SUCCESS;
                        }
                    }
                }
                usleep(100000); // 100ms
            }

            // Timeout
            if ($this->option('json')) {
                $this->outputJson([
                    'error' => 'Timeout waiting for response',
                ], self::FAILURE);

                return self::FAILURE;
            }
            $this->error('Timeout waiting for response');

            return self::FAILURE;
        } finally {
            $ipcClient->disconnect();
        }
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
