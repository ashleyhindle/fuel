<?php

declare(strict_types=1);

namespace Tests\E2E;

use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

/**
 * Base class for E2E browser tests.
 *
 * Uses a class-level shared testDir that persists across all tests in the class.
 * This allows the daemon to be started once and reused for all tests.
 */
abstract class BrowserE2ETestCase extends BaseTestCase
{
    protected static ?int $daemonPid = null;

    protected static int $daemonPort = 0;

    protected static string $fuelBinary = '';

    /**
     * Shared test directory for the entire test class (not per-test).
     */
    protected static string $classTestDir = '';

    /**
     * Per-class setup: create shared testDir and start daemon.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::$fuelBinary = realpath(__DIR__.'/../../fuel');
        if (! static::$fuelBinary || ! is_executable(static::$fuelBinary)) {
            throw new \RuntimeException('Cannot find fuel binary');
        }

        static::$daemonPort = random_int(49152, 65535);

        // Create a class-level testDir that persists across all tests
        static::$classTestDir = sys_get_temp_dir().'/fuel-e2e-'.uniqid();
        @mkdir(static::$classTestDir.'/.fuel', 0755, true);

        // Create minimal config
        $config = <<<'YAML'
primary: test-agent
complexity:
  trivial: test-agent
  simple: test-agent
  moderate: test-agent
  complex: test-agent
agents:
  test-agent:
    driver: claude
    command: echo
YAML;
        file_put_contents(static::$classTestDir.'/.fuel/config.yaml', $config);
    }

    /**
     * Per-test setup: ensure daemon is running.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Start daemon if not running
        if (! static::$daemonPid || ! posix_kill(static::$daemonPid, 0)) {
            $this->startDaemon();
        }
    }

    /**
     * Per-class teardown: stop daemon and clean up.
     */
    public static function tearDownAfterClass(): void
    {
        // Stop daemon
        if (static::$daemonPid && @posix_kill(static::$daemonPid, 0)) {
            @posix_kill(static::$daemonPid, SIGTERM);
            usleep(500000);
            if (@posix_kill(static::$daemonPid, 0)) {
                @posix_kill(static::$daemonPid, SIGKILL);
            }
        }
        static::$daemonPid = null;

        // Clean up class testDir
        if (static::$classTestDir && File::exists(static::$classTestDir)) {
            File::deleteDirectory(static::$classTestDir);
        }
        static::$classTestDir = '';

        parent::tearDownAfterClass();
    }

    /**
     * Start daemon in the class testDir.
     */
    protected function startDaemon(): void
    {
        $port = static::$daemonPort;
        $command = sprintf(
            'cd %s && %s consume --start --port=%d > /tmp/fuel-e2e.log 2>&1',
            escapeshellarg(static::$classTestDir),
            escapeshellarg(static::$fuelBinary),
            $port
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $log = @file_get_contents('/tmp/fuel-e2e.log') ?: 'No log';
            throw new \RuntimeException("Failed to start daemon: $log");
        }

        // Wait for PID file
        $pidFile = static::$classTestDir.'/.fuel/consume.pid';
        $attempts = 0;
        while ($attempts < 30) {
            if (file_exists($pidFile)) {
                $data = json_decode(file_get_contents($pidFile), true);
                if (isset($data['pid'])) {
                    static::$daemonPid = $data['pid'];
                    static::$daemonPort = $data['port'] ?? $port;
                    break;
                }
            }
            usleep(100000);
            $attempts++;
        }

        if (! static::$daemonPid) {
            $log = @file_get_contents('/tmp/fuel-e2e.log') ?: 'No log';
            throw new \RuntimeException("No daemon PID: $log");
        }

        // Wait for ready
        $this->waitForDaemonReady();
    }

    /**
     * Wait for daemon to respond.
     */
    protected function waitForDaemonReady(): void
    {
        $attempts = 0;
        while ($attempts < 30) {
            $result = $this->runCommand(['browser:status'], false);
            if ($result['exitCode'] === 0) {
                return;
            }
            sleep(1);
            $attempts++;
        }
        throw new \RuntimeException('Daemon not ready after 30s');
    }

