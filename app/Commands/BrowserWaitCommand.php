<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserWaitCommand as IpcBrowserWaitCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserWaitCommand extends BrowserCommand
{
    protected $signature = 'browser:wait
        {page_id : The ID of the page to wait on}
        {--selector= : Wait for a CSS selector to appear}
        {--url= : Wait for navigation to a URL (partial match or regex)}
        {--text= : Wait for text to appear on the page}
        {--state=visible : State for selector wait (visible|hidden|attached|detached)}
        {--timeout=30000 : Timeout in milliseconds}
        {--json : Output response as JSON}';

    protected $description = 'Wait for a condition on a browser page';

    public function handle(): int
    {
        // Validate that exactly one wait condition is provided
        $selector = $this->option('selector');
        $url = $this->option('url');
        $text = $this->option('text');
        $conditions = array_filter([$selector, $url, $text]);

        if (count($conditions) !== 1) {
            return $this->outputError('Must provide exactly one of: --selector, --url, or --text');
        }

        return parent::handle();
    }

    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        $pageId = $this->argument('page_id');
        $selector = $this->option('selector');
        $url = $this->option('url');
        $text = $this->option('text');
        $state = $this->option('state');
        $timeout = (int) $this->option('timeout');

        return new IpcBrowserWaitCommand(
            pageId: $pageId,
            selector: $selector,
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
