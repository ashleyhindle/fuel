<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\DetectsElementTarget;
use App\Ipc\Commands\BrowserTextCommand as IpcBrowserTextCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserTextCommand extends BrowserCommand
{
    use DetectsElementTarget;

    protected $signature = 'browser:text
        {page_id : The ID of the page to get text from}
        {target : Element ref (@e1) or CSS selector}
        {--json : Output as JSON}';

    protected $description = 'Get text content from an element on a page';

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
        return new IpcBrowserTextCommand(
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
