<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserPageCommand as BrowserPageIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserPageCommand extends BrowserCommand
{
    protected $signature = 'browser:page
        {context_id : Browser context ID}
        {page_id : Page ID to create}
        {--json : Output as JSON}';

    protected $description = 'Create a new page in a browser context via the consume daemon';

    private ?string $contextId = null;

    private ?string $pageId = null;

    /**
     * Prepare command arguments before building IPC command.
     */
    public function handle(): int
    {
        $this->contextId = $this->argument('context_id');
        $this->pageId = $this->argument('page_id');

        return parent::handle();
    }

    /**
     * Build the BrowserPage IPC command.
     */
    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        return new BrowserPageIpcCommand(
            contextId: $this->contextId,
            pageId: $this->pageId,
            timestamp: $timestamp,
            instanceId: $instanceId,
            requestId: $requestId
        );
    }

    /**
     * Handle the successful response from the browser daemon.
     */
    protected function handleSuccess(BrowserResponseEvent $response): void
    {
        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'context_id' => $this->contextId,
                'page_id' => $this->pageId,
                'result' => $response->result,
            ]);
        } else {
            $this->info(sprintf("Page '%s' created in context '%s' successfully", $this->pageId, $this->contextId));
            if ($response->result !== null) {
                $this->line('Result: '.json_encode($response->result, JSON_PRETTY_PRINT));
            }
        }
    }
}
