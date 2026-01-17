<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\DetectsElementTarget;
use App\Ipc\Commands\BrowserWaitCommand as IpcBrowserWaitCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserWaitCommand extends BrowserCommand
{
    use DetectsElementTarget;

    protected $signature = 'browser:wait
        {page_id : The ID of the page to wait on}
        {target? : Element ref (@e1), CSS selector, or milliseconds to wait}
        {--selector= : Wait for a CSS selector to appear}
        {--url= : Wait for navigation to a URL (partial match or regex)}
        {--text= : Wait for text to appear on the page}
        {--state=visible : State for selector wait (visible|hidden|attached|detached)}
        {--timeout=30000 : Timeout in milliseconds}
        {--json : Output response as JSON}';

    protected $description = 'Wait for a condition on a browser page';

    protected ?string $selector = null;

    protected ?string $ref = null;

    protected ?int $delay = null;

    public function handle(): int
    {
        $target = $this->argument('target');
        $selectorOption = $this->option('selector');
        $url = $this->option('url');
        $text = $this->option('text');

        // Parse the target argument if provided
        if ($target !== null) {
            // Check if target is numeric (milliseconds)
            if (is_numeric($target)) {
                $this->delay = (int) $target;
            } else {
                // Parse as ref or selector
                ['selector' => $parsedSelector, 'ref' => $parsedRef] = $this->parseTarget($target);
                $this->selector = $parsedSelector;
                $this->ref = $parsedRef;
            }
        } elseif ($selectorOption !== null) {
            // Use --selector option if no target
            $this->selector = $selectorOption;
        }

        // Validate that exactly one wait condition is provided
        $conditions = array_filter([$this->selector, $this->ref, $this->delay, $url, $text]);

        if (count($conditions) !== 1) {
            return $this->outputError('Must provide exactly one of: target (ref/selector/milliseconds), --selector, --url, or --text');
        }

        return parent::handle();
    }

    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        $pageId = $this->argument('page_id');
        $url = $this->option('url');
        $text = $this->option('text');
        $state = $this->option('state');
        $timeout = (int) $this->option('timeout');

        return new IpcBrowserWaitCommand(
            pageId: $pageId,
            selector: $this->selector,
            ref: $this->ref,
            delay: $this->delay,
            url: $url,
            text: $text,
            state: $state,
            timeout: $timeout,
            timestamp: $timestamp,
            instanceId: $instanceId,
            requestId: $requestId
        );
    }

    protected function getResponseTimeout(): int
    {
        // Get timeout from option and convert to seconds, adding buffer
        $timeout = (int) $this->option('timeout');

        return max(5, intval($timeout / 1000) + 2);
    }

    protected function handleSuccess(BrowserResponseEvent $response): void
    {
        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'message' => 'Wait completed successfully',
                'data' => $response->result ?? [],
            ]);
        } else {
            $result = $response->result ?? [];
            $this->info('✓ Wait completed successfully');

            if (isset($result['type'])) {
                $this->info('Type: '.$result['type']);

                switch ($result['type']) {
                    case 'selector':
                        $this->info('Found selector: '.($result['selector'] ?? 'N/A'));
                        break;
                    case 'ref':
                        $this->info('Found ref: '.($result['ref'] ?? 'N/A'));
                        break;
                    case 'delay':
                        $this->info('Waited for: '.($result['delay'] ?? 'N/A').'ms');
                        break;
                    case 'url':
                        $this->info('Navigated to: '.($result['url'] ?? 'N/A'));
                        break;
                    case 'text':
                        $this->info('Found text: '.($result['text'] ?? 'N/A'));
                        break;
                }
            }
        }
    }
}
