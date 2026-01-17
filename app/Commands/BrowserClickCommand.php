<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\DetectsElementTarget;
use App\Ipc\Commands\BrowserClickCommand as BrowserClickIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserClickCommand extends BrowserCommand
{
    use DetectsElementTarget;

    protected $signature = 'browser:click
        {page_id : Page ID to click on}
        {target : Element ref (@e1) or CSS selector to click}
        {--json : Output as JSON}';

    protected $description = 'Click an element on a browser page';

    protected string $target;

    protected ?string $selector = null;

    protected ?string $ref = null;

    public function handle(): int
    {
        $this->target = $this->argument('target');
        ['selector' => $this->selector, 'ref' => $this->ref] = $this->parseTarget($this->target);

        return parent::handle();
    }

    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        return new BrowserClickIpcCommand(
            pageId: $this->argument('page_id'),
            selector: $this->selector,
            ref: $this->ref,
            timestamp: $timestamp,
            instanceId: $instanceId,
            requestId: $requestId
        );
    }

    protected function handleSuccess(BrowserResponseEvent $response): void
    {
        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'message' => 'Clicked on: '.$this->target,
            ]);
        } else {
            $this->info('✓ Clicked on: '.$this->target);
        }
    }
}
