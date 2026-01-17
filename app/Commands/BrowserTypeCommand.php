<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\DetectsElementTarget;
use App\Ipc\Commands\BrowserTypeCommand as BrowserTypeIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserTypeCommand extends BrowserCommand
{
    use DetectsElementTarget;

    protected $signature = 'browser:type
        {page_id : Page ID to type on}
        {target : Element ref (@e1) or CSS selector of element}
        {text : Text to type}
        {--delay=0 : Delay between keystrokes in milliseconds}
        {--json : Output as JSON}';

    protected $description = 'Type text into an element on a browser page';

    protected string $target;

    protected string $text;

    protected ?string $selector = null;

    protected ?string $ref = null;

    public function handle(): int
    {
        $this->target = $this->argument('target');
        $this->text = $this->argument('text');
        ['selector' => $this->selector, 'ref' => $this->ref] = $this->parseTarget($this->target);

        return parent::handle();
    }

    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        return new BrowserTypeIpcCommand(
            pageId: $this->argument('page_id'),
            selector: $this->selector,
            text: $this->text,
            ref: $this->ref,
            delay: (int) $this->option('delay'),
            timestamp: $timestamp,
            instanceId: $instanceId,
            requestId: $requestId
        );
    }

    protected function handleSuccess(BrowserResponseEvent $response): void
    {
        $displayText = strlen($this->text) > 50 ? substr($this->text, 0, 47).'...' : $this->text;

        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'message' => sprintf('Typed into %s: %s', $this->target, $displayText),
            ]);
        } else {
            $this->info(sprintf('✓ Typed into %s: %s', $this->target, $displayText));
        }
    }
}
