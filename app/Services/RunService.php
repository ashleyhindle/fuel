<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use RuntimeException;

class RunService
{
    private const OUTPUT_MAX_LENGTH = 10240; // 10KB

    private string $storageBasePath;

    private int $lockRetries = 10;

    private int $lockRetryDelayMs = 100;

    public function __construct(?string $storageBasePath = null)
    {
        $this->storageBasePath = $storageBasePath ?? getcwd().'/.fuel/runs';
    }

    /**
     * Log a run for a task.
     *
     * @param  string  $taskId  Task ID (e.g., 'f-xxxxxx')
     * @param  array<string, mixed>  $data  Run data (agent, model, started_at, ended_at, exit_code, output)
     */
    public function logRun(string $taskId, array $data): void
    {
        $this->withExclusiveLock($taskId, function () use ($taskId, $data): void {
            $runs = $this->readRuns($taskId);

            // Generate run_id
            $runId = $this->generateRunId($runs);

            // Truncate output to 10KB if present
            $output = $data['output'] ?? null;
            if ($output !== null && is_string($output) && strlen($output) > self::OUTPUT_MAX_LENGTH) {
                $output = substr($output, 0, self::OUTPUT_MAX_LENGTH);
            }

            $run = [
                'run_id' => $runId,
                'agent' => $data['agent'] ?? null,
                'model' => $data['model'] ?? null,
                'started_at' => $data['started_at'] ?? now()->toIso8601String(),
                'ended_at' => $data['ended_at'] ?? null,
                'exit_code' => $data['exit_code'] ?? null,
                'output' => $output,
            ];

            $runs[] = $run;
            $this->writeRuns($taskId, $runs);
        });
    }

    /**
     * Get all runs for a task.
     *
     * @param  string  $taskId  Task ID
     * @return array<int, array<string, mixed>>
     */
    public function getRuns(string $taskId): array
    {
        return $this->withSharedLock($taskId, fn (): array => $this->readRuns($taskId));
    }

    /**
     * Get the latest run for a task.
     *
     * @param  string  $taskId  Task ID
     * @return array<string, mixed>|null
     */
    public function getLatestRun(string $taskId): ?array
    {
        $runs = $this->getRuns($taskId);

        if (empty($runs)) {
            return null;
        }

        // Return the last run (most recent)
        return $runs[count($runs) - 1];
    }

    /**
     * Update the latest run for a task with completion data.
     *
     * @param  string  $taskId  Task ID
     * @param  array<string, mixed>  $data  Update data (ended_at, exit_code, output)
     */
    public function updateLatestRun(string $taskId, array $data): void
    {
        $this->withExclusiveLock($taskId, function () use ($taskId, $data): void {
            $runs = $this->readRuns($taskId);

            if (empty($runs)) {
                throw new RuntimeException("No runs found for task {$taskId} to update");
            }

            // Update the last run (most recent)
            $latestIndex = count($runs) - 1;
            $run = $runs[$latestIndex];

            // Truncate output to 10KB if present
            $output = $data['output'] ?? null;
            if ($output !== null && is_string($output) && strlen($output) > self::OUTPUT_MAX_LENGTH) {
                $output = substr($output, 0, self::OUTPUT_MAX_LENGTH);
            }

            // Update fields
            if (isset($data['ended_at'])) {
                $run['ended_at'] = $data['ended_at'];
            }
            if (isset($data['exit_code'])) {
                $run['exit_code'] = $data['exit_code'];
            }
            if (array_key_exists('output', $data)) {
                $run['output'] = $output;
            }

            $runs[$latestIndex] = $run;
            $this->writeRuns($taskId, $runs);
        });
    }

    /**
     * Clean up orphaned runs (runs that started but never completed due to consume crash).
     * Marks incomplete runs as failed with a special exit code and message.
     *
     * @param  callable  $isPidDead  Callback (int $pid): bool to check if a PID is dead
     * @return int  Number of orphaned runs cleaned up
     */
    public function cleanupOrphanedRuns(callable $isPidDead): int
    {
        $cleanupCount = 0;
        $dir = $this->storageBasePath;

        if (! is_dir($dir)) {
            return 0;
        }

        // Iterate through all run files
        $files = glob($dir.'/*.jsonl');
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            $taskId = basename($file, '.jsonl');

            // Skip lock files
            if (str_ends_with($taskId, '.lock')) {
                continue;
            }

            // Check this task's runs
            $this->withExclusiveLock($taskId, function () use ($taskId, $isPidDead, &$cleanupCount): void {
                $runs = $this->readRuns($taskId);
                $modified = false;

                foreach ($runs as $index => $run) {
                    // Skip runs that are already completed
                    if (isset($run['ended_at']) && $run['ended_at'] !== null) {
                        continue;
                    }

                    // This is an incomplete run - mark it as orphaned
                    $run['ended_at'] = date('c');
                    $run['exit_code'] = -1;
                    $run['output'] = '[Run orphaned - consume process died before completion]';

                    $runs[$index] = $run;
                    $modified = true;
                    $cleanupCount++;
                }

                if ($modified) {
                    $this->writeRuns($taskId, $runs);
                }
            });
        }

