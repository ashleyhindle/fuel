<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserTextCommand as IpcBrowserTextCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserTextCommand extends BrowserCommand
{
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
        $selector = $this->argument('selector');
        $ref = $this->option('ref');

        // Validate that either selector or ref is provided
        if (! $selector && ! $ref) {
            return $this->outputError('Either selector or --ref must be provided');
        }

        return parent::handle();
    }

    /**
     * Build the specific IPC command for this browser operation.
     */
    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        $pageId = $this->argument('page_id');
        $selector = $this->argument('selector');
        $ref = $this->option('ref');

        return new IpcBrowserTextCommand(
            pageId: $pageId,
            selector: $selector,
            ref: $ref,
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
                'data' => $response->result ?? [],
            ]);
        } else {
            $result = $response->result ?? [];
            if (isset($result['text'])) {
                $this->info($result['text']);
            } else {
                $this->info('No text content found');
            }
        }
    }
}