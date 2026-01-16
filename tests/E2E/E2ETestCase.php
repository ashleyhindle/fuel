<?php

declare(strict_types=1);

namespace Tests\E2E;

use Tests\TestCase;

/**
 * Base class for E2E tests that require the consume daemon to be running.
 * These tests run the full stack: PHP command → IPC → consume daemon → browser-daemon.js → Playwright
 */
abstract class E2ETestCase extends TestCase
{
    protected ?int $daemonPid = null;

    protected string $pidFilePath;

    protected int $daemonPort = 19876; // Fixed port for E2E tests

    protected bool $daemonStarted = false;

    /**
     * Maximum time to wait for daemon to start (in seconds)
     */
    protected int $daemonStartTimeout = 10;

    /**
     * Maximum time to wait for commands to complete (in seconds)
     */
    protected int $commandTimeout = 30;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip if not explicitly enabled
        if (! $this->shouldRunE2ETests()) {
            $this->markTestSkipped('E2E tests are disabled. Run with --group=e2e to enable.');
        }

        // Set up PID file path for E2E tests
        $this->pidFilePath = sys_get_temp_dir().'/fuel-e2e-test.pid';

        // Clean up any leftover PID files
        $this->cleanupPidFiles();

        // Start the consume daemon
        $this->startDaemon();
    }

    protected function tearDown(): void
    {
        // Stop the daemon if we started it
        if ($this->daemonStarted) {
            $this->stopDaemon();
        }

        // Clean up PID files
        $this->cleanupPidFiles();

        parent::tearDown();
    }

    /**
     * Check if E2E tests should run
     */
    protected function shouldRunE2ETests(): bool
    {
        // Check for CI environment - skip E2E tests in CI
        if (getenv('CI') === 'true' || getenv('GITHUB_ACTIONS') === 'true') {
            return false;
        }

        // Check for explicit E2E flag
        return getenv('RUN_E2E_TESTS') === 'true' || in_array('e2e', $GLOBALS['argv'] ?? []);
    }

    /**
     * Start the consume daemon in the background
     */
    protected function startDaemon(): void
    {
        // Check if daemon is already running
        if ($this->isDaemonRunning()) {
            $this->debugOutput('Daemon already running, using existing instance');

            return;
        }

        $this->debugOutput('Starting consume daemon...');

        // Start daemon with specific port for E2E tests
        $command = sprintf(
            'FUEL_IPC_PORT=%d ./fuel consume --start > /dev/null 2>&1',
            $this->daemonPort
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->fail('Failed to start consume daemon: '.implode("\n", $output));
        }

        // Wait for daemon to be ready
        $startTime = time();
        while (! $this->isDaemonRunning()) {
            if (time() - $startTime > $this->daemonStartTimeout) {
                $this->fail('Daemon failed to start within timeout');
            }
            usleep(500000); // 0.5 seconds
        }

        $this->daemonStarted = true;
        $this->debugOutput('Daemon started successfully');
    }

    /**
     * Stop the consume daemon
     */
    protected function stopDaemon(): void
    {
        if (! $this->daemonStarted) {
            return;
        }

        $this->debugOutput('Stopping consume daemon...');

        // IMPORTANT: Use the same port we started the daemon on, not the default port!
        exec(sprintf('./fuel consume --stop --port=%d > /dev/null 2>&1', $this->daemonPort), $output, $returnCode);

        // Give it time to stop gracefully
        sleep(1);

        // Force stop if still running
        if ($this->isDaemonRunning()) {
            exec(sprintf('./fuel consume --force --port=%d > /dev/null 2>&1', $this->daemonPort));
            sleep(1);
        }

        $this->daemonStarted = false;
        $this->debugOutput('Daemon stopped');
    }

    /**
     * Check if the daemon is running
     */
    protected function isDaemonRunning(): bool
    {
        // IMPORTANT: Use the same port we started the daemon on, not the default port!
        exec(sprintf('./fuel consume --status --port=%d 2>&1', $this->daemonPort), $output, $returnCode);

        // If status command succeeds, daemon is running
        return $returnCode === 0;
    }

    /**
     * Clean up any leftover PID files
     */
    protected function cleanupPidFiles(): void
    {
        $pidFiles = glob(sys_get_temp_dir().'/fuel*.pid');
        foreach ($pidFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Run a fuel browser command and return the output
     */
    protected function runBrowserCommand(string $command, array $args = []): array
    {
        $fullCommand = './fuel '.$command;

        if (! empty($args)) {
            foreach ($args as $key => $value) {
                if (is_bool($value)) {
                    if ($value) {
                        $fullCommand .= ' '.$key;
                    }
                } elseif (strpos($key, '--') === 0) {
                    $fullCommand .= ' '.escapeshellarg($key).'='.escapeshellarg($value);
                } else {
                    $fullCommand .= ' '.escapeshellarg($value);
                }
            }
        }

        $this->debugOutput("Running: $fullCommand");

        exec($fullCommand.' 2>&1', $output, $returnCode);

        return [
            'output' => $output,
            'returnCode' => $returnCode,
            'outputString' => implode("\n", $output),
        ];
    }

    /**
     * Run a browser command and expect it to succeed
     */
    protected function assertBrowserCommandSucceeds(string $command, array $args = []): array
    {
        $result = $this->runBrowserCommand($command, $args);

        $this->assertEquals(0, $result['returnCode'],
            "Command failed: $command\nOutput: ".$result['outputString']);

        return $result;
    }

    /**
     * Run a browser command and expect it to fail
     */
    protected function assertBrowserCommandFails(string $command, array $args = []): array
    {
        $result = $this->runBrowserCommand($command, $args);

        $this->assertNotEquals(0, $result['returnCode'],
            "Command unexpectedly succeeded: $command\nOutput: ".$result['outputString']);

        return $result;
    }

    /**
     * Parse JSON output from a browser command
     */
    protected function parseJsonOutput(string $output): array
    {
        // Find JSON in output (commands might have non-JSON output before the JSON)
        $lines = explode("\n", $output);
        $jsonLine = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{') || str_starts_with($line, '[')) {
                $jsonLine = $line;
                break;
            }
        }

        if (! $jsonLine) {
            $this->fail("No JSON found in output: $output");
        }

        $decoded = json_decode($jsonLine, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail("Invalid JSON in output: $jsonLine");
        }

        return $decoded;
    }

    /**
     * Wait for a condition to be true
     */
    protected function waitFor(callable $condition, int $timeoutSeconds = 5, string $message = 'Condition not met'): void
    {
        $startTime = time();
        while (! $condition()) {
            if (time() - $startTime > $timeoutSeconds) {
                $this->fail($message);
            }
            usleep(100000); // 0.1 seconds
        }
    }

    /**
     * Output debug message if verbose
     */
    protected function debugOutput(string $message): void
    {
        if (in_array('--verbose', $GLOBALS['argv'] ?? [])) {
            echo "[E2E] $message\n";
        }
    }
}
