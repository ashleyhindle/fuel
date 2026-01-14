<?php

declare(strict_types=1);

namespace App\Daemon;

use App\Ipc\Events\BrowserResponseEvent;
use App\Ipc\IpcMessage;
use App\Services\BrowserDaemonManager;
use App\Services\ConsumeIpcServer;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Handles browser automation IPC commands.
 * Processes browser commands and broadcasts response events back to requesting clients.
 */
final class BrowserCommandHandler
{
    // Error codes for browser operations
    private const ERROR_CODE_BROWSER_START_FAILED = 'BROWSER_START_FAILED';

    private const ERROR_CODE_BROWSER_OPERATION_FAILED = 'BROWSER_OPERATION_FAILED';

    private const ERROR_CODE_INVALID_PARAMETERS = 'INVALID_PARAMETERS';

    public function __construct(
        private readonly BrowserDaemonManager $browserManager,
        private readonly ConsumeIpcServer $ipcServer,
        private readonly LifecycleManager $lifecycleManager,
    ) {}

    /**
     * Handle browser create context command
     */
    public function handleBrowserCreate(IpcMessage $message): void
    {
        if ($message instanceof \App\Ipc\Commands\BrowserCreateCommand) {
            try {
                // Ensure browser daemon is running
                $this->ensureBrowserRunning();

                // Build options array for createContext
                $options = [];
                if ($message->viewport !== null) {
                    $options['viewport'] = $message->viewport;
                }
                if ($message->userAgent !== null) {
                    $options['userAgent'] = $message->userAgent;
                }

                // Create the browser context
                $result = $this->browserManager->createContext($message->contextId, $options);

                // Send success response
                $this->sendSuccessResponse($message->getRequestId(), $result);
            } catch (Throwable $e) {
                $this->sendErrorResponse(
                    $message->getRequestId(),
                    $e->getMessage(),
                    $this->getErrorCode($e)
                );
            }
        }
    }

    /**
     * Handle browser create page command
     */
    public function handleBrowserPage(IpcMessage $message): void
    {
        if ($message instanceof \App\Ipc\Commands\BrowserPageCommand) {
            try {
                // Ensure browser daemon is running
                $this->ensureBrowserRunning();

                // Create the page
                $result = $this->browserManager->createPage($message->contextId, $message->pageId);

                // Send success response
                $this->sendSuccessResponse($message->getRequestId(), $result);
            } catch (Throwable $e) {
                $this->sendErrorResponse(
                    $message->getRequestId(),
                    $e->getMessage(),
                    $this->getErrorCode($e)
                );
            }
        }
    }

    /**
     * Handle browser goto command
     */
    public function handleBrowserGoto(IpcMessage $message): void
    {
        if ($message instanceof \App\Ipc\Commands\BrowserGotoCommand) {
            try {
                // Ensure browser daemon is running
                $this->ensureBrowserRunning();

                // Build options array
                $options = [];
                if ($message->waitUntil !== null) {
                    $options['waitUntil'] = $message->waitUntil;
                }
                if ($message->timeout !== null) {
                    $options['timeout'] = $message->timeout;
                }

                // Navigate to URL
                $result = $this->browserManager->goto($message->pageId, $message->url, $options);

                // Send success response
                $this->sendSuccessResponse($message->getRequestId(), $result);
            } catch (Throwable $e) {
                $this->sendErrorResponse(
                    $message->getRequestId(),
                    $e->getMessage(),
                    $this->getErrorCode($e)
                );
            }
        }
    }

    /**
     * Handle browser eval command
     */
    public function handleBrowserEval(IpcMessage $message): void
    {
        if ($message instanceof \App\Ipc\Commands\BrowserEvalCommand) {
            try {
                // Ensure browser daemon is running
                $this->ensureBrowserRunning();

                // Evaluate JavaScript expression
                $result = $this->browserManager->eval($message->pageId, $message->expression);

                // Send success response
                $this->sendSuccessResponse($message->getRequestId(), $result);
            } catch (Throwable $e) {
                $this->sendErrorResponse(
                    $message->getRequestId(),
                    $e->getMessage(),
                    $this->getErrorCode($e)
                );
            }
        }
    }

