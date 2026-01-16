<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Ipc\Commands\BrowserSnapshotCommand as BrowserSnapshotIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use App\Services\ConsumeIpcProtocol;
use App\Services\FuelContext;
use DateTimeImmutable;
use LaravelZero\Framework\Commands\Command;

class BrowserSnapshotCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'browser:snapshot
        {page_id : Page ID to take accessibility snapshot of}
        {--interactive : Only include interactive elements}
        {--json : Output as JSON}';

    protected $description = 'Get accessibility snapshot of a browser page with element refs';

    /**
     * Handle the command.
     */
    public function handle(): int
    {
        $pageId = $this->argument('page_id');
        $interactiveOnly = (bool) $this->option('interactive');

        $client = app(ConsumeIpcClient::class);
        $protocol = new ConsumeIpcProtocol;

        // Check if daemon is running
        $pidFilePath = app(FuelContext::class)->getPidFilePath();
        if (! $client->isRunnerAlive($pidFilePath)) {
            return $this->outputError('Consume daemon is not running. Start it with: fuel consume');
        }

        try {
            $pidData = json_decode(file_get_contents($pidFilePath), true);
            $port = $pidData['port'] ?? 0;
            if ($port === 0) {
                return $this->outputError('Invalid port in PID file');
            }

            $client->connect($port);
            $client->attach();

            $requestId = $protocol->generateRequestId();
            $client->sendCommand(new BrowserSnapshotIpcCommand(
                pageId: $pageId,
                interactiveOnly: $interactiveOnly,
                timestamp: new DateTimeImmutable,
                instanceId: $client->getInstanceId(),
                requestId: $requestId
            ));

            $response = $this->waitForResponse($client, $requestId, 30);

            $client->detach();
            $client->disconnect();

            if (! $response instanceof BrowserResponseEvent) {
                return $this->outputError('Timeout waiting for browser response');
            }

            if (! $response->success) {
                return $this->outputError($response->error ?? 'Snapshot failed');
            }

            $this->outputSnapshotSuccess($response);

            return self::SUCCESS;

        } catch (\Throwable $e) {
            return $this->outputError('Failed to communicate with daemon: '.$e->getMessage());
        }
    }

    /**
     * Wait for a BrowserResponseEvent with matching request ID.
     */
    private function waitForResponse(ConsumeIpcClient $client, string $requestId, int $timeout): ?BrowserResponseEvent
    {
        $deadline = time() + $timeout;

        while (time() < $deadline) {
            $events = $client->pollEvents();

            foreach ($events as $event) {
                if ($event instanceof BrowserResponseEvent && $event->requestId() === $requestId) {
                    return $event;
                }

                if (! $event instanceof BrowserResponseEvent) {
                    $client->applyEvent($event);
                }
            }

            usleep(50000); // 50ms
        }

        return null;
    }

    /**
     * Output snapshot success message.
     */
    private function outputSnapshotSuccess(BrowserResponseEvent $response): void
    {
        $snapshot = $response->result['snapshot'] ?? null;

        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'snapshot' => $snapshot,
            ]);
        } else {
            if ($snapshot === null) {
                $this->info('Snapshot captured (no accessibility tree available)');

                return;
            }

            // New format: { text: "...", refCount: N }
            if (isset($snapshot['text'])) {
                $this->info('Page Accessibility Snapshot:');
                $this->line('');
                $this->line($snapshot['text']);
                $this->line('');
                $this->info(sprintf('Found %d elements', $snapshot['refCount'] ?? 0));
            } else {
                // Legacy format (tree structure) - shouldn't happen with new daemon
                $this->info('Page Accessibility Snapshot:');
                $this->line('');
                $this->formatSnapshotNode($snapshot, 0);
            }
        }
    }

    /**
     * Format snapshot node recursively for text output (legacy).
     */
    private function formatSnapshotNode(array $node, int $indent): void
    {
        $spaces = str_repeat('  ', $indent);

        // Build node description
        $parts = [];
        if (isset($node['ref'])) {
            $parts[] = $node['ref'];
        }
        if (isset($node['role'])) {
            $parts[] = '['.$node['role'].']';
        }
        if (isset($node['name'])) {
            $parts[] = '"'.$node['name'].'"';
        }
        if (isset($node['value'])) {
            $parts[] = 'value="'.$node['value'].'"';
        }

        if (count($parts) > 0) {
            $this->line($spaces.implode(' ', $parts));
        }

        // Process children
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                $this->formatSnapshotNode($child, $indent + 1);
            }
        }
    }
}
