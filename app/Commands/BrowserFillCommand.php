<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserFillCommand as BrowserFillIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserFillCommand extends BrowserCommand
{
    protected $signature = 'browser:fill
        {page_id : Page ID to fill input on}
        {selector? : CSS selector of input to fill}
        {--value= : Value to fill into the input (required)}
        {--ref= : Element ref from snapshot (e.g. @e2)}
        {--json : Output as JSON}';

    protected $description = 'Fill an input field on a browser page';

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
        $value = $this->option('value');
        $ref = $this->option('ref');

        // Validate value is provided
        if (! $value) {
            throw new \InvalidArgumentException('--value option is required');
        }

        // Validate that either selector or ref is provided
        if (! $selector && ! $ref) {
            throw new \InvalidArgumentException('Must provide either a selector or --ref option');
        }

        if ($selector && $ref) {
            throw new \InvalidArgumentException('Cannot provide both selector and --ref option');
        }

        return new BrowserFillIpcCommand(
            pageId: $pageId,
            selector: $selector,
            value: $value,
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
        $value = $this->option('value');
        $target = $selector ?? $ref;

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