    /**
     * Handle browser screenshot command
     */
    public function handleBrowserScreenshot(IpcMessage $message): void
    {
        if ($message instanceof \App\Ipc\Commands\BrowserScreenshotCommand) {
            try {
                // Ensure browser daemon is running
                $this->ensureBrowserRunning();

                // Take screenshot
                $result = $this->browserManager->screenshot(
                    $message->pageId,
                    $message->path,
                    $message->fullPage ?? false
                );

                // Send success response
                $this->sendSuccessResponse($message->getRequestId(), $result);
            } catch (Throwable $e) {
                $this->sendErrorResponse(
                    $message->getRequestId(),
                    $e->getMessage(),
                    $this->getErrorCode($e)
                );
            }
        }
    }

    /**
     * Handle browser close context command
     */
    public function handleBrowserClose(IpcMessage $message): void
    {
        if ($message instanceof \App\Ipc\Commands\BrowserCloseCommand) {
            try {
                // Ensure browser daemon is running (might not be if already stopped)
                if (! $this->browserManager->isRunning()) {
                    // Context already closed, just send success
                    $this->sendSuccessResponse($message->getRequestId(), ['status' => 'already_closed']);

                    return;
                }

                // Close the context
                $result = $this->browserManager->closeContext($message->contextId);

                // Send success response
                $this->sendSuccessResponse($message->getRequestId(), $result);
            } catch (Throwable $e) {
                $this->sendErrorResponse(
                    $message->getRequestId(),
                    $e->getMessage(),
                    $this->getErrorCode($e)
                );
            }
        }
    }

    /**
     * Handle browser status command
     */
    public function handleBrowserStatus(IpcMessage $message): void
    {
        if ($message instanceof \App\Ipc\Commands\BrowserStatusCommand) {
            try {
                // Get status (doesn't require daemon to be running)
                $result = $this->browserManager->status();

                // Send success response
                $this->sendSuccessResponse($message->getRequestId(), $result);
            } catch (Throwable $e) {
                $this->sendErrorResponse(
                    $message->getRequestId(),
                    $e->getMessage(),
                    $this->getErrorCode($e)
                );
            }
        }
    }

    /**
     * Ensure the browser daemon is running, attempt to start if not
     *
     * @throws RuntimeException if browser cannot be started
     */
    private function ensureBrowserRunning(): void
    {
        if (! $this->browserManager->isRunning()) {
            try {
                $this->browserManager->start();
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Failed to start browser daemon: '.$e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    /**
     * Send a success response to the requesting client
     *
     * @param  string|null  $requestId  The request ID for correlation
     * @param  array  $result  The result data to send
     */
    private function sendSuccessResponse(?string $requestId, array $result): void
    {
        $response = new BrowserResponseEvent(
            success: true,
            result: $result,
            error: null,
            errorCode: null,
            timestamp: new DateTimeImmutable,
            instanceId: $this->lifecycleManager->getInstanceId(),
            requestId: $requestId
        );

        $this->ipcServer->broadcast($response);
    }

    /**
     * Send an error response to the requesting client
     *
     * @param  string|null  $requestId  The request ID for correlation
     * @param  string  $error  The error message
     * @param  string  $errorCode  The error code
     */
    private function sendErrorResponse(?string $requestId, string $error, string $errorCode): void
    {
        $response = new BrowserResponseEvent(
            success: false,
            result: null,
            error: $error,
            errorCode: $errorCode,
            timestamp: new DateTimeImmutable,
            instanceId: $this->lifecycleManager->getInstanceId(),
            requestId: $requestId
        );

        $this->ipcServer->broadcast($response);
    }

    /**
     * Determine the appropriate error code based on the exception
     *
     * @param  Throwable  $e  The exception that occurred
     * @return string The error code to use
     */
    private function getErrorCode(Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'Failed to start browser daemon')) {
            return self::ERROR_CODE_BROWSER_START_FAILED;
        }

        return self::ERROR_CODE_BROWSER_OPERATION_FAILED;
    }
}
