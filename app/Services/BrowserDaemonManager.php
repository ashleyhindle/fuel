<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use RuntimeException;
use Throwable;

class BrowserDaemonManager
{
    private static ?BrowserDaemonManager $instance = null;

    private $daemonProcess;

    private array $pipes = [];

    private int $requestIdSeq = 0;

    private array $contextPageMap = [];

    private bool $shutdownRegistered = false;

    /**
     * Get the singleton instance
     */
    public static function getInstance(): self
    {
        if (! self::$instance instanceof \App\Services\BrowserDaemonManager) {
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

        $daemonPath = $this->getDaemonPath();
        if (! file_exists($daemonPath)) {
            throw new RuntimeException('Browser daemon script not found at: '.$daemonPath);
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        // Use daemon's directory as cwd (base_path() is invalid inside PHAR)
        $cwd = dirname($daemonPath);

        $this->daemonProcess = proc_open(
            ['node', $daemonPath],
            $descriptorSpec,
            $this->pipes,
            $cwd,
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
        } catch (Exception $exception) {
            $this->stop();
            throw new RuntimeException('Failed to verify browser daemon: '.$exception->getMessage(), $exception->getCode(), $exception);
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
            } catch (Throwable) {
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
        $this->contextPageMap = [];
    }

    /**
     * Get the path to the browser daemon script.
     *
     * When running from source, returns the local browser-daemon.js.
     * When running from PHAR/binary, extracts the bundled daemon to ~/.fuel/
     */
    private function getDaemonPath(): string
    {
        $pharPath = \Phar::running(false);

        // Running from source - use file directly
        if ($pharPath === '') {
            return base_path('browser-daemon.js');
        }

        // Running from PHAR/binary - always extract to ~/.fuel/browser-daemon-dist/
        // (ensures updates get new daemon version)
        $fuelDir = (getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir()).'/.fuel';
        $extractDir = $fuelDir.'/browser-daemon-dist';
        $extractedPath = $extractDir.'/index.js';

        $this->extractBrowserDaemon($pharPath, $extractDir);

        return $extractedPath;
    }

    /**
     * Extract the browser daemon directory from PHAR to disk.
     */
    private function extractBrowserDaemon(string $pharPath, string $extractDir): void
    {
        $pharDir = 'phar://'.$pharPath.'/browser-daemon-dist';

        if (! is_dir($pharDir)) {
            throw new RuntimeException('Browser daemon not found in PHAR: '.$pharDir);
        }

        if (! is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }

        // Extract all files from the browser-daemon-dist directory
        $files = ['index.js', 'package.json', 'appIcon.png', 'xdg-open'];
        foreach ($files as $file) {
            $source = $pharDir.'/'.$file;
            $dest = $extractDir.'/'.$file;

            if (is_file($source)) {
                $content = file_get_contents($source);
                if ($content !== false) {
                    file_put_contents($dest, $content);
                    // Make xdg-open executable
                    if ($file === 'xdg-open' || $file === 'index.js') {
                        chmod($dest, 0755);
                    }
                }
            }
        }
    }

    /**
     * Check if the daemon process is running
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
     * Check if the daemon is healthy (running AND responsive).
     * Uses a quick ping with short timeout to verify responsiveness.
     */
    public function isHealthy(): bool
    {
        if (! $this->isRunning()) {
            return false;
        }

        try {
            $result = $this->ping(3); // 3 second timeout for health check

            return $result['status'] === 'ok';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Send a ping to the daemon to verify it's responsive.
     *
     * @param  int  $timeout  Timeout in seconds (default 5)
     * @return array{status: string} Ping result
     *
     * @throws RuntimeException If ping fails or times out
     */
    public function ping(int $timeout = 5): array
    {
        if (! $this->isRunning()) {
            throw new RuntimeException('Browser daemon is not running');
        }

        $requestId = ++$this->requestIdSeq;
        $request = [
            'id' => $requestId,
            'method' => 'ping',
            'params' => [],
        ];

        // Write request
        $json = json_encode($request)."\n";
        $written = fwrite($this->pipes[0], $json);
        if ($written === false) {
            throw new RuntimeException('Failed to write to daemon stdin');
        }

        fflush($this->pipes[0]);

        // Read response with short timeout
        $startTime = time();
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
                        continue; // Skip malformed lines
                    }

                    if (isset($data['id']) && $data['id'] === $requestId && ($data['ok'] ?? false)) {
                        return $data['result'] ?? ['status' => 'ok'];
                    }
                }
            }

            usleep(10000); // 10ms
        }

        throw new RuntimeException('Ping timeout - browser daemon unresponsive');
    }

    /**
     * Restart the browser daemon.
     * Stops the current daemon (if running) and starts a new one.
     *
     * @throws RuntimeException If restart fails
     */
    public function restart(): void
    {
        $this->stop();
        $this->start();
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

        // Debug: Log what we're sending
        @file_put_contents(getcwd().'/.fuel/browser-send-debug.log', sprintf(
            '[%s] Sending to daemon: %s',
            date('H:i:s'),
            $json
        ), FILE_APPEND);

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

        // Debug: Log response received
        @file_put_contents(getcwd().'/.fuel/browser-response-debug.log', sprintf(
            "[%s] Response for method=%s: %s\n",
            date('H:i:s'),
            $method,
            json_encode($response['result'] ?? [])
        ), FILE_APPEND);

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
     * Run Playwright code in a page
     */
    public function run(string $pageId, string $code): array
    {
        return $this->sendRequest('run', [
            'pageId' => $pageId,
            'code' => $code,
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
     * Take an accessibility snapshot of the page
     */
    public function snapshot(string $pageId, bool $interactiveOnly = false): array
    {
        return $this->sendRequest('snapshot', [
            'pageId' => $pageId,
            'interactiveOnly' => $interactiveOnly,
        ]);
    }

    /**
     * Click an element on the page
     */
    public function click(string $pageId, ?string $selector, ?string $ref): array
    {
        $params = [
            'pageId' => $pageId,
        ];

        if ($selector !== null) {
            $params['selector'] = $selector;
        }

        if ($ref !== null) {
            $params['ref'] = $ref;
        }

        return $this->sendRequest('click', $params);
    }

    /**
     * Fill an input field on the page
     */
    public function fill(string $pageId, ?string $selector, string $value, ?string $ref): array
    {
        $params = [
            'pageId' => $pageId,
            'value' => $value,
        ];

        if ($selector !== null) {
            $params['selector'] = $selector;
        }

        if ($ref !== null) {
            $params['ref'] = $ref;
        }

        return $this->sendRequest('fill', $params);
    }

    /**
     * Type text into an element on the page
     */
    public function type(string $pageId, ?string $selector, string $text, ?string $ref, int $delay = 0): array
    {
        $params = [
            'pageId' => $pageId,
            'text' => $text,
            'delay' => $delay,
        ];

        if ($selector !== null) {
            $params['selector'] = $selector;
        }

        if ($ref !== null) {
            $params['ref'] = $ref;
        }

        return $this->sendRequest('type', $params);
    }

    /**
     * Get text content from an element
     */
    public function text(string $pageId, ?string $selector, ?string $ref): array
    {
        $params = [
            'pageId' => $pageId,
        ];

        if ($selector !== null) {
            $params['selector'] = $selector;
        }

        if ($ref !== null) {
            $params['ref'] = $ref;
        }

        return $this->sendRequest('text', $params);
    }

    /**
     * Get HTML content from an element
     */
    public function html(string $pageId, ?string $selector, ?string $ref, bool $inner = false): array
    {
        $params = [
            'pageId' => $pageId,
            'inner' => $inner,
        ];

        if ($selector !== null) {
            $params['selector'] = $selector;
        }

        if ($ref !== null) {
            $params['ref'] = $ref;
        }

        return $this->sendRequest('html', $params);
    }

    /**
     * Wait for a condition (selector, URL, or text) on a page
     */
    public function wait(string $pageId, ?string $selector, ?string $url, ?string $text, string $state = 'visible', int $timeout = 30000): array
    {
        $params = [
            'pageId' => $pageId,
            'state' => $state,
            'timeout' => $timeout,
        ];

        if ($selector !== null) {
            $params['selector'] = $selector;
        }
        if ($url !== null) {
            $params['url'] = $url;
        }
        if ($text !== null) {
            $params['text'] = $text;
        }

        return $this->sendRequest('wait', $params);
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
        } catch (Exception $exception) {
            return [
                'browserLaunched' => false,
                'contexts' => [],
                'pages' => [],
                'daemonRunning' => false,
                'error' => $exception->getMessage(),
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
