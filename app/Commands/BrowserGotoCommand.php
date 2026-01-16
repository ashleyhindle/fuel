<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserGotoCommand as BrowserGotoIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserGotoCommand extends BrowserCommand
{
    protected $signature = 'browser:goto
        {page_id : Page ID to navigate}
        {url : URL to navigate to}
        {--wait-until=load : Wait until this event (load, domcontentloaded, networkidle)}
        {--timeout=30000 : Navigation timeout in milliseconds}
        {--html : Return rendered HTML/DOM after navigation}
        {--json : Output as JSON}';

    protected $description = 'Navigate a browser page to a URL via the consume daemon';

    private ?string $pageId = null;

    private ?string $url = null;

    private ?string $waitUntil = null;

    private int $timeout = 30000;

    private bool $html = false;

    /**
     * Prepare command arguments before building IPC command.
     */
    public function handle(): int
    {
        $this->pageId = $this->argument('page_id');
        $this->url = $this->argument('url');
        $this->waitUntil = $this->option('wait-until');
        $this->timeout = (int) $this->option('timeout');
        $this->html = (bool) $this->option('html');

        return parent::handle();
    }

    /**
     * Build the BrowserGoto IPC command.
     */
    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        return new BrowserGotoIpcCommand(
            pageId: $this->pageId,
            url: $this->url,
            waitUntil: $this->waitUntil,
            timeout: $this->timeout,
            timestamp: $timestamp,
            instanceId: $instanceId,
            requestId: $requestId,
            html: $this->html
        );
    }

    /**
     * Get response timeout based on navigation timeout.
     */
    protected function getResponseTimeout(): int
    {
        // Use timeout from option + 2 seconds buffer for IPC overhead
        return (int) ceil($this->timeout / 1000) + 2;
    }

    /**
     * Handle the successful response from the browser daemon.
     */
    protected function handleSuccess(BrowserResponseEvent $response): void
    {
        if ($this->option('json')) {
            $output = [
                'success' => true,
                'page_id' => $this->pageId,
                'url' => $this->url,
                'result' => $response->result,
            ];
            if ($this->html && isset($response->result['html'])) {
                $output['html'] = $response->result['html'];
            }

            $this->outputJson($output);
        } else {
            $this->info(sprintf("Page '%s' navigated to '%s' successfully", $this->pageId, $this->url));
            if ($this->html && isset($response->result['html'])) {
                $this->line($response->result['html']);
            } elseif ($response->result !== null) {
                $this->line('Result: '.json_encode($response->result, JSON_PRETTY_PRINT));
            }
        }
    }
}
