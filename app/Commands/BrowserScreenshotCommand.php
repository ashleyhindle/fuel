<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserScreenshotCommand as BrowserScreenshotIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserScreenshotCommand extends BrowserCommand
{
    protected $signature = 'browser:screenshot
        {page_id : Page ID to screenshot}
        {--path= : Path to save the screenshot (optional, returns base64 if omitted)}
        {--full-page : Capture full scrollable page}
        {--json : Output as JSON}';

    protected $description = 'Take a screenshot of a browser page via the consume daemon';

    private ?string $pageId = null;

    private ?string $path = null;

    private bool $fullPage = false;

    /**
     * Prepare command arguments before building IPC command.
     */
    public function handle(): int
    {
        $this->pageId = $this->argument('page_id');
        $this->path = $this->option('path');
        $this->fullPage = $this->option('full-page');

        return parent::handle();
    }

    /**
     * Build the BrowserScreenshot IPC command.
     */
    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        return new BrowserScreenshotIpcCommand(
            pageId: $this->pageId,
            path: $this->path,
            fullPage: $this->fullPage,
            timestamp: $timestamp,
            instanceId: $instanceId,
            requestId: $requestId
        );
    }

    /**
     * Get response timeout for screenshot operations.
     */
    protected function getResponseTimeout(): int
    {
        return 30;
    }

    /**
     * Handle the successful response from the browser daemon.
     */
    protected function handleSuccess(BrowserResponseEvent $response): void
    {
        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'page_id' => $this->pageId,
                'path' => $this->path,
                'full_page' => $this->fullPage,
                'result' => $response->result,
            ]);
        } else {
            if ($this->path !== null) {
                $this->info(sprintf("Screenshot of page '%s' saved to '%s'", $this->pageId, $this->path));
            } else {
                $this->info(sprintf("Screenshot of page '%s' captured successfully", $this->pageId));
            }

            if ($response->result !== null) {
                $this->line('Result: '.json_encode($response->result, JSON_PRETTY_PRINT));
            }
        }
    }
}
