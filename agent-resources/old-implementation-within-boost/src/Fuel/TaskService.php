<?php

declare(strict_types=1);

namespace Laravel\Boost\Fuel;

use Closure;
use Illuminate\Support\Collection;
use RuntimeException;

class TaskService
{
    private string $storagePath;

    private string $prefix = 'fuel';

    private int $lockRetries = 10;

    private int $lockRetryDelayMs = 100;

    private int $maxTasks = 50;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? base_path('.ai/fuel.jsonl');
    }

    /**
     * Load all tasks from JSONL file (with shared lock for safe concurrent reads).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function all(): Collection
    {
        return $this->withSharedLock(fn (): \Illuminate\Support\Collection => $this->readTasks(excludeTombstones: true));
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
     * Create a new task (with exclusive lock to prevent lost updates).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        return $this->withExclusiveLock(function () use ($data): array {
            // Re-read inside lock to get latest state
            $tasks = $this->readTasks(excludeTombstones: false);

            $task = [
                'id' => $this->generateId($tasks->count()),
                'title' => $data['title'] ?? throw new RuntimeException('Task title is required'),
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? 'task',
                'status' => $data['status'] ?? 'open',
                'priority' => $data['priority'] ?? 2,
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
                'created_by' => $data['created_by'] ?? null,
                'dependencies' => $this->parseDependencies(is_array($data['dependencies'] ?? null) ? $data['dependencies'] : []),
                'labels' => $data['labels'] ?? [],
            ];

            // Validate dependencies exist
            foreach ($task['dependencies'] as $dep) {
                if (! $tasks->contains('id', $dep['depends_on'])) {
                    throw new RuntimeException("Dependency task '{$dep['depends_on']}' not found");
                }
            }

            $tasks->push($task);
            $this->writeTasks($tasks);

            return $task;
        });
    }

    /**
     * Update an existing task (with exclusive lock to prevent lost updates).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        return $this->withExclusiveLock(function () use ($id, $data): array {
            // Re-read inside lock to get latest state
            $tasks = $this->readTasks(excludeTombstones: false);
            $task = $this->findInCollection($tasks, $id);

            if ($task === null) {
                throw new RuntimeException("Task '{$id}' not found");
            }

            $updatable = ['title', 'description', 'type', 'status', 'priority', 'labels', 'dependencies', 'closed_reason'];

            foreach ($updatable as $field) {
                if (array_key_exists($field, $data)) {
                    if ($field === 'dependencies') {
                        $depData = $data[$field];
                        $task[$field] = $this->parseDependencies(is_array($depData) ? $depData : []);
                    } else {
                        $task[$field] = $data[$field];
                    }
                }
            }

            $task['updated_at'] = now()->toIso8601String();

            $tasks = $tasks->map(fn (array $t): array => $t['id'] === $task['id'] ? $task : $t);

            $this->writeTasks($tasks);

            return $task;
        });
    }

    /**
     * Close a task.
     *
     * @return array<string, mixed>
     */
    public function close(string $id, ?string $reason = null): array
    {
        $task = $this->find($id);

        if ($task === null) {
            throw new RuntimeException("Task '{$id}' not found");
        }

        $updateData = [
            'status' => 'closed',
        ];

        if ($reason !== null) {
            $updateData['closed_reason'] = $reason;
        }

        $taskId = $task['id'];
        if (! is_string($taskId)) {
            throw new RuntimeException('Task ID must be a string');
        }

        return $this->update($taskId, $updateData);
    }

    /**
     * Soft-delete a task (tombstone pattern, with exclusive lock).
     */
    public function delete(string $id): void
    {
        $this->withExclusiveLock(function () use ($id): void {
            // Re-read inside lock to get latest state
            $tasks = $this->readTasks(excludeTombstones: false);
            $task = $this->findInCollection($tasks, $id);

            if ($task === null) {
                throw new RuntimeException("Task '{$id}' not found");
            }

            // Convert to tombstone
            $tasks = $tasks->map(function (array $t) use ($task): array {
                if ($t['id'] === $task['id']) {
                    return [
                        'id' => $t['id'],
                        'title' => $t['title'],
                        'status' => 'tombstone',
                        'deleted_at' => now()->toIso8601String(),
                        'deleted_by' => 'user',
                    ];
                }

                return $t;
            });

            $this->writeTasks($tasks);
        });
    }

    /**
     * Get tasks that are ready to work on (no open blockers).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ready(): Collection
    {
        $tasks = $this->all();

        // Build list of blocked task IDs
        $blockedIds = [];

        foreach ($tasks as $task) {
            /** @var array<int, array{depends_on: string, type: string}> $dependencies */
            $dependencies = $task['dependencies'] ?? [];
            foreach ($dependencies as $dep) {
                if ($dep['type'] === 'blocks') {
                    $blocker = $tasks->firstWhere('id', $dep['depends_on']);
                    // Task is blocked if blocker exists and is not closed
                    if ($blocker && ! in_array($blocker['status'], ['closed', 'tombstone'], true)) {
                        $blockedIds[] = $task['id'];
                    }
                }
            }
        }

        // Filter to open tasks without blockers
        return $tasks
            ->filter(fn (array $t): bool => $t['status'] === 'open')
            ->filter(fn (array $t): bool => ! in_array($t['id'], $blockedIds, true))
            ->sortBy([
                ['priority', 'asc'],
                ['created_at', 'asc'],
            ])
            ->values();
    }

    /**
     * Get tasks that are blocked by other tasks.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function blocked(): Collection
    {
        $tasks = $this->all();

        // Build list of blocked task IDs with their blockers
        $blockedIds = [];

        foreach ($tasks as $task) {
            /** @var array<int, array{depends_on: string, type: string}> $dependencies */
            $dependencies = $task['dependencies'] ?? [];
            foreach ($dependencies as $dep) {
                if ($dep['type'] === 'blocks') {
                    $blocker = $tasks->firstWhere('id', $dep['depends_on']);
                    if ($blocker && ! in_array($blocker['status'], ['closed', 'tombstone'], true)) {
                        $blockedIds[] = $task['id'];
                    }
                }
            }
        }

        return $tasks
            ->filter(fn (array $t): bool => in_array($t['id'], $blockedIds, true))
            ->filter(fn (array $t): bool => $t['status'] === 'open')
            ->sortBy([
                ['priority', 'asc'],
                ['created_at', 'asc'],
            ])
            ->values();
    }

    /**
     * Search tasks with filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function search(array $filters): Collection
    {
        $tasks = $this->all();

        if (isset($filters['status'])) {
            $tasks = $tasks->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $tasks = $tasks->where('type', $filters['type']);
        }

        if (isset($filters['priority'])) {
            /** @phpstan-ignore cast.int (priority filter is numeric when set) */
            $tasks = $tasks->where('priority', (int) $filters['priority']);
        }

        if (isset($filters['labels'])) {
            $filterLabels = is_array($filters['labels']) ? $filters['labels'] : [$filters['labels']];
            $tasks = $tasks->filter(function (array $task) use ($filterLabels): bool {
                /** @var array<int, string> $taskLabels */
                $taskLabels = $task['labels'] ?? [];

                return count(array_intersect($filterLabels, $taskLabels)) === count($filterLabels);
            });
        }

        return $tasks
            ->sortBy([
                ['priority', 'asc'],
                ['created_at', 'asc'],
            ])
            ->values();
    }

    /**
     * Add a dependency between tasks.
     */
    public function addDependency(string $from, string $to, string $type = 'blocks'): void
    {
        $fromTask = $this->find($from);
        $toTask = $this->find($to);

        if ($fromTask === null) {
            throw new RuntimeException("Task '{$from}' not found");
        }

        if ($toTask === null) {
            throw new RuntimeException("Task '{$to}' not found");
        }

        $fromTaskId = $fromTask['id'];
        $toTaskId = $toTask['id'];
        if (! is_string($fromTaskId) || ! is_string($toTaskId)) {
            throw new RuntimeException('Task IDs must be strings');
        }

        // Check for cycles
        if (! $this->validateNoCycles($fromTaskId, $toTaskId)) {
            throw new RuntimeException(
                'Circular dependency detected! Adding this dependency would create a cycle.'
            );
        }

        /** @var array<int, array{depends_on: string, type: string}> $dependencies */
        $dependencies = $fromTask['dependencies'] ?? [];

        foreach ($dependencies as $dep) {
            if ($dep['depends_on'] === $toTaskId && $dep['type'] === $type) {
                return; // Already exists
            }
        }

        $dependencies[] = [
            'depends_on' => $toTaskId,
            'type' => $type,
        ];

        $this->update($fromTaskId, ['dependencies' => $dependencies]);
    }

    /**
     * Remove a dependency between tasks.
     */
    public function removeDependency(string $from, string $to): void
    {
        $fromTask = $this->find($from);
        $toTask = $this->find($to);

        if ($fromTask === null) {
            throw new RuntimeException("Task '{$from}' not found");
        }

        if ($toTask === null) {
            throw new RuntimeException("Task '{$to}' not found");
        }

        $fromTaskId = $fromTask['id'];
        $toTaskId = $toTask['id'];
        if (! is_string($fromTaskId) || ! is_string($toTaskId)) {
            throw new RuntimeException('Task IDs must be strings');
        }

        /** @var array<int, array{depends_on: string, type: string}> $deps */
        $deps = $fromTask['dependencies'] ?? [];
        $dependencies = collect($deps)
            ->reject(fn (array $dep): bool => $dep['depends_on'] === $toTaskId)
            ->values()
            ->all();

        $this->update($fromTaskId, ['dependencies' => $dependencies]);
    }

    /**
     * Get all dependencies of a task.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getDependencies(string $id): Collection
    {
        $task = $this->find($id);

        if ($task === null) {
            throw new RuntimeException("Task '{$id}' not found");
        }

        $tasks = $this->all();
        /** @var Collection<int, array{task: array<string, mixed>, type: string}> $dependencies */
        $dependencies = collect();

        /** @var array<int, array{depends_on: string, type: string}> $taskDeps */
        $taskDeps = $task['dependencies'] ?? [];
        foreach ($taskDeps as $dep) {
            $depTask = $tasks->firstWhere('id', $dep['depends_on']);
            if ($depTask) {
                $dependencies->push([
                    'task' => $depTask,
                    'type' => $dep['type'],
                ]);
            }
        }

        return $dependencies;
    }

    /**
     * Get tasks that block a given task.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getBlockers(string $id): Collection
    {
        /** @var Collection<int, array<string, mixed>> $blockers */
        $blockers = $this->getDependencies($id)
            ->filter(fn (array $dep): bool => $dep['type'] === 'blocks')
            ->pluck('task');

        return $blockers;
    }

    /**
     * Validate that adding a dependency won't create a cycle.
     */
    public function validateNoCycles(string $from, string $to): bool
    {
        $tasks = $this->all()->keyBy('id');
        $visited = [];
        $queue = [$to];

        while (! empty($queue)) {
            $current = array_shift($queue);

            if ($current === $from) {
                return false; // Cycle detected!
            }

            if (in_array($current, $visited, true)) {
                continue;
            }

            $visited[] = $current;

            // Add all tasks this one depends on to the queue
            $task = $tasks->get($current);
            if ($task) {
                /** @var array<int, array{depends_on: string, type: string}> $taskDeps */
                $taskDeps = $task['dependencies'] ?? [];
                foreach ($taskDeps as $dep) {
                    if ($dep['type'] === 'blocks') {
                        $queue[] = $dep['depends_on'];
                    }
                }
            }
        }

        return true; // No cycle
    }

    /**
     * Generate a hash-based ID with adaptive length.
     */
    public function generateId(int $taskCount = 0): string
    {
        // Adaptive length based on task count
        $length = match (true) {
            $taskCount < 500 => 4,
            $taskCount < 1500 => 5,
            $taskCount < 10000 => 6,
            default => 7,
        };

        $hash = hash('sha256', uniqid($this->prefix.'-', true).microtime(true));

        return $this->prefix.'-'.substr($hash, 0, $length);
    }

    /**
     * Initialize the storage file.
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
     * Set the maximum number of tasks to keep (completed tasks are pruned when exceeded).
     */
    public function setMaxTasks(int $maxTasks): self
    {
        $this->maxTasks = $maxTasks;

        return $this;
    }

    /**
     * Get the maximum number of tasks.
     */
    public function getMaxTasks(): int
    {
        return $this->maxTasks;
    }

    /**
     * Get pruning statistics to determine if pruning is recommended.
     *
     * @return array{total: int, closed: int, max: int, should_prune: bool, prunable: int}
     */
    public function getPruneStats(): array
    {
        $tasks = $this->all();
        $total = $tasks->count();
        $closed = $tasks->filter(fn (array $t): bool => ($t['status'] ?? '') === 'closed')->count();

        return [
            'total' => $total,
            'closed' => $closed,
            'max' => $this->maxTasks,
            'should_prune' => $total > $this->maxTasks,
            'prunable' => max(0, $total - $this->maxTasks),
        ];
    }

    /**
     * Prune old completed tasks to keep the file size manageable.
     * Removes oldest closed tasks first when total tasks exceed maxTasks.
     * Tombstones are always removed during pruning.
     *
     * @return int Number of tasks pruned
     */
    public function prune(): int
    {
        return $this->withExclusiveLock(function (): int {
            $tasks = $this->readTasks(excludeTombstones: false);

            // Remove all tombstones first
            $tasksWithoutTombstones = $tasks->filter(
                fn (array $t): bool => ($t['status'] ?? '') !== 'tombstone'
            );

            $totalTasks = $tasksWithoutTombstones->count();
            $prunedCount = $tasks->count() - $totalTasks; // Count removed tombstones

            // If under limit, just write without tombstones and return
            if ($totalTasks <= $this->maxTasks) {
                if ($prunedCount > 0) {
                    $this->writeTasks($tasksWithoutTombstones);
                }

                return $prunedCount;
            }

            // Need to prune closed tasks
            $tasksToRemove = $totalTasks - $this->maxTasks;

            // Get closed tasks sorted by updated_at, then by ID (oldest first)
            $closedTasks = $tasksWithoutTombstones
                ->filter(fn (array $t): bool => ($t['status'] ?? '') === 'closed')
                ->sortBy([
                    ['updated_at', 'asc'],
                    ['id', 'asc'],
                ])
                ->values();

            // Get IDs of tasks to remove (oldest closed first)
            $idsToRemove = $closedTasks
                ->take($tasksToRemove)
                ->pluck('id')
                ->toArray();

            // Filter out the tasks to remove
            $remainingTasks = $tasksWithoutTombstones->filter(
                fn (array $t): bool => ! in_array($t['id'], $idsToRemove, true)
            );

            $this->writeTasks($remainingTasks);

            return $prunedCount + count($idsToRemove);
        });
    }

    /**
     * Execute a callback with an exclusive lock (for write operations).
     * Uses retry logic with exponential backoff.
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
     * @param  int<1,4>  $lockType
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
                /** @phpstan-ignore argument.type (bitwise OR with LOCK_NB is safe) */
                if (flock($handle, $lockType | LOCK_NB)) {
                    $lockAcquired = true;
                    break;
                }

                $attempts++;
                usleep($delay * 1000); // Convert ms to microseconds
                $delay = min($delay * 2, 1000); // Cap at 1 second
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
    private function readTasks(bool $excludeTombstones = true): Collection
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

            if ($excludeTombstones && (is_array($task) && ($task['status'] ?? '') === 'tombstone')) {
                continue;
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
     * Find a task in a collection by ID (supports partial matching).
     *
     * @param  Collection<int, array<string, mixed>>  $tasks
     * @return array<string, mixed>|null
     */
    private function findInCollection(Collection $tasks, string $id): ?array
    {
        // Filter out tombstones for finding
        $activeTasks = $tasks->filter(fn (array $t): bool => ($t['status'] ?? '') !== 'tombstone');

        // Try exact match first
        $task = $activeTasks->firstWhere('id', $id);
        if ($task !== null) {
            return $task;
        }

        // Try partial match (prefix matching)
        $matches = $activeTasks->filter(function (array $task) use ($id): bool {
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
     * Parse dependency input into proper format.
     *
     * @param  array<int|string, mixed>  $dependencies
     * @return array<int, array{depends_on: string, type: string}>
     */
    private function parseDependencies(array $dependencies): array
    {
        $parsed = [];

        foreach ($dependencies as $dep) {
            if (is_string($dep)) {
                // Simple string format: just the ID
                $parsed[] = [
                    'depends_on' => $dep,
                    'type' => 'blocks',
                ];
            } elseif (is_array($dep)) {
                // Already in correct format
                $dependsOn = $dep['depends_on'] ?? $dep['id'] ?? '';
                $type = $dep['type'] ?? 'blocks';
                $parsed[] = [
                    'depends_on' => is_string($dependsOn) ? $dependsOn : '',
                    'type' => is_string($type) ? $type : 'blocks',
                ];
            }
        }

        return array_values(array_filter($parsed, fn (array $dep): bool => ! empty($dep['depends_on'])));
    }
}
