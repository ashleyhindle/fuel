<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Collection;
use RuntimeException;

class TaskService
{
    private string $storagePath;

    private string $prefix = 'fuel';

    private int $lockRetries = 10;

    private int $lockRetryDelayMs = 100;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? getcwd().'/.fuel/tasks.jsonl';
    }

    /**
     * Load all tasks from JSONL file (with shared lock for safe concurrent reads).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function all(): Collection
    {
        return $this->withSharedLock(fn (): Collection => $this->readTasks());
    }

    /**
     * Find a task by ID (supports partial ID matching).
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $tasks = $this->all();

        // Try exact match first
        $task = $tasks->firstWhere('id', $id);
        if ($task !== null) {
            return $task;
        }

        // Try partial match (prefix matching)
        $matches = $tasks->filter(function (array $task) use ($id): bool {
            $taskId = $task['id'];
            if (! is_string($taskId)) {
                return false;
            }

            return str_starts_with($taskId, $id) ||
                   str_starts_with($taskId, $this->prefix.'-'.$id);
        });

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1) {
            throw new RuntimeException(
                "Ambiguous task ID '{$id}'. Matches: ".$matches->pluck('id')->implode(', ')
            );
        }

        return null;
    }

    /**
     * Create a new task.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        return $this->withExclusiveLock(function () use ($data): array {
            $tasks = $this->readTasks();

            // Validate type enum
            $validTypes = ['bug', 'feature', 'task', 'epic', 'chore'];
            $type = $data['type'] ?? 'task';
            if (! in_array($type, $validTypes, true)) {
                throw new RuntimeException(
                    "Invalid task type '{$type}'. Must be one of: ".implode(', ', $validTypes)
                );
            }

            // Validate priority range
            $priority = $data['priority'] ?? 2;
            if (! is_int($priority) || $priority < 0 || $priority > 4) {
                throw new RuntimeException(
                    "Invalid priority '{$priority}'. Must be an integer between 0 and 4."
                );
            }

            // Validate labels is an array
            $labels = $data['labels'] ?? [];
            if (! is_array($labels)) {
                throw new RuntimeException('Labels must be an array of strings.');
            }
            // Ensure all labels are strings
            foreach ($labels as $label) {
                if (! is_string($label)) {
                    throw new RuntimeException('All labels must be strings.');
                }
            }

            $task = [
                'id' => $this->generateId($tasks->count()),
                'title' => $data['title'] ?? throw new RuntimeException('Task title is required'),
                'status' => 'open',
                'description' => $data['description'] ?? null,
                'type' => $type,
                'priority' => $priority,
                'labels' => $labels,
                'dependencies' => $data['dependencies'] ?? [],
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ];

            $tasks->push($task);
            $this->writeTasks($tasks);

            return $task;
        });
    }

    /**
     * Mark a task as done (closed).
     *
     * @return array<string, mixed>
     */
    public function done(string $id): array
    {
        return $this->withExclusiveLock(function () use ($id): array {
            $tasks = $this->readTasks();
            $task = $this->findInCollection($tasks, $id);

            if ($task === null) {
                throw new RuntimeException("Task '{$id}' not found");
            }

            $task['status'] = 'closed';
            $task['updated_at'] = now()->toIso8601String();

            $tasks = $tasks->map(fn (array $t): array => $t['id'] === $task['id'] ? $task : $t);
            $this->writeTasks($tasks);

            return $task;
        });
    }

    /**
     * Get tasks that are ready (open with no open blockers).
     *
     * A task is blocked if it has a dependency where depends_on points to
     * a task with status != 'closed'.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ready(): Collection
    {
        $tasks = $this->all();

        // Build a map of task ID to task for quick lookups
        $taskMap = $tasks->keyBy('id');

        // Find all blocked task IDs
        $blockedIds = [];
        foreach ($tasks as $task) {
            $dependencies = $task['dependencies'] ?? [];
            foreach ($dependencies as $dep) {
                if (($dep['type'] ?? '') === 'blocks') {
                    $blockerId = $dep['depends_on'] ?? null;
                    if ($blockerId !== null) {
                        $blocker = $taskMap->get($blockerId);
                        // Task is blocked if the blocker exists and is not closed
                        if ($blocker !== null && ($blocker['status'] ?? '') !== 'closed') {
                            $blockedIds[] = $task['id'];
                            break; // No need to check other dependencies
                        }
                    }
                }
            }
        }

        // Return open tasks that are not blocked
        return $tasks
            ->filter(fn (array $t): bool => ($t['status'] ?? '') === 'open')
            ->filter(fn (array $t): bool => ! in_array($t['id'], $blockedIds, true))
            ->sortBy('created_at')
            ->values();
    }

    /**
     * Add a dependency to a task.
     *
     * @return array<string, mixed> The updated task
     *
     * @throws RuntimeException If task or dependency target doesn't exist, or if adding would create a cycle
     */
    public function addDependency(string $taskId, string $dependsOnId, string $type = 'blocks'): array
    {
        return $this->withExclusiveLock(function () use ($taskId, $dependsOnId, $type): array {
            $tasks = $this->readTasks();

            // Find the task to add dependency to
            $task = $this->findInCollection($tasks, $taskId);
            if ($task === null) {
                throw new RuntimeException("Task '{$taskId}' not found");
            }

            // Validate that the dependency target exists
            $dependsOnTask = $this->findInCollection($tasks, $dependsOnId);
            if ($dependsOnTask === null) {
                throw new RuntimeException("Dependency target '{$dependsOnId}' not found");
            }

            // Use the actual resolved IDs
            $resolvedTaskId = $task['id'];
            $resolvedDependsOnId = $dependsOnTask['id'];

            // Prevent self-dependency
            if ($resolvedTaskId === $resolvedDependsOnId) {
                throw new RuntimeException('A task cannot depend on itself');
            }

            // Check for cycles using BFS
            // We want to add: $resolvedTaskId depends on $resolvedDependsOnId
            // This creates a cycle if $resolvedDependsOnId (transitively) depends on $resolvedTaskId
            if (! $this->validateNoCycles($tasks, $resolvedTaskId, $resolvedDependsOnId)) {
                throw new RuntimeException(
                    'Circular dependency detected! Adding this dependency would create a cycle.'
                );
            }

            // Initialize dependencies array if not present
            if (! isset($task['dependencies'])) {
                $task['dependencies'] = [];
            }

            // Check if dependency already exists
            foreach ($task['dependencies'] as $dep) {
                if (($dep['depends_on'] ?? '') === $resolvedDependsOnId) {
                    // Dependency already exists, just return the task
                    return $task;
                }
            }

            // Add the dependency
            $task['dependencies'][] = [
                'depends_on' => $resolvedDependsOnId,
                'type' => $type,
            ];
            $task['updated_at'] = now()->toIso8601String();

            // Update the task in the collection
            $tasks = $tasks->map(fn (array $t): array => $t['id'] === $resolvedTaskId ? $task : $t);
            $this->writeTasks($tasks);

            return $task;
        });
    }

    /**
     * Get tasks that block the given task (open dependencies).
     *
     * @return Collection<int, array<string, mixed>>
     *
     * @throws RuntimeException If task doesn't exist
     */
    public function getBlockers(string $taskId): Collection
    {
        $tasks = $this->all();
        $taskMap = $tasks->keyBy('id');

        // Find the task
        $task = $this->findInCollection($tasks, $taskId);
        if ($task === null) {
            throw new RuntimeException("Task '{$taskId}' not found");
        }

        $blockers = collect();
        $dependencies = $task['dependencies'] ?? [];

        foreach ($dependencies as $dep) {
            if (($dep['type'] ?? '') === 'blocks') {
                $blockerId = $dep['depends_on'] ?? null;
                if ($blockerId !== null) {
                    $blocker = $taskMap->get($blockerId);
                    // Only include open blockers (not closed)
                    if ($blocker !== null && ($blocker['status'] ?? '') !== 'closed') {
                        $blockers->push($blocker);
                    }
                }
            }
        }

        return $blockers->values();
    }

    /**
     * Generate a hash-based ID.
     */
    public function generateId(int $taskCount = 0): string
    {
        $length = 4; // MVP: always 4 chars

        $hash = hash('sha256', uniqid($this->prefix.'-', true).microtime(true));

        return $this->prefix.'-'.substr($hash, 0, $length);
    }

    /**
     * Initialize the storage directory and file.
     */
    public function initialize(): void
    {
        $dir = dirname($this->storagePath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! file_exists($this->storagePath)) {
            file_put_contents($this->storagePath, '');
        }
    }

    /**
     * Get the storage path.
     */
    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    /**
     * Set custom storage path.
     */
    public function setStoragePath(string $path): self
    {
        $this->storagePath = $path;

        return $this;
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
        $lockPath = $this->storagePath.'.lock';
        $dir = dirname($lockPath);

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
     * Read tasks from JSONL file (internal, no locking - caller must handle).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function readTasks(): Collection
    {
        if (! file_exists($this->storagePath)) {
            return collect();
        }

        $content = file_get_contents($this->storagePath);
        if ($content === false || trim($content) === '') {
            return collect();
        }

        $tasks = collect();
        $lines = explode("\n", trim($content));

        foreach ($lines as $lineNumber => $line) {
            if (trim($line) === '') {
                continue;
            }

            $task = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(
                    'Failed to parse tasks.jsonl on line '.($lineNumber + 1).': '.json_last_error_msg()
                );
            }

            $tasks->push($task);
        }

        return $tasks;
    }

    /**
     * Write tasks to JSONL file (internal, no locking - caller must handle).
     *
     * @param  Collection<int, array<string, mixed>>  $tasks
     */
    private function writeTasks(Collection $tasks): void
    {
        $dir = dirname($this->storagePath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Sort by ID for merge-friendly git diffs
        $sorted = $tasks->sortBy('id')->values();

        $content = $sorted
            ->map(fn (array $task): string => (string) json_encode($task, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->implode("\n");

        if ($content !== '') {
            $content .= "\n";
        }

        // Atomic write: temp file + rename
        $tempPath = $this->storagePath.'.tmp';
        file_put_contents($tempPath, $content);
        rename($tempPath, $this->storagePath);
    }

    /**
     * Validate that adding a dependency won't create a cycle.
     *
     * Uses BFS to check if $dependsOnId (transitively) depends on $taskId.
     * If it does, adding "$taskId depends on $dependsOnId" would create a cycle.
     *
     * @param  Collection<int, array<string, mixed>>  $tasks
     */
    private function validateNoCycles(Collection $tasks, string $taskId, string $dependsOnId): bool
    {
        $taskMap = $tasks->keyBy('id');
        $visited = [];
        $queue = [$dependsOnId];

        while (! empty($queue)) {
            $current = array_shift($queue);

            // If we reached the task we're adding dependency to, there's a cycle
            if ($current === $taskId) {
                return false;
            }

            // Skip if already visited
            if (in_array($current, $visited, true)) {
                continue;
            }

            $visited[] = $current;

            // Add all dependencies of current task to the queue
            $currentTask = $taskMap->get($current);
            if ($currentTask !== null) {
                $dependencies = $currentTask['dependencies'] ?? [];
                foreach ($dependencies as $dep) {
                    if (($dep['type'] ?? '') === 'blocks') {
                        $depId = $dep['depends_on'] ?? null;
                        if ($depId !== null && ! in_array($depId, $visited, true)) {
                            $queue[] = $depId;
                        }
                    }
                }
            }
        }

        return true; // No cycle detected
    }

    /**
     * Remove a dependency between tasks.
     *
     * @return array<string, mixed> The updated "from" task
     */
    public function removeDependency(string $fromId, string $toId): array
    {
        return $this->withExclusiveLock(function () use ($fromId, $toId): array {
            $tasks = $this->readTasks();

            $fromTask = $this->findInCollection($tasks, $fromId);
            if ($fromTask === null) {
                throw new RuntimeException("Task '{$fromId}' not found");
            }

            $toTask = $this->findInCollection($tasks, $toId);
            if ($toTask === null) {
                throw new RuntimeException("Task '{$toId}' not found");
            }

            $dependencies = $fromTask['dependencies'] ?? [];

            // Find and remove the dependency
            $found = false;
            $newDependencies = [];
            foreach ($dependencies as $dep) {
                if ($dep['depends_on'] === $toTask['id']) {
                    $found = true;
                } else {
                    $newDependencies[] = $dep;
                }
            }

            if (! $found) {
                throw new RuntimeException('No dependency exists between these tasks');
            }

            $fromTask['dependencies'] = $newDependencies;
            $fromTask['updated_at'] = now()->toIso8601String();

            $tasks = $tasks->map(fn (array $t): array => $t['id'] === $fromTask['id'] ? $fromTask : $t);
            $this->writeTasks($tasks);

            return $fromTask;
        });
    }

    /**
     * Find a task in a collection by ID (supports partial matching).
     *
     * @param  Collection<int, array<string, mixed>>  $tasks
     * @return array<string, mixed>|null
     */
    private function findInCollection(Collection $tasks, string $id): ?array
    {
        // Try exact match first
        $task = $tasks->firstWhere('id', $id);
        if ($task !== null) {
            return $task;
        }

        // Try partial match (prefix matching)
        $matches = $tasks->filter(function (array $task) use ($id): bool {
            $taskId = $task['id'];
            if (! is_string($taskId)) {
                return false;
            }

            return str_starts_with($taskId, $id) ||
                   str_starts_with($taskId, $this->prefix.'-'.$id);
        });

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1) {
            throw new RuntimeException(
                "Ambiguous task ID '{$id}'. Matches: ".$matches->pluck('id')->implode(', ')
            );
        }

        return null;
    }
}
