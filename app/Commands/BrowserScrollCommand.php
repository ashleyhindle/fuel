<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserScrollCommand as BrowserScrollIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserScrollCommand extends BrowserCommand
{
    protected $signature = 'browser:scroll
        {page_id : Page ID to scroll on}
        {direction : Direction to scroll (up|down|left|right)}
        {amount=100 : Pixels to scroll}
        {--json : Output as JSON}';

    protected $description = 'Scroll a browser page';

    public function handle(): int
    {
        // Validate direction
        $direction = $this->argument('direction');
        if (! in_array($direction, ['up', 'down', 'left', 'right'])) {
            return $this->outputError('Invalid direction. Must be one of: up, down, left, right');
        }

        // Validate amount
        $amount = (int) $this->argument('amount');
        if ($amount <= 0) {
            return $this->outputError('Amount must be a positive number');
        }

        return parent::handle();
    }

    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        return new BrowserScrollIpcCommand(
            pageId: $this->argument('page_id'),
            direction: $this->argument('direction'),
            amount: (int) $this->argument('amount'),
            timestamp: $timestamp,
            instanceId: $instanceId,
            requestId: $requestId
        );
    }

    protected function handleSuccess(BrowserResponseEvent $response): void
    {
        $direction = $this->argument('direction');
        $amount = $this->argument('amount');

        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'message' => sprintf('Scrolled %s %spx', $direction, $amount),
                'direction' => $direction,
                'amount' => (int) $amount,
            ]);
        } else {
            $this->info(sprintf('✓ Scrolled %s %spx', $direction, $amount));
        }
    }
}
