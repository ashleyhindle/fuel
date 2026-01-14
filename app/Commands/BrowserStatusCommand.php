<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserStatusCommand as BrowserStatusIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserStatusCommand extends BrowserCommand
{
    protected $signature = 'browser:status
        {--json : Output as JSON}';

    protected $description = 'Get browser daemon status via the consume daemon';

    /**
     * Build the BrowserStatus IPC command.
     */
    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        return new BrowserStatusIpcCommand(
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
        // Extract status data from result
        $result = $response->result ?? [];
        $browserLaunched = $result['browserLaunched'] ?? false;
        $contextsCount = $result['contextsCount'] ?? 0;
        $pagesCount = $result['pagesCount'] ?? 0;

        // Output status
        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'browserLaunched' => $browserLaunched,
                'contextsCount' => $contextsCount,
                'pagesCount' => $pagesCount,
            ]);
        } else {
            $this->info('Browser Daemon Status:');
            $this->line('  Browser Launched: '.($browserLaunched ? 'Yes' : 'No'));
            $this->line('  Contexts: '.$contextsCount);
            $this->line('  Pages: '.$pagesCount);
        }
    }
}
