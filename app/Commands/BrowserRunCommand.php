<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserRunCommand as BrowserRunIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserRunCommand extends BrowserCommand
{
    protected $signature = 'browser:run
        {page_id : Page ID to run code in}
        {code : Playwright code to run (has access to `page` variable)}
        {--json : Output as JSON}';

    protected $description = 'Run Playwright code in a browser page via the consume daemon';

    private ?string $pageId = null;

    private ?string $code = null;

    /**
     * Prepare command arguments before building IPC command.
     */
    public function handle(): int
    {
        $this->pageId = $this->argument('page_id');
        $this->code = $this->argument('code');

        return parent::handle();
    }

    /**
     * Build the BrowserRun IPC command.
     */
    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        return new BrowserRunIpcCommand(
            pageId: $this->pageId,
            code: $this->code,
            timestamp: $timestamp,
            instanceId: $instanceId,
            requestId: $requestId
        );
    }

    /**
     * Get response timeout for run operations.
     */
    protected function getResponseTimeout(): int
    {
        return 30;
    }

    /**
     * Handle the successful response from the browser daemon.
     */
    protected function handleSuccess(BrowserResponseEvent $response): void
    {
        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'page_id' => $this->pageId,
                'result' => $response->result,
            ]);
        } else {
            $this->info(sprintf("Code executed successfully on page '%s'", $this->pageId));
            if ($response->result !== null) {
                $this->line('Result: '.json_encode($response->result, JSON_PRETTY_PRINT));
            }
        }
    }
}
