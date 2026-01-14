<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use RuntimeException;
use Throwable;

class BrowserDaemonManager
{
    private static ?BrowserDaemonManager $instance = null;

    private $daemonProcess = null;

    private array $pipes = [];

    private int $requestIdSeq = 0;

    private array $pendingRequests = [];

    private array $contextPageMap = [];

    private bool $shutdownRegistered = false;

    /**
     * Get the singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Start the browser daemon if not already running
     */
    public function start(): void
    {
        if ($this->isRunning()) {
            return;
        }

        $daemonPath = base_path('browser-daemon.js');
        if (! file_exists($daemonPath)) {
            throw new RuntimeException("Browser daemon script not found at: {$daemonPath}");
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $this->daemonProcess = proc_open(
            ['node', $daemonPath],
            $descriptorSpec,
            $this->pipes,
            base_path(),
            null,
            ['bypass_shell' => true]
        );

        if (! is_resource($this->daemonProcess)) {
            throw new RuntimeException('Failed to start browser daemon');
        }

        // Make stdout non-blocking for async reads
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        // Register shutdown handler
        if (! $this->shutdownRegistered) {
            register_shutdown_function([$this, 'stop']);
            $this->shutdownRegistered = true;
        }

        // Verify daemon started successfully
        try {
            $result = $this->sendRequest('ping', []);
            if (! isset($result['status']) || $result['status'] !== 'ok') {
                throw new RuntimeException('Browser daemon ping failed');
            }
        } catch (Exception $e) {
            $this->stop();
            throw new RuntimeException('Failed to verify browser daemon: '.$e->getMessage());
        }
    }

    /**
     * Stop the browser daemon
     */
    public function stop(): void
    {
        if (! $this->isRunning()) {
            return;
        }

        // Close all contexts before stopping
        foreach (array_keys($this->contextPageMap) as $contextId) {
            try {
                $this->closeContext($contextId);
            } catch (Throwable $e) {
                // Ignore errors during shutdown
            }
        }

        // Close pipes
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        // Terminate the process
        if (is_resource($this->daemonProcess)) {
            proc_terminate($this->daemonProcess, SIGTERM);
            usleep(100000); // Give it 100ms to terminate gracefully

            $status = proc_get_status($this->daemonProcess);
            if ($status['running']) {
                proc_terminate($this->daemonProcess, SIGKILL);
            }

            proc_close($this->daemonProcess);
        }

        $this->daemonProcess = null;
        $this->pipes = [];
        $this->pendingRequests = [];
        $this->contextPageMap = [];
    }

    /**
     * Check if the daemon is running
     */
    public function isRunning(): bool
    {
        if (! is_resource($this->daemonProcess)) {
            return false;
        }

        $status = proc_get_status($this->daemonProcess);

        return $status['running'] ?? false;
    }

    /**
     * Send a request to the daemon and wait for response
     */
    public function sendRequest(string $method, array $params): array
    {
        if (! $this->isRunning()) {
            $this->start();
        }

        $requestId = ++$this->requestIdSeq;
        $request = [
            'id' => $requestId,
            'method' => $method,
            'params' => $params,
        ];

        // Write request
        $json = json_encode($request)."\n";
        $written = fwrite($this->pipes[0], $json);
        if ($written === false) {
            throw new RuntimeException('Failed to write to daemon stdin');
        }
        fflush($this->pipes[0]);

        // Read response (with timeout)
        $timeout = 30; // seconds
        $startTime = time();
        $response = null;
        $buffer = '';

        while (time() - $startTime < $timeout) {
            $chunk = fread($this->pipes[1], 8192);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;

                // Check for complete JSON lines
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    if (trim($line) === '') {
                        continue;
                    }

                    $data = json_decode($line, true);
                    if ($data === null) {
                        throw new RuntimeException('Invalid JSON response from daemon: '.$line);
                    }

                    if (isset($data['id']) && $data['id'] === $requestId) {
                        $response = $data;
                        break 2;
                    }
                }
            }

            usleep(10000); // 10ms
        }

        if ($response === null) {
            throw new RuntimeException('Timeout waiting for daemon response');
        }

        if (! $response['ok']) {
            throw new RuntimeException(
                'Daemon error: '.($response['error']['message'] ?? 'Unknown error').
                ' (code: '.($response['error']['code'] ?? 'ERR').')'
            );
        }

        return $response['result'] ?? [];
    }

    /**
     * Create a new browser context
     */
    public function createContext(string $contextId, array $options = []): array
    {
        $params = array_merge([
            'contextId' => $contextId,
        ], $options);

        $result = $this->sendRequest('newContext', $params);

        // Track context
        if (! isset($this->contextPageMap[$contextId])) {
            $this->contextPageMap[$contextId] = [];
        }

        return $result;
    }

    /**
     * Create a new page in a context
     */
    public function createPage(string $contextId, string $pageId): array
    {
        $result = $this->sendRequest('newPage', [
            'contextId' => $contextId,
            'pageId' => $pageId,
        ]);

        // Track page-context relationship
        if (! isset($this->contextPageMap[$contextId])) {
            $this->contextPageMap[$contextId] = [];
        }
        $this->contextPageMap[$contextId][] = $pageId;

        return $result;
    }

    /**
     * Navigate a page to a URL
     */
    public function goto(string $pageId, string $url, array $options = []): array
    {
        $params = array_merge([
            'pageId' => $pageId,
            'url' => $url,
        ], $options);

        return $this->sendRequest('goto', $params);
    }

    /**
     * Evaluate JavaScript in a page
     */
    public function eval(string $pageId, string $expression): array
    {
        return $this->sendRequest('eval', [
            'pageId' => $pageId,
            'expression' => $expression,
        ]);
    }

    /**
     * Take a screenshot of a page
     */
    public function screenshot(string $pageId, ?string $path = null, bool $fullPage = false): array
    {
        $params = [
            'pageId' => $pageId,
            'fullPage' => $fullPage,
        ];

        if ($path !== null) {
            $params['path'] = $path;
        }

        return $this->sendRequest('screenshot', $params);
    }

    /**
     * Close a browser context and all its pages
     */
    public function closeContext(string $contextId): array
    {
        $result = $this->sendRequest('closeContext', [
            'contextId' => $contextId,
        ]);

        // Remove from tracking
        unset($this->contextPageMap[$contextId]);

        return $result;
    }

    /**
     * Get daemon status
     */
    public function status(): array
    {
        if (! $this->isRunning()) {
            return [
                'browserLaunched' => false,
                'contexts' => [],
                'pages' => [],
                'daemonRunning' => false,
            ];
        }

        try {
            $result = $this->sendRequest('status', []);
            $result['daemonRunning'] = true;

            return $result;
        } catch (Exception $e) {
            return [
                'browserLaunched' => false,
                'contexts' => [],
                'pages' => [],
                'daemonRunning' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Prevent cloning of singleton
     */
    private function __clone()
    {
        // Prevent cloning
    }

    /**
     * Prevent unserialization of singleton
     */
    public function __wakeup()
    {
        throw new RuntimeException('Cannot unserialize singleton');
    }
}
