<?php

declare(strict_types=1);

namespace App\Daemon;

use App\Services\FuelContext;
use App\Services\ProcessManager;
use DateTimeImmutable;

/**
 * Manages the lifecycle of the daemon runner.
 *
 * Responsibilities:
 * - Starting and stopping the runner
 * - Managing pause/resume state
 * - PID file management
 * - Cleanup operations
 */
final class LifecycleManager
{
    /** Flag indicating if runner is paused (stops accepting new tasks) */
    private bool $paused = true;

    /** Flag indicating if runner is shutting down */
    private bool $shuttingDown = false;

    /** Whether to perform graceful shutdown (vs force) */
    private bool $gracefulShutdown = true;

    /** Whether stop() was explicitly called (vs external signal) */
    private bool $stopCalled = false;

    /** Whether to skip cleanup (for testing) */
    private bool $skipCleanup = false;

    /** Unique instance identifier (UUID v4) */
    private readonly string $instanceId;

    /** When the runner was started */
    private readonly DateTimeImmutable $startedAt;

    public function __construct(
        private readonly FuelContext $fuelContext,
    ) {
        $this->instanceId = $this->generateInstanceId();
        $this->startedAt = new DateTimeImmutable;
    }

    public function start(int $port): void
    {
        $this->cleanupStalePidFile();
        $this->writePidFile($port);
    }

    public function stop(bool $graceful = true): void
    {
        $this->shuttingDown = true;
        $this->stopCalled = true;
        $this->gracefulShutdown = $graceful;
    }

    public function cleanup(): void
    {
        if ($this->skipCleanup || ! $this->stopCalled) {
            return;
        }

        $this->deletePidFile();
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function isShuttingDown(): bool
    {
        return $this->shuttingDown;
    }

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function isGracefulShutdown(): bool
    {
        return $this->gracefulShutdown;
    }

    public function isStopCalled(): bool
    {
        return $this->stopCalled;
    }

    public function setSkipCleanup(bool $skip): void
    {
        $this->skipCleanup = $skip;
    }

    public function isSkipCleanup(): bool
    {
        return $this->skipCleanup;
    }

    private function writePidFile(int $port): void
    {
        $pidFile = $this->fuelContext->getPidFilePath();
        $pidData = [
            'pid' => getmypid(),
            'started_at' => $this->startedAt->format(DateTimeImmutable::ATOM),
            'instance_id' => $this->instanceId,
            'port' => $port,
        ];
        $dir = dirname($pidFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Open file with exclusive lock for writing
        $lockFile = $pidFile.'.lock';
        $lock = fopen($lockFile, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Failed to open lock file: '.$lockFile);
        }

        try {
            // Acquire exclusive lock
            if (! flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Failed to acquire lock for PID file: '.$pidFile);
            }

            // Write PID data atomically
            $tempFile = $pidFile.'.tmp.'.uniqid();
            if (file_put_contents($tempFile, json_encode($pidData, JSON_THROW_ON_ERROR)) === false) {
                throw new \RuntimeException('Failed to write temporary PID file: '.$tempFile);
            }

            // Atomic rename to replace the old PID file
            if (! rename($tempFile, $pidFile)) {
                @unlink($tempFile);
                throw new \RuntimeException('Failed to rename temporary PID file to: '.$pidFile);
            }

            chmod($pidFile, 0600);
        } finally {
            // Release lock and close lock file
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function deletePidFile(): void
    {
        $pidFile = $this->fuelContext->getPidFilePath();
        if (! file_exists($pidFile)) {
            return;
        }

        // Open lock file with exclusive lock for deletion
        $lockFile = $pidFile.'.lock';
        $lock = fopen($lockFile, 'c');
        if ($lock === false) {
            // If we can't open lock file, try to delete PID file anyway
            @unlink($pidFile);

            return;
        }

        try {
            // Acquire exclusive lock
            if (flock($lock, LOCK_EX) && file_exists($pidFile)) {
                unlink($pidFile);
            }
        } finally {
            // Release lock and close lock file
            flock($lock, LOCK_UN);
            fclose($lock);

            // Clean up lock file if no PID file exists
            if (! file_exists($pidFile)) {
                @unlink($lockFile);
            }
        }
    }

    private function cleanupStalePidFile(): void
    {
        $pidFile = $this->fuelContext->getPidFilePath();
        if (! file_exists($pidFile)) {
            return;
        }

        // Open lock file with exclusive lock for cleanup check
        $lockFile = $pidFile.'.lock';
        $lock = fopen($lockFile, 'c');
        if ($lock === false) {
            // If we can't open lock file, skip cleanup
            return;
        }

        try {
            // Acquire exclusive lock
            if (! flock($lock, LOCK_EX)) {
                return;
            }

            // Re-check existence after acquiring lock
            if (! file_exists($pidFile)) {
                return;
            }

            $content = file_get_contents($pidFile);
            if ($content === false) {
                return;
            }

            $data = json_decode($content, true);
            if (! is_array($data) || ! isset($data['pid'])) {
                unlink($pidFile);

                return;
            }

            $pid = (int) $data['pid'];
            if (! ProcessManager::isProcessAlive($pid)) {
                unlink($pidFile);

                // Clean up lock file after removing stale PID file
                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink($lockFile);

                return;
            }
        } finally {
            // Release lock if we still have it
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function generateInstanceId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
