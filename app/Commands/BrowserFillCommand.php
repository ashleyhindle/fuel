<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\DetectsElementTarget;
use App\Ipc\Commands\BrowserFillCommand as BrowserFillIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserFillCommand extends BrowserCommand
{
    use DetectsElementTarget;

    protected $signature = 'browser:fill
        {page_id : Page ID to fill input on}
        {target : Element ref (@e1) or CSS selector of input}
        {value : Value to fill into the input}
        {--json : Output as JSON}';

    protected $description = 'Fill an input field on a browser page';

    protected string $target;

    protected string $value;

    protected ?string $selector = null;

    protected ?string $ref = null;

    public function handle(): int
    {
        $this->target = $this->argument('target');
        $this->value = $this->argument('value');
        ['selector' => $this->selector, 'ref' => $this->ref] = $this->parseTarget($this->target);

        return parent::handle();
    }

    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        return new BrowserFillIpcCommand(
            pageId: $this->argument('page_id'),
            selector: $this->selector,
            value: $this->value,
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
                'message' => sprintf('Filled %s with: %s', $this->target, $this->value),
            ]);
        } else {
            $this->info(sprintf('✓ Filled %s with: %s', $this->target, $this->value));
        }
    }
}
