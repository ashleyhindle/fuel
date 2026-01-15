<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Ipc\Client\ConsumeIpcClient;
use App\Ipc\Commands\BrowserHtmlCommand as IpcBrowserHtmlCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeProcessManager;
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
    public function handle(ConsumeProcessManager $processManager, ConsumeIpcClient $ipcClient): int
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

        // Ensure fuel consume is running
        if (! $processManager->isRunning()) {
            if ($this->option('json')) {
                $this->outputJson([
                    'error' => 'Fuel consume is not running. Start it first with: fuel consume',
                ], self::FAILURE);

                return self::FAILURE;
            }
            $this->error('Fuel consume is not running. Start it first with: fuel consume');

            return self::FAILURE;
        }

        // Connect to IPC
        $ipcClient->connect();

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
                    if ($message instanceof BrowserResponseEvent
                        && $message->requestId === $command->getRequestId()) {
                        if ($message->error) {
                            if ($this->option('json')) {
                                $this->outputJson([
                                    'error' => $message->error,
                                ], self::FAILURE);

                                return self::FAILURE;
                            }
                            $this->error($message->error);

                            return self::FAILURE;
                        }

                        // Output the HTML content
                        if ($this->option('json')) {
                            $this->outputJson($message->data);

                            return self::SUCCESS;
                        }

                        $this->info($message->data['html'] ?? '');

                        return self::SUCCESS;
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
