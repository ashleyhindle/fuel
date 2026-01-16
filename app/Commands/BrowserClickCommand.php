<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserClickCommand as BrowserClickIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserClickCommand extends BrowserCommand
{
    protected $signature = 'browser:click
        {page_id : Page ID to click on}
        {selector? : CSS selector to click}
        {--ref= : Element ref from snapshot (e.g. @e1)}
        {--json : Output as JSON}';

    protected $description = 'Click an element on a browser page';

    /**
     * Build the IPC command to send to the daemon.
     *
     * @param  string  $requestId  The unique request ID
     * @param  string  $instanceId  The instance ID of the client
     * @param  DateTimeImmutable  $timestamp  The timestamp for the command
     * @return IpcMessage The IPC command to send to the daemon
     */
    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        $pageId = $this->argument('page_id');
        $selector = $this->argument('selector');
        $ref = $this->option('ref');

        // Validate that either selector or ref is provided
        if (! $selector && ! $ref) {
            throw new \InvalidArgumentException('Must provide either a selector or --ref option');
        }

        if ($selector && $ref) {
            throw new \InvalidArgumentException('Cannot provide both selector and --ref option');
        }

        return new BrowserClickIpcCommand(
            pageId: $pageId,
            selector: $selector,
            ref: $ref,
            timestamp: $timestamp,
            instanceId: $instanceId,
            requestId: $requestId
        );
    }

    /**
     * Handle a successful response from the browser.
     *
     * @param  BrowserResponseEvent  $response  The successful response from the browser
     */
    protected function handleSuccess(BrowserResponseEvent $response): void
    {
        $selector = $this->argument('selector');
        $ref = $this->option('ref');
        $target = $selector ?? $ref;

        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'message' => "Clicked on: $target",
            ]);
        } else {
            $this->info("✓ Clicked on: $target");
        }
    }
}
