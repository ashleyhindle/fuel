<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\DetectsElementTarget;
use App\Ipc\Commands\BrowserHtmlCommand as IpcBrowserHtmlCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserHtmlCommand extends BrowserCommand
{
    use DetectsElementTarget;

    protected $signature = 'browser:html
        {page_id : The ID of the page to get HTML from}
        {target : Element ref (@e1) or CSS selector}
        {--inner : Return innerHTML instead of outerHTML}
        {--json : Output as JSON}';

    protected $description = 'Get HTML content from an element on a page';

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
        return new IpcBrowserHtmlCommand(
            pageId: $this->argument('page_id'),
            selector: $this->selector,
            ref: $this->ref,
            inner: $this->option('inner'),
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
                'data' => $response->result ?? [],
            ]);
        } else {
            $result = $response->result ?? [];
            if (isset($result['html'])) {
                $this->info($result['html']);
            } else {
                $this->info('No HTML content found');
            }
        }
    }
}
