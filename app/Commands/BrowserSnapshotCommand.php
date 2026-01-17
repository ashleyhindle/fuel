<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserSnapshotCommand as BrowserSnapshotIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserSnapshotCommand extends BrowserCommand
{
    protected $signature = 'browser:snapshot
        {page_id : Page ID to take accessibility snapshot of}
        {--i|interactive : Only include interactive elements}
        {--s|scope= : Scope snapshot to CSS selector}
        {--json : Output as JSON}';

    protected $description = 'Get accessibility snapshot of a browser page with element refs';

    private ?string $pageId = null;

    private bool $interactiveOnly = false;

    private ?string $scope = null;

    /**
     * Prepare command arguments before building IPC command.
     */
    public function handle(): int
    {
        $this->pageId = $this->argument('page_id');
        $this->interactiveOnly = (bool) $this->option('interactive');
        $this->scope = $this->option('scope');

        return parent::handle();
    }

    /**
     * Build the BrowserSnapshot IPC command.
     */
    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        return new BrowserSnapshotIpcCommand(
            pageId: $this->pageId,
            interactiveOnly: $this->interactiveOnly,
            scope: $this->scope,
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
                'page_id' => $this->pageId,
                'result' => $response->result,
            ]);
        } else {
            $this->info(sprintf(
                "Accessibility snapshot for page '%s'%s:",
                $this->pageId,
                $this->interactiveOnly ? ' (interactive only)' : ''
            ));

            if (isset($response->result['snapshot']['text'])) {
                $this->line($response->result['snapshot']['text']);
            }

            if (isset($response->result['snapshot']['refCount'])) {
                $this->comment(sprintf('Total refs: %d', $response->result['snapshot']['refCount']));
            }
        }
    }
}