    /**
     * Run fuel command in test workspace.
     * Browser commands auto-discover the daemon via .fuel/consume.pid in the cwd.
     */
    protected function runCommand(array $args, bool $expectSuccess = true): array
    {
        $command = sprintf(
            'cd %s && %s %s 2>&1',
            escapeshellarg(static::$classTestDir),
            escapeshellarg(static::$fuelBinary),
            implode(' ', array_map('escapeshellarg', $args))
        );

        exec($command, $output, $exitCode);

        $result = [
            'output' => implode("\n", $output),
            'exitCode' => $exitCode,
            'success' => $exitCode === 0,
        ];

        if ($expectSuccess && $exitCode !== 0) {
            throw new \RuntimeException(
                'Command failed: '.implode(' ', $args)."\nOutput: ".$result['output']
            );
        }

        return $result;
    }

    /**
     * Run command and parse JSON.
     */
    protected function runJsonCommand(array $args): array
    {
        if (! in_array('--json', $args)) {
            $args[] = '--json';
        }

        $result = $this->runCommand($args);
        $json = json_decode($result['output'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON: '.$result['output']);
        }

        return $json;
    }

    // Helper methods for browser operations

    protected function createTestBrowser(): array
    {
        $contextId = 'context-'.uniqid();
        $pageId = 'page-'.uniqid();
        $this->runJsonCommand(['browser:create', $contextId, $pageId]);

        return ['contextId' => $contextId, 'pageId' => $pageId];
    }

    protected function closeBrowser(string $contextId): void
    {
        $this->runJsonCommand(['browser:close', $contextId]);
    }

    protected function navigateTo(string $pageId, string $url): void
    {
        $this->runJsonCommand(['browser:goto', $pageId, $url]);
    }

    protected function getSnapshot(string $pageId, bool $interactive = false): array
    {
        $args = ['browser:snapshot', $pageId];
        if ($interactive) {
            $args[] = '--interactive';
        }

        return $this->runJsonCommand($args)['snapshot'] ?? [];
    }

    protected function takeScreenshot(string $pageId, ?string $file = null): string
    {
        $file = $file ?? '/tmp/fuel-e2e-'.uniqid().'.png';
        $this->runJsonCommand(['browser:screenshot', $pageId, '--path', $file]);
        $this->assertFileExists($file);

        return $file;
    }

    protected function fillField(string $pageId, string $ref, string $value): void
    {
        $this->runJsonCommand(['browser:fill', $pageId, '--ref', $ref, $value]);
    }

    protected function clickElement(string $pageId, string $ref): void
    {
        $this->runJsonCommand(['browser:click', $pageId, '--ref', $ref]);
    }

    protected function typeText(string $pageId, string $text): void
    {
        $this->runJsonCommand(['browser:type', $pageId, $text]);
    }

    protected function waitFor(string $pageId, string $condition, int $timeout = 5000): void
    {
        $args = ['browser:wait', $pageId, $condition, '--timeout', (string) $timeout];
        $this->runJsonCommand($args);
    }

    protected function getPageText(string $pageId): string
    {
        return $this->runJsonCommand(['browser:text', $pageId, 'body'])['text'] ?? '';
    }

    protected function getPageHtml(string $pageId): string
    {
        return $this->runJsonCommand(['browser:html', $pageId, 'body'])['html'] ?? '';
    }

    protected function parseSnapshotRefs(string $text): array
    {
        preg_match_all('/\[ref=(@e\d+)\]/', $text, $matches);

        return $matches[1] ?? [];
    }

    protected function findRefsInSnapshot(string $text, string $pattern): array
    {
        $refs = [];
        foreach (explode("\n", $text) as $line) {
            if (stripos($line, $pattern) !== false && preg_match('/\[ref=(@e\d+)\]/', $line, $m)) {
                $refs[] = $m[1];
            }
        }

        return $refs;
    }
}
