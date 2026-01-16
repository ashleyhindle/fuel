<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserTypeCommand as BrowserTypeIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserTypeCommand extends BrowserCommand
{
    protected $signature = 'browser:type
        {page_id : Page ID to type on}
        {selector? : CSS selector of element to type into}
        {--text= : Text to type (required)}
        {--ref= : Element ref from snapshot (e.g. @e2)}
        {--delay=0 : Delay between keystrokes in milliseconds}
        {--json : Output as JSON}';

    protected $description = 'Type text into an element on a browser page';

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
        $text = $this->option('text');
        $ref = $this->option('ref');
        $delay = (int) $this->option('delay');

        // Validate text is provided
        if (! $text) {
            throw new \InvalidArgumentException('--text option is required');
        }

        // Validate that either selector or ref is provided
        if (! $selector && ! $ref) {
            throw new \InvalidArgumentException('Must provide either a selector or --ref option');
        }

        if ($selector && $ref) {
            throw new \InvalidArgumentException('Cannot provide both selector and --ref option');
        }

        return new BrowserTypeIpcCommand(
            pageId: $pageId,
            selector: $selector,
            text: $text,
            ref: $ref,
            delay: $delay,
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
        $text = $this->option('text');
        $target = $selector ?? $ref;
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
