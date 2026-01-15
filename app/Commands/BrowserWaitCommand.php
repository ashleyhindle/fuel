<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\Commands\BrowserWaitCommand as IpcBrowserWaitCommand;
use App\Services\ConsumeIpcClient;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class BrowserWaitCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'browser:wait
        {page_id : The ID of the page to wait on}
        {--selector= : Wait for a CSS selector to appear}
        {--url= : Wait for navigation to a URL (partial match or regex)}
        {--text= : Wait for text to appear on the page}
        {--state=visible : State for selector wait (visible|hidden|attached|detached)}
        {--timeout=30000 : Timeout in milliseconds}
        {--json : Output response as JSON}';

    protected $description = 'Wait for a condition on a browser page';

    public function handle(ConsumeIpcClient $ipcClient): int
    {
        $pageId = $this->argument('page_id');
        $selector = $this->option('selector');
        $url = $this->option('url');
        $text = $this->option('text');
        $state = $this->option('state');
        $timeout = (int) $this->option('timeout');

        // Validate that exactly one wait condition is provided
        $conditions = array_filter([$selector, $url, $text]);
        if (count($conditions) !== 1) {
            $this->error('Must provide exactly one of: --selector, --url, or --text');

            return 1;
        }

        // Check if daemon is running
        if (! $ipcClient->isDaemonRunning()) {
            $this->error('Browser daemon is not running. Start it with: fuel consume');

            return 1;
        }

        // Send wait command to daemon
        try {
            $command = new IpcBrowserWaitCommand(
                pageId: $pageId,
                selector: $selector,
                url: $url,
                text: $text,
                state: $state,
                timeout: $timeout
            );

            $response = $ipcClient->sendCommandAndWait($command, max(5, intval($timeout / 1000) + 2));

            if ($response instanceof BrowserResponseEvent) {
                if ($response->success) {
                    if ($this->option('json')) {
                        $this->outputJson([
                            'success' => true,
                            'message' => 'Wait completed successfully',
                            'data' => $response->result ?? [],
                        ]);

                        return 0;
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

                    return 0;
                } else {
                    $error = $response->error ?? 'Unknown error';
                    $errorCode = $response->errorCode ?? 'UNKNOWN';

                    if ($this->option('json')) {
                        $this->outputJson([
                            'success' => false,
                            'error' => $error,
                            'code' => $errorCode,
                        ]);

                        return 1;
                    } else {
                        $this->error("✗ Wait failed: $error (Code: $errorCode)");
                    }

                    return 1;
                }
            } else {
                throw new RuntimeException('Unexpected response type: '.get_class($response));
            }
        } catch (RuntimeException $e) {
            if ($this->option('json')) {
                $this->outputJson([
                    'success' => false,
                    'error' => $e->getMessage(),
                ]);

                return 1;
            } else {
                $this->error('✗ Failed to execute wait: '.$e->getMessage());
            }

            return 1;
        }
    }
}
