<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserCreateCommand as BrowserCreateIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserCreateCommand extends BrowserCommand
{
    protected $signature = 'browser:create
        {context_id : Browser context ID to create}
        {page_id? : Page ID to create (defaults to {context_id}-tab1)}
        {--viewport= : Viewport size as JSON (e.g., {"width":1280,"height":720})}
        {--user-agent= : Custom user agent string}
        {--json : Output as JSON}';

    protected $description = 'Create a new browser context via the consume daemon';

    private ?string $contextId = null;

    private ?string $pageId = null;

    private ?array $viewport = null;

    private ?string $userAgent = null;

    /**
     * Prepare command arguments before building IPC command.
     */
    public function handle(): int
    {
        $this->contextId = $this->argument('context_id');
        $this->pageId = $this->argument('page_id') ?? $this->contextId.'-tab1';
        $viewportOption = $this->option('viewport');
        $this->userAgent = $this->option('user-agent');

        // Parse viewport JSON if provided
        if ($viewportOption !== null) {
            $decoded = json_decode($viewportOption, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->outputError('Invalid viewport JSON: '.json_last_error_msg());
            }

            $this->viewport = $decoded;
        }

        return parent::handle();
    }

    /**
     * Build the BrowserCreate IPC command.
     */
    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        return new BrowserCreateIpcCommand(
            contextId: $this->contextId,
            pageId: $this->pageId,
            viewport: $this->viewport,
            userAgent: $this->userAgent,
            timestamp: $timestamp,
            instanceId: $instanceId,
            requestId: $requestId
        );
    }

    /**
     * Handle the successful response from the browser daemon.
     */
    protected function handleSuccess(BrowserResponseEvent $response): void
    {
        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'context_id' => $this->contextId,
                'page_id' => $this->pageId,
                'result' => $response->result,
            ]);
        } else {
            $this->info(sprintf("Browser context '%s' created with page '%s'", $this->contextId, $this->pageId));
            if ($response->result !== null) {
                $this->line('Result: '.json_encode($response->result, JSON_PRETTY_PRINT));
            }
        }
    }
}
