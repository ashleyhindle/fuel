<?php

declare(strict_types=1);

namespace App\Commands;

use App\Ipc\Commands\BrowserEvalCommand as BrowserEvalIpcCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use DateTimeImmutable;

class BrowserEvalCommand extends BrowserCommand
{
    protected $signature = 'browser:eval
        {page_id : Page ID to evaluate expression in}
        {expression : JavaScript expression to evaluate}
        {--json : Output as JSON}';

    protected $description = 'Evaluate JavaScript expression in a browser page via the consume daemon';

    private ?string $pageId = null;

    private ?string $expression = null;

    /**
     * Prepare command arguments before building IPC command.
     */
    public function handle(): int
    {
        $this->pageId = $this->argument('page_id');
        $this->expression = $this->argument('expression');

        return parent::handle();
    }

    /**
     * Build the BrowserEval IPC command.
     */
    protected function buildIpcCommand(
        string $requestId,
        string $instanceId,
        DateTimeImmutable $timestamp
    ): IpcMessage {
        return new BrowserEvalIpcCommand(
            pageId: $this->pageId,
            expression: $this->expression,
            timestamp: $timestamp,
            instanceId: $instanceId,
            requestId: $requestId
        );
    }

    /**
     * Get response timeout for eval operations.
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
                'expression' => $this->expression,
                'result' => $response->result,
            ]);
        } else {
            $this->info(sprintf("Expression evaluated successfully on page '%s'", $this->pageId));
            if ($response->result !== null) {
                $this->line('Result: '.json_encode($response->result, JSON_PRETTY_PRINT));
            }
        }
    }
}
