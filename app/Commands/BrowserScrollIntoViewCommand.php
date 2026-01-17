<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\DetectsElementTarget;
use App\Ipc\Commands\BrowserScrollIntoViewCommand as BrowserScrollIntoViewIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserScrollIntoViewCommand extends BrowserCommand
{
    use DetectsElementTarget;

    protected $signature = 'browser:scrollintoview
        {page_id : Page ID to scroll on}
        {target : Element ref (@e1) or CSS selector to scroll into view}
        {--json : Output as JSON}';

    protected $description = 'Scroll an element into view on a browser page';

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
        return new BrowserScrollIntoViewIpcCommand(
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
                'message' => 'Scrolled into view: '.$this->target,
            ]);
        } else {
            $this->info('✓ Scrolled into view: '.$this->target);
        }
    }
}