        return $cleanupCount;
    }

    /**
     * Get the storage path for a task's runs file.
     */
    private function getRunsPath(string $taskId): string
    {
        return $this->storageBasePath.'/'.$taskId.'.jsonl';
    }

    /**
     * Generate a unique run ID.
     *
     * @param  array<int, array<string, mixed>>  $existingRuns
     */
    private function generateRunId(array $existingRuns): string
    {
        $length = 6;
        $maxAttempts = 100;

        $existingIds = array_map(fn (array $run): string => $run['run_id'] ?? '', $existingRuns);
        $existingIds = array_filter($existingIds, fn (string $id): bool => $id !== '');

        $attempts = 0;
        while ($attempts < $maxAttempts) {
            $hash = hash('sha256', uniqid('run-', true).microtime(true));
            $id = 'run-'.substr($hash, 0, $length);

            if (! in_array($id, $existingIds, true)) {
                return $id;
            }

            $attempts++;
        }

        throw new RuntimeException(
            "Failed to generate unique run ID after {$maxAttempts} attempts. This is extremely unlikely."
        );
    }

    /**
     * Execute a callback with an exclusive lock (for write operations).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withExclusiveLock(string $taskId, Closure $callback): mixed
    {
        return $this->withLock($taskId, LOCK_EX, $callback);
    }

    /**
     * Execute a callback with a shared lock (for read operations).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withSharedLock(string $taskId, Closure $callback): mixed
    {
        return $this->withLock($taskId, LOCK_SH, $callback);
    }

    /**
     * Execute a callback with a file lock.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withLock(string $taskId, int $lockType, Closure $callback): mixed
    {
        $runsPath = $this->getRunsPath($taskId);
        $lockPath = $runsPath.'.lock';
        $dir = dirname($runsPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! file_exists($lockPath)) {
            touch($lockPath);
        }

        $handle = fopen($lockPath, 'r+');
        if ($handle === false) {
            throw new RuntimeException('Failed to open lock file: '.$lockPath);
        }

        $lockAcquired = false;
        $attempts = 0;
        $delay = $this->lockRetryDelayMs;

        try {
            // Retry with exponential backoff
            while ($attempts < $this->lockRetries) {
                if (flock($handle, $lockType | LOCK_NB)) {
                    $lockAcquired = true;
                    break;
                }

                $attempts++;
                usleep($delay * 1000);
                $delay = min($delay * 2, 1000);
            }

            // Final blocking attempt if retries exhausted
            if (! $lockAcquired && ! flock($handle, $lockType)) {
                throw new RuntimeException(
                    'Failed to acquire file lock after '.$this->lockRetries.' attempts'
                );
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Read runs from JSONL file (internal, no locking - caller must handle).
     *
     * @return array<int, array<string, mixed>>
     */
    private function readRuns(string $taskId): array
    {
        $runsPath = $this->getRunsPath($taskId);

        if (! file_exists($runsPath)) {
            return [];
        }

        $content = file_get_contents($runsPath);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $runs = [];
        $lines = explode("\n", trim($content));

        foreach ($lines as $lineNumber => $line) {
            if (trim($line) === '') {
                continue;
            }

            $run = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(
                    'Failed to parse runs file on line '.($lineNumber + 1).': '.json_last_error_msg()
                );
            }

            $runs[] = $run;
        }

        return $runs;
    }

    /**
     * Write runs to JSONL file (internal, no locking - caller must handle).
     *
     * @param  array<int, array<string, mixed>>  $runs
     */
    private function writeRuns(string $taskId, array $runs): void
    {
        $runsPath = $this->getRunsPath($taskId);
        $dir = dirname($runsPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = implode("\n", array_map(
            fn (array $run): string => (string) json_encode($run, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $runs
        ));

        if ($content !== '') {
            $content .= "\n";
        }

        // Atomic write: temp file + rename
        $tempPath = $runsPath.'.tmp';
        file_put_contents($tempPath, $content);
        rename($tempPath, $runsPath);
    }
}
