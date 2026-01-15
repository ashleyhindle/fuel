<?php

declare(strict_types=1);

namespace App\Daemon;

use App\Ipc\Commands\BrowserCloseCommand;
use App\Ipc\Commands\BrowserCreateCommand;
use App\Ipc\Commands\BrowserGotoCommand;
use App\Ipc\Commands\BrowserPageCommand;
use App\Ipc\Commands\BrowserRunCommand;
use App\Ipc\Commands\BrowserScreenshotCommand;
use App\Ipc\Commands\BrowserStatusCommand;
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
final readonly class BrowserCommandHandler
{
    // Error codes for browser operations
    private const ERROR_CODE_BROWSER_START_FAILED = 'BROWSER_START_FAILED';

    private const ERROR_CODE_BROWSER_OPERATION_FAILED = 'BROWSER_OPERATION_FAILED';

    public function __construct(
        private BrowserDaemonManager $browserManager,
        private ConsumeIpcServer $ipcServer,
        private LifecycleManager $lifecycleManager,
    ) {}

    /**
     * Handle browser create context command
     */
    public function handleBrowserCreate(IpcMessage $message): void
    {
        // Debug: Log what we received
        @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
            "[%s] handleBrowserCreate called - message type: %s, class: %s\n",
            date('H:i:s'),
            $message->type(),
            get_class($message)
        ), FILE_APPEND);

        if ($message instanceof BrowserCreateCommand) {
            @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                "[%s] instanceof check PASSED - contextId: %s\n",
                date('H:i:s'),
                $message->contextId
            ), FILE_APPEND);

            try {
                // Ensure browser daemon is running
                @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                    "[%s] Calling ensureBrowserRunning...\n",
                    date('H:i:s')
                ), FILE_APPEND);
                $this->ensureBrowserRunning();
                @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                    "[%s] ensureBrowserRunning completed\n",
                    date('H:i:s')
                ), FILE_APPEND);

                // Build options array for createContext
                $options = [];
                if ($message->viewport !== null) {
                    $options['viewport'] = $message->viewport;
                }

                if ($message->userAgent !== null) {
                    $options['userAgent'] = $message->userAgent;
                }

                if ($message->colorScheme !== null) {
                    $options['colorScheme'] = $message->colorScheme;
                }

                // Create the browser context
                @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                    "[%s] Calling createContext...\n",
                    date('H:i:s')
                ), FILE_APPEND);
                $contextResult = $this->browserManager->createContext($message->contextId, $options);
                @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                    "[%s] createContext completed\n",
                    date('H:i:s')
                ), FILE_APPEND);

                // Create the initial page
                @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                    "[%s] Calling createPage...\n",
                    date('H:i:s')
                ), FILE_APPEND);
                $pageResult = $this->browserManager->createPage($message->contextId, $message->pageId);
                @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                    "[%s] createPage completed\n",
                    date('H:i:s')
                ), FILE_APPEND);

                // Send success response with both context and page info
                @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                    "[%s] Sending success response...\n",
                    date('H:i:s')
                ), FILE_APPEND);
                $this->sendSuccessResponse($message->getRequestId(), [
                    'contextId' => $message->contextId,
                    'pageId' => $message->pageId,
                    'context' => $contextResult,
                    'page' => $pageResult,
                ]);
                @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                    "[%s] Success response sent\n",
                    date('H:i:s')
                ), FILE_APPEND);
            } catch (Throwable $e) {
                @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                    "[%s] EXCEPTION: %s\n",
                    date('H:i:s'),
                    $e->getMessage()
                ), FILE_APPEND);
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
        if ($message instanceof BrowserPageCommand) {
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
        if ($message instanceof BrowserGotoCommand) {
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
     * Handle browser run command
     */
    public function handleBrowserRun(IpcMessage $message): void
    {
        if ($message instanceof BrowserRunCommand) {
            try {
                // Ensure browser daemon is running
                $this->ensureBrowserRunning();

                // Run Playwright code
                $result = $this->browserManager->run($message->pageId, $message->code);

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
        if ($message instanceof BrowserScreenshotCommand) {
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
        if ($message instanceof BrowserCloseCommand) {
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
        if ($message instanceof BrowserStatusCommand) {
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
     * Ensure the browser daemon is running and healthy, restart if needed.
     *
     * @throws RuntimeException if browser cannot be started
     */
    private function ensureBrowserRunning(): void
    {
        @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
            "[%s] ensureBrowserRunning: checking isRunning()...\n",
            date('H:i:s')
        ), FILE_APPEND);

        // If not running, start it
        if (! $this->browserManager->isRunning()) {
            @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                "[%s] ensureBrowserRunning: NOT running, starting...\n",
                date('H:i:s')
            ), FILE_APPEND);
            try {
                $this->browserManager->start();
                @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                    "[%s] ensureBrowserRunning: started successfully\n",
                    date('H:i:s')
                ), FILE_APPEND);

                return;
            } catch (Throwable $e) {
                @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                    "[%s] ensureBrowserRunning: start FAILED: %s\n",
                    date('H:i:s'),
                    $e->getMessage()
                ), FILE_APPEND);
                throw new RuntimeException(
                    'Failed to start browser daemon: '.$e->getMessage(),
                    0,
                    $e
                );
            }
        }

        @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
            "[%s] ensureBrowserRunning: IS running, checking isHealthy()...\n",
            date('H:i:s')
        ), FILE_APPEND);

        // If running but unhealthy (unresponsive), restart it
        if (! $this->browserManager->isHealthy()) {
            @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                "[%s] ensureBrowserRunning: NOT healthy, restarting...\n",
                date('H:i:s')
            ), FILE_APPEND);
            try {
                $this->browserManager->restart();
                @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                    "[%s] ensureBrowserRunning: restarted successfully\n",
                    date('H:i:s')
                ), FILE_APPEND);
            } catch (Throwable $e) {
                @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
                    "[%s] ensureBrowserRunning: restart FAILED: %s\n",
                    date('H:i:s'),
                    $e->getMessage()
                ), FILE_APPEND);
                throw new RuntimeException(
                    'Failed to restart browser daemon: '.$e->getMessage(),
                    0,
                    $e
                );
            }
        }

        @file_put_contents(getcwd().'/.fuel/browser-debug.log', sprintf(
            "[%s] ensureBrowserRunning: all checks passed\n",
            date('H:i:s')
        ), FILE_APPEND);
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
