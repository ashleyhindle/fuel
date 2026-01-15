<?php

declare(strict_types=1);

namespace App\Daemon;

/**
 * Simple file-based logger for the daemon process.
 *
 * Supports log levels: debug, info, warning, error
 * In development, logs everything. In production/bundled, can filter to info+.
 */
final class DaemonLogger
{
    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
    ];

    private static ?self $instance = null;

    private string $logPath;

    private int $minLevel;

    private function __construct(string $logPath, string $minLevel = 'debug')
    {
        $this->logPath = $logPath;
        $this->minLevel = self::LEVELS[$minLevel] ?? 0;
    }

    /**
     * Get or create the singleton instance.
     */
    public static function getInstance(?string $logPath = null): self
    {
        if (self::$instance === null) {
            $path = $logPath ?? getcwd().'/.fuel/daemon.log';
            self::$instance = new self($path);
        }

        return self::$instance;
    }

    /**
     * Set the minimum log level.
     */
    public function setMinLevel(string $level): void
    {
        $this->minLevel = self::LEVELS[$level] ?? 0;
    }

    /**
     * Log a debug message (verbose, development only).
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Log an info message (normal operations).
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Log a warning message (potential issues).
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * Log an error message (failures).
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Log an exception with stack trace.
     */
    public function exception(\Throwable $e, string $message = ''): void
    {
        $this->log('error', $message ?: $e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    /**
     * Write a log entry.
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $levelValue = self::LEVELS[$level] ?? 0;
        if ($levelValue < $this->minLevel) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s.v');
        $levelUpper = strtoupper($level);

        $line = sprintf("[%s] [%s] %s", $timestamp, $levelUpper, $message);

        if ($context !== []) {
            $line .= ' '.json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        $line .= "\n";

        @file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Clear the log file (useful on daemon start).
     */
    public function clear(): void
    {
        @file_put_contents($this->logPath, '');
    }

    /**
     * Get the log file path.
     */
    public function getLogPath(): string
    {
        return $this->logPath;
    }
}
