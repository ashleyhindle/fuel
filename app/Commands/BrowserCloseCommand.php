<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserCloseCommand as BrowserCloseIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserCloseCommand extends BrowserCommand
{
    protected $signature = 'browser:close
        {context_id : Browser context ID to close}
        {--json : Output as JSON}';

    protected $description = 'Close a browser context via the consume daemon';

    private ?string $contextId = null;

    /**
     * Prepare command arguments before building IPC command.
     */
    public function handle(): int
    {
        $this->contextId = $this->argument('context_id');

        return parent::handle();
    }

    /**
     * Build the BrowserClose IPC command.
     */
    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        return new BrowserCloseIpcCommand(
            contextId: $this->contextId,
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
                'result' => $response->result,
            ]);
        } else {
            $this->info(sprintf("Browser context '%s' closed successfully", $this->contextId));
            if ($response->result !== null) {
                $this->line('Result: '.json_encode($response->result, JSON_PRETTY_PRINT));
            }
        }
    }
}
