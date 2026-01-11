<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Collection;
use RuntimeException;

class BacklogService
{
    private int $lockRetries = 10;

    private int $lockRetryDelayMs = 100;

    public function __construct(private readonly FuelContext $context)
    {
    }

    /**
     * Get the storage path.
     */
    public function getStoragePath(): string
    {
        return $this->context->getBacklogPath();
    }

    /**
     * Load all backlog items from JSONL file.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function all(): Collection
    {
        return $this->withSharedLock(fn (): Collection => $this->readBacklog());
    }

    /**
     * Add a new item to the backlog.
     *
     * @return array<string, mixed>
     */
    public function add(string $title, ?string $description = null): array
    {
        return $this->withExclusiveLock(function () use ($title, $description): array {
            $backlog = $this->readBacklog();

            $item = [
                'id' => $this->generateId($backlog),
                'title' => $title,
                'description' => $description,
                'created_at' => now()->toIso8601String(),
            ];

            $backlog->push($item);
            $this->writeBacklog($backlog);

            return $item;
        });
    }

    /**
     * Find a backlog item by ID (supports partial ID matching).
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $backlog = $this->all();

        // Try exact match first
        $item = $backlog->firstWhere('id', $id);
        if ($item !== null) {
            return $item;
        }

        // Try partial match (prefix matching)
        $matches = $backlog->filter(function (array $item) use ($id): bool {
            $itemId = $item['id'];
            if (! is_string($itemId)) {
                return false;
            }

            return str_starts_with($itemId, $id) ||
                   str_starts_with($itemId, 'b-'.$id);
        });

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1) {
            throw new RuntimeException(
                sprintf("Ambiguous backlog ID '%s'. Matches: ", $id).$matches->pluck('id')->implode(', ')
            );
        }

        return null;
    }

    /**
     * Delete a backlog item by ID.
     *
     * @return array<string, mixed> The deleted item
     */
    public function delete(string $id): array
    {
        return $this->withExclusiveLock(function () use ($id): array {
            $backlog = $this->readBacklog();
            $item = $this->findInCollection($backlog, $id);

            if ($item === null) {
                throw new RuntimeException(sprintf("Backlog item '%s' not found", $id));
            }

            $backlog = $backlog->filter(fn (array $i): bool => $i['id'] !== $item['id']);
            $this->writeBacklog($backlog);

            return $item;
        });
    }

    /**
     * Generate a hash-based ID for backlog items with collision detection.
     *
     * @param  Collection<int, array<string, mixed>>  $existingItems
     *
     * @throws RuntimeException If unable to generate unique ID after max attempts
     */
    public function generateId(?Collection $existingItems = null): string
    {
        $prefix = 'b';
        $length = 6;
        $maxAttempts = 100;

        $existingIds = [];
        if ($existingItems instanceof Collection) {
            $existingIds = $existingItems->pluck('id')->toArray();
        }

        $attempts = 0;
        while ($attempts < $maxAttempts) {
            $hash = hash('sha256', uniqid($prefix.'-', true).microtime(true));
            $id = $prefix.'-'.substr($hash, 0, $length);

            if (! in_array($id, $existingIds, true)) {
                return $id;
            }

            $attempts++;
        }

        throw new RuntimeException(
            sprintf('Failed to generate unique backlog ID after %d attempts.', $maxAttempts)
        );
    }

    /**
     * Initialize the storage directory and file.
     */
    public function initialize(): void
    {
        $dir = dirname($this->getStoragePath());

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! file_exists($this->getStoragePath())) {
            file_put_contents($this->getStoragePath(), '');
        }
    }

    /**
     * Read backlog from JSONL file (internal, no locking - caller must handle).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function readBacklog(): Collection
    {
        if (! file_exists($this->getStoragePath())) {
            return collect();
        }

        $content = file_get_contents($this->getStoragePath());
        if ($content === false || trim($content) === '') {
            return collect();
        }

        $items = collect();
        $lines = explode("\n", trim($content));

        foreach ($lines as $lineNumber => $line) {
            if (trim($line) === '') {
                continue;
            }

            $item = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(
                    'Failed to parse backlog.jsonl on line '.($lineNumber + 1).': '.json_last_error_msg()
                );
            }

            $items->push($item);
        }

        return $items;
    }

    /**
     * Write backlog to JSONL file (internal, no locking - caller must handle).
     *
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function writeBacklog(Collection $items): void
    {
        $dir = dirname($this->getStoragePath());

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Sort by ID for merge-friendly git diffs
        $sorted = $items->sortBy('id')->values();

        $content = $sorted
            ->map(fn (array $item): string => (string) json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->implode("\n");

        if ($content !== '') {
            $content .= "\n";
        }

        // Atomic write: temp file + rename
        $tempPath = $this->getStoragePath().'.tmp';
        file_put_contents($tempPath, $content);
        rename($tempPath, $this->getStoragePath());
    }

    /**
     * Find a backlog item in a collection by ID (supports partial matching).
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function findInCollection(Collection $items, string $id): ?array
    {
        // Try exact match first
        $item = $items->firstWhere('id', $id);
        if ($item !== null) {
            return $item;
        }

        // Try partial match (prefix matching)
        $matches = $items->filter(function (array $item) use ($id): bool {
            $itemId = $item['id'];
            if (! is_string($itemId)) {
                return false;
            }

            return str_starts_with($itemId, $id) ||
                   str_starts_with($itemId, 'b-'.$id);
        });

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1) {
            throw new RuntimeException(
                sprintf("Ambiguous backlog ID '%s'. Matches: ", $id).$matches->pluck('id')->implode(', ')
            );
        }

        return null;
    }

    /**
     * Execute a callback with an exclusive lock (for write operations).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withExclusiveLock(Closure $callback): mixed
    {
        return $this->withLock(LOCK_EX, $callback);
    }

    /**
     * Execute a callback with a shared lock (for read operations).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withSharedLock(Closure $callback): mixed
    {
        return $this->withLock(LOCK_SH, $callback);
    }

    /**
     * Execute a callback with a file lock.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withLock(int $lockType, Closure $callback): mixed
    {
        $lockPath = $this->getStoragePath().'.lock';
        $dir = dirname($lockPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! file_exists($lockPath)) {
            touch($lockPath);
        }

        $handle = fopen($lockPath, 'r+');
        if ($handle === false) {
            throw new RuntimeException('Failed to open backlog lock file: '.$lockPath);
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
                    'Failed to acquire backlog file lock after '.$this->lockRetries.' attempts'
                );
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
