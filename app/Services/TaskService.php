<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Collection;
use RuntimeException;

class TaskService
{
    private const VALID_TYPES = ['bug', 'feature', 'task', 'epic', 'chore', 'docs', 'test'];

    private const VALID_SIZES = ['xs', 's', 'm', 'l', 'xl'];

    private const VALID_COMPLEXITIES = ['trivial', 'simple', 'moderate', 'complex'];

    private const VALID_STATUSES = ['open', 'in_progress', 'closed'];

    private string $storagePath;

    private string $prefix = 'f';

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
        // Support both old 'fuel-' prefix and new 'f-' prefix for backward compatibility
        $matches = $tasks->filter(function (array $task) use ($id): bool {
            $taskId = $task['id'];
            if (! is_string($taskId)) {
                return false;
            }

            return str_starts_with($taskId, $id) ||
                   str_starts_with($taskId, $this->prefix.'-'.$id) ||
                   str_starts_with($taskId, 'fuel-'.$id);
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
     * Validate that a value is in a list of valid enum values.
     *
     * @param  array<string>  $validValues
     *
     * @throws RuntimeException If value is not in valid values
     */
    private function validateEnum(mixed $value, array $validValues, string $fieldName): void
    {
        if (! in_array($value, $validValues, true)) {
            throw new RuntimeException(
                "Invalid {$fieldName} '{$value}'. Must be one of: ".implode(', ', $validValues)
            );
        }
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
            $type = $data['type'] ?? 'task';
            $this->validateEnum($type, self::VALID_TYPES, 'task type');

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

            // Validate size enum
            $size = $data['size'] ?? 'm';
            $this->validateEnum($size, self::VALID_SIZES, 'task size');

            // Validate complexity enum
            $complexity = $data['complexity'] ?? 'simple';
            $this->validateEnum($complexity, self::VALID_COMPLEXITIES, 'task complexity');

            $task = [
                'id' => $this->generateId($tasks),
                'title' => $data['title'] ?? throw new RuntimeException('Task title is required'),
                'status' => 'open',
                'description' => $data['description'] ?? null,
                'type' => $type,
                'priority' => $priority,
                'labels' => $labels,
                'size' => $size,
                'complexity' => $complexity,
                'blocked_by' => $data['blocked_by'] ?? [],
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ];

            $tasks->push($task);
            $this->writeTasks($tasks);

            return $task;
        });
    }

    /**
     * Update a task with new data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        return $this->withExclusiveLock(function () use ($id, $data): array {
            $tasks = $this->readTasks();
            $task = $this->findInCollection($tasks, $id);

            if ($task === null) {
                throw new RuntimeException("Task '{$id}' not found");
            }

            // Update title if provided
            if (isset($data['title'])) {
                $task['title'] = $data['title'];
            }

            // Update description if provided
            if (array_key_exists('description', $data)) {
                $task['description'] = $data['description'];
            }

            // Update type if provided (with validation)
            if (isset($data['type'])) {
                $this->validateEnum($data['type'], self::VALID_TYPES, 'task type');
                $task['type'] = $data['type'];
            }

            // Update priority if provided (with validation)
            if (isset($data['priority'])) {
                $priority = $data['priority'];
                if (! is_int($priority) || $priority < 0 || $priority > 4) {
                    throw new RuntimeException(
                        "Invalid priority '{$priority}'. Must be an integer between 0 and 4."
                    );
                }
                $task['priority'] = $priority;
            }

            // Update status if provided (with validation)
            if (isset($data['status'])) {
                $this->validateEnum($data['status'], self::VALID_STATUSES, 'status');
                $task['status'] = $data['status'];
            }

            // Update size if provided (with validation)
            if (isset($data['size'])) {
                $this->validateEnum($data['size'], self::VALID_SIZES, 'task size');
                $task['size'] = $data['size'];
            }

            // Update complexity if provided (with validation)
            if (isset($data['complexity'])) {
                $this->validateEnum($data['complexity'], self::VALID_COMPLEXITIES, 'task complexity');
                $task['complexity'] = $data['complexity'];
            }

            // Handle labels updates
            if (isset($data['add_labels']) || isset($data['remove_labels'])) {
                $labels = $task['labels'] ?? [];
                $labels = is_array($labels) ? $labels : [];

                // Add labels
                if (isset($data['add_labels']) && is_array($data['add_labels'])) {
                    foreach ($data['add_labels'] as $label) {
                        if (is_string($label) && ! in_array($label, $labels, true)) {
                            $labels[] = $label;
                        }
                    }
                }

                // Remove labels
                if (isset($data['remove_labels']) && is_array($data['remove_labels'])) {
                    $labels = array_values(array_filter($labels, fn (string $label): bool => ! in_array($label, $data['remove_labels'], true)));
                }

                $task['labels'] = $labels;
            }

            // Preserve arbitrary fields not explicitly handled above (e.g., consumed, consumed_at, consumed_exit_code, consumed_output)
            $handledFields = ['title', 'description', 'type', 'priority', 'status', 'size', 'complexity', 'add_labels', 'remove_labels'];
            foreach ($data as $key => $value) {
                if (! in_array($key, $handledFields, true)) {
                    $task[$key] = $value;
                }
            }

            // Update updated_at only if not explicitly provided (allows setting custom dates for testing/archiving)
            if (! isset($data['updated_at'])) {
                $task['updated_at'] = now()->toIso8601String();
            }

            $tasks = $tasks->map(fn (array $t): array => $t['id'] === $task['id'] ? $task : $t);
            $this->writeTasks($tasks);

            return $task;
        });
    }

    /**
     * Mark a task as in progress (claim it).
     *
     * @return array<string, mixed>
     */
    public function start(string $id): array
    {
        return $this->withExclusiveLock(function () use ($id): array {
            $tasks = $this->readTasks();
            $task = $this->findInCollection($tasks, $id);

            if ($task === null) {
                throw new RuntimeException("Task '{$id}' not found");
            }

            $task['status'] = 'in_progress';
            $task['updated_at'] = now()->toIso8601String();

            $tasks = $tasks->map(fn (array $t): array => $t['id'] === $task['id'] ? $task : $t);
            $this->writeTasks($tasks);

            return $task;
        });
    }

    /**
     * Mark a task as done (closed).
     *
     * @param  string|null  $reason  Optional reason for completion
     * @param  string|null  $commitHash  Optional git commit hash
     * @return array<string, mixed>
     */
    public function done(string $id, ?string $reason = null, ?string $commitHash = null): array
    {
        return $this->withExclusiveLock(function () use ($id, $reason, $commitHash): array {
            $tasks = $this->readTasks();
            $task = $this->findInCollection($tasks, $id);

            if ($task === null) {
                throw new RuntimeException("Task '{$id}' not found");
            }

            $task['status'] = 'closed';
            $task['updated_at'] = now()->toIso8601String();
            if ($reason !== null) {
                $task['reason'] = $reason;
            }
            if ($commitHash !== null) {
                $task['commit_hash'] = $commitHash;
            }

            $tasks = $tasks->map(fn (array $t): array => $t['id'] === $task['id'] ? $task : $t);
            $this->writeTasks($tasks);

            return $task;
        });
    }

    /**
     * Reopen a closed or in_progress task (set status back to open).
     *
     * @return array<string, mixed>
     */
    public function reopen(string $id): array
    {
        return $this->withExclusiveLock(function () use ($id): array {
            $tasks = $this->readTasks();
            $task = $this->findInCollection($tasks, $id);

            if ($task === null) {
                throw new RuntimeException("Task '{$id}' not found");
            }

            $status = $task['status'] ?? '';
            if ($status !== 'closed' && $status !== 'in_progress') {
                throw new RuntimeException("Task '{$id}' is not closed or in_progress. Only closed or in_progress tasks can be reopened.");
            }

            $task['status'] = 'open';
            $task['updated_at'] = now()->toIso8601String();
            // Remove reason if it exists (since task is being reopened)
            unset($task['reason']);
            // Clear consumed fields so task can be retried cleanly
            unset($task['consumed'], $task['consumed_at'], $task['consumed_exit_code'], $task['consumed_output']);

            $tasks = $tasks->map(fn (array $t): array => $t['id'] === $task['id'] ? $task : $t);
            $this->writeTasks($tasks);

            return $task;
        });
    }

    /**
     * Retry a failed task by moving it back to open status.
     *
     * Accepts tasks that are:
     * - consumed=true with non-zero exit code (explicit failure)
     * - in_progress + consumed=true + null consume_pid (spawn failed or PID lost)
     *
     * @return array<string, mixed>
     */
    public function retry(string $id): array
    {
        return $this->withExclusiveLock(function () use ($id): array {
            $tasks = $this->readTasks();
            $task = $this->findInCollection($tasks, $id);

            if ($task === null) {
                throw new RuntimeException("Task '{$id}' not found");
            }

            $consumed = ! empty($task['consumed']);
            $status = $task['status'] ?? '';

            // Any consumed in_progress task can be retried (agent started but didn't complete)
            if (! ($consumed && $status === 'in_progress')) {
                throw new RuntimeException("Task '{$id}' is not a consumed in_progress task. Use 'reopen' for closed tasks.");
            }

            $task['status'] = 'open';
            $task['updated_at'] = now()->toIso8601String();
            // Remove reason if it exists (since task is being retried)
            unset($task['reason']);
            // Clear consumed fields so task can be retried cleanly
            unset($task['consumed'], $task['consumed_at'], $task['consumed_exit_code'], $task['consumed_output'], $task['consume_pid']);

            $tasks = $tasks->map(fn (array $t): array => $t['id'] === $task['id'] ? $task : $t);
            $this->writeTasks($tasks);

            return $task;
        });
    }

    /**
     * Get tasks that are ready (open with no open blockers).
     *
     * A task is blocked if it has a blocker ID in blocked_by that points to
     * a task with status != 'closed'.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ready(): Collection
    {
        return $this->readyFrom($this->all());
    }

    /**
     * Get tasks that are blocked (open with unresolved dependencies).
     *
     * A task is blocked if it has a blocker ID in blocked_by that points to
     * a task with status != 'closed'.
     *
     * This is the inverse of ready() - shows tasks that have open blockers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function blocked(): Collection
    {
        return $this->blockedFrom($this->all());
    }

    /**
     * Compute ready tasks from a given collection (for snapshot-based operations).
     *
     * @param  Collection<int, array<string, mixed>>  $tasks
     * @return Collection<int, array<string, mixed>>
     */
    public function readyFrom(Collection $tasks): Collection
    {
        $blockedIds = $this->getBlockedIds($tasks);

        return $tasks
            ->filter(fn (array $t): bool => ($t['status'] ?? '') === 'open')
            ->filter(fn (array $t): bool => ! in_array($t['id'], $blockedIds, true))
            ->filter(function (array $t): bool {
                $labels = $t['labels'] ?? [];
                if (! is_array($labels)) {
                    return true;
                }

                return ! in_array('needs-human', $labels, true);
            })
            ->sortBy([
                ['priority', 'asc'],
                ['created_at', 'asc'],
            ])
            ->values();
    }

    /**
     * Compute blocked tasks from a given collection (for snapshot-based operations).
     *
     * @param  Collection<int, array<string, mixed>>  $tasks
     * @return Collection<int, array<string, mixed>>
     */
    public function blockedFrom(Collection $tasks): Collection
    {
        $blockedIds = $this->getBlockedIds($tasks);

        return $tasks
            ->filter(fn (array $t): bool => ($t['status'] ?? '') === 'open')
            ->filter(fn (array $t): bool => in_array($t['id'], $blockedIds, true))
            ->sortBy([
                ['priority', 'asc'],
                ['created_at', 'asc'],
            ])
            ->values();
    }

    /**
     * Get IDs of tasks that are blocked by open dependencies.
     *
     * @param  Collection<int, array<string, mixed>>  $tasks
     * @return array<string>
     */
    public function getBlockedIds(Collection $tasks): array
    {
        $taskMap = $tasks->keyBy('id');
        $blockedIds = [];

        foreach ($tasks as $task) {
            $blockedBy = $task['blocked_by'] ?? [];
            foreach ($blockedBy as $blockerId) {
                if (is_string($blockerId)) {
                    $blocker = $taskMap->get($blockerId);
                    if ($blocker !== null && ($blocker['status'] ?? '') !== 'closed') {
                        $blockedIds[] = $task['id'];
                        break;
                    }
                }
            }
        }

        return $blockedIds;
    }

    /**
     * Check if a single task is failed/stuck.
     *
     * A task is failed if:
     * 1. consumed=true with non-zero exit code (explicit failure)
     * 2. in_progress + consumed=true + null consume_pid (spawn failed or PID lost)
     * 3. in_progress + consume_pid that is dead (if $isPidDead callback provided)
     *
     * @param  array<string, mixed>  $task
     * @param  callable|null  $isPidDead  Optional callback (int $pid): bool to check if a PID is dead
     * @param  array<int>  $excludePids  PIDs to exclude (e.g., actively tracked by current session)
     */
    public function isFailed(array $task, ?callable $isPidDead = null, array $excludePids = []): bool
    {
        $consumed = ! empty($task['consumed']);
        $exitCode = $task['consumed_exit_code'] ?? null;
        $status = $task['status'] ?? '';
        $pid = $task['consume_pid'] ?? null;

        // Case 1: Explicit failure (consumed with non-zero exit code)
        if ($consumed && $exitCode !== null && $exitCode !== 0) {
            return true;
        }

        // Case 2: in_progress + consumed + null PID (spawn failed or PID lost)
        if ($status === 'in_progress' && $consumed && $pid === null) {
            return true;
        }

        // Case 3: in_progress + PID that is dead (if callback provided)
        if ($status === 'in_progress' && $pid !== null && $isPidDead !== null) {
            $pidInt = (int) $pid;
            // Skip if this PID is being tracked by the caller
            if (in_array($pidInt, $excludePids, true)) {
                return false;
            }

            return $isPidDead($pidInt);
        }

        return false;
    }

    /**
     * Find failed/stuck tasks that need retry.
     *
     * @param  callable|null  $isPidDead  Optional callback (int $pid): bool to check if a PID is dead
     * @param  array<int>  $excludePids  PIDs to exclude (e.g., actively tracked by current session)
     * @return Collection<int, array<string, mixed>>
     */
    public function failed(?callable $isPidDead = null, array $excludePids = []): Collection
    {
        return $this->all()
            ->filter(fn (array $task): bool => $this->isFailed($task, $isPidDead, $excludePids))
            ->values();
    }

    /**
     * Add a dependency to a task (adds blocker to blocked_by array).
     *
     * @return array<string, mixed> The updated task
     *
     * @throws RuntimeException If task or dependency target doesn't exist, or if adding would create a cycle
     */
    public function addDependency(string $taskId, string $dependsOnId): array
    {
        return $this->withExclusiveLock(function () use ($taskId, $dependsOnId): array {
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

            // Initialize blocked_by array if not present
            if (! isset($task['blocked_by'])) {
                $task['blocked_by'] = [];
            }

            // Ensure blocked_by is an array
            if (! is_array($task['blocked_by'])) {
                $task['blocked_by'] = [];
            }

            // Check if dependency already exists
            if (in_array($resolvedDependsOnId, $task['blocked_by'], true)) {
                // Dependency already exists, just return the task
                return $task;
            }

            // Add the blocker ID to blocked_by array
            $task['blocked_by'][] = $resolvedDependsOnId;
            $task['updated_at'] = now()->toIso8601String();

            // Update the task in the collection
            $tasks = $tasks->map(fn (array $t): array => $t['id'] === $resolvedTaskId ? $task : $t);
            $this->writeTasks($tasks);

            return $task;
        });
    }

    /**
     * Get tasks that block the given task (open blockers).
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
        $blockedBy = $task['blocked_by'] ?? [];

        foreach ($blockedBy as $blockerId) {
            if (is_string($blockerId)) {
                $blocker = $taskMap->get($blockerId);
                // Only include open blockers (not closed)
                if ($blocker !== null && ($blocker['status'] ?? '') !== 'closed') {
                    $blockers->push($blocker);
                }
            }
        }

        return $blockers->values();
    }

    /**
     * Generate a hash-based ID with collision detection.
     *
     * @param  Collection<int, array<string, mixed>>|null  $existingTasks  Collection of existing tasks to check against
     *
     * @throws RuntimeException If unable to generate unique ID after max attempts
     */
    public function generateId(?Collection $existingTasks = null): string
    {
        $length = 6; // Increased from 4 to 6 chars for better collision resistance
        $maxAttempts = 100; // Safeguard against infinite loops

        // Extract existing IDs if tasks collection provided
        $existingIds = [];
        if ($existingTasks !== null) {
            $existingIds = $existingTasks->pluck('id')->toArray();
        }

        $attempts = 0;
        while ($attempts < $maxAttempts) {
            $hash = hash('sha256', uniqid($this->prefix.'-', true).microtime(true));
            $id = $this->prefix.'-'.substr($hash, 0, $length);

            // Check if ID already exists
            if (! in_array($id, $existingIds, true)) {
                return $id;
            }

            $attempts++;
        }

        throw new RuntimeException(
            "Failed to generate unique task ID after {$maxAttempts} attempts. This is extremely unlikely."
        );
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
     * Get the archive storage path.
     */
    public function getArchivePath(): string
    {
        return dirname($this->storagePath).'/archive.jsonl';
    }

    /**
     * Archive closed tasks older than N days (or all closed tasks if $all is true).
     *
     * @param  int  $days  Age threshold in days (ignored if $all is true)
     * @param  bool  $all  Archive all closed tasks regardless of age
     * @return array<string, mixed> Returns array with 'archived' count and 'archived_tasks' array
     */
    public function archiveTasks(int $days = 30, bool $all = false): array
    {
        return $this->withExclusiveLock(function () use ($days, $all): array {
            $tasks = $this->readTasks();
            $archivePath = $this->getArchivePath();

            // Determine cutoff date if not archiving all
            $cutoffDate = null;
            if (! $all) {
                $cutoffDate = now()->subDays($days);
            }

            // Find tasks to archive (closed tasks that meet age criteria)
            $tasksToArchive = $tasks->filter(function (array $task) use ($cutoffDate, $all): bool {
                if (($task['status'] ?? '') !== 'closed') {
                    return false;
                }

                if ($all) {
                    return true;
                }

                // Check if task is older than cutoff date
                $updatedAt = $task['updated_at'] ?? null;
                if ($updatedAt === null) {
                    return false; // Can't determine age, skip
                }

                try {
                    $taskDate = \Carbon\Carbon::parse($updatedAt);

                    return $taskDate->lt($cutoffDate);
                } catch (\Exception $e) {
                    return false; // Invalid date, skip
                }
            });

            if ($tasksToArchive->isEmpty()) {
                return ['archived' => 0, 'archived_tasks' => []];
            }

            // Read existing archive
            $archivedTasks = $this->readArchive();

            // Add tasks to archive (merge with existing)
            $archivedTaskIds = $archivedTasks->pluck('id')->toArray();
            foreach ($tasksToArchive as $task) {
                // Only add if not already in archive (avoid duplicates)
                if (! in_array($task['id'], $archivedTaskIds, true)) {
                    $archivedTasks->push($task);
                }
            }

            // Write updated archive
            $this->writeArchive($archivedTasks);

            // Remove archived tasks from main tasks file
            $archivedIds = $tasksToArchive->pluck('id')->toArray();
            $remainingTasks = $tasks->filter(function (array $task) use ($archivedIds): bool {
                return ! in_array($task['id'], $archivedIds, true);
            });

            $this->writeTasks($remainingTasks);

            return [
                'archived' => $tasksToArchive->count(),
                'archived_tasks' => $tasksToArchive->values()->toArray(),
            ];
        });
    }

    /**
     * Read tasks from archive file.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function readArchive(): Collection
    {
        $archivePath = $this->getArchivePath();

        if (! file_exists($archivePath)) {
            return collect();
        }

        $content = file_get_contents($archivePath);
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
                    'Failed to parse archive.jsonl on line '.($lineNumber + 1).': '.json_last_error_msg()
                );
            }

            $tasks->push($task);
        }

        return $tasks;
    }

    /**
     * Write tasks to archive file.
     *
     * @param  Collection<int, array<string, mixed>>  $tasks
     */
    private function writeArchive(Collection $tasks): void
    {
        $archivePath = $this->getArchivePath();
        $dir = dirname($archivePath);

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
        $tempPath = $archivePath.'.tmp';
        file_put_contents($tempPath, $content);
        rename($tempPath, $archivePath);
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

            // Add all blockers of current task to the queue
            $currentTask = $taskMap->get($current);
            if ($currentTask !== null) {
                $blockedBy = $currentTask['blocked_by'] ?? [];
                foreach ($blockedBy as $blockerId) {
                    if (is_string($blockerId) && ! in_array($blockerId, $visited, true)) {
                        $queue[] = $blockerId;
                    }
                }
            }
        }

        return true; // No cycle detected
    }

    /**
     * Delete a task from tasks.jsonl.
     *
     * @return array<string, mixed> The deleted task
     */
    public function delete(string $id): array
    {
        return $this->withExclusiveLock(function () use ($id): array {
            $tasks = $this->readTasks();
            $task = $this->findInCollection($tasks, $id);

            if ($task === null) {
                throw new RuntimeException("Task '{$id}' not found");
            }

            $tasks = $tasks->filter(fn (array $t): bool => $t['id'] !== $task['id']);
            $this->writeTasks($tasks);

            return $task;
        });
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

            $blockedBy = $fromTask['blocked_by'] ?? [];
            if (! is_array($blockedBy)) {
                $blockedBy = [];
            }

            // Find and remove the blocker ID
            $resolvedToId = $toTask['id'];
            $found = false;
            $newBlockedBy = [];
            foreach ($blockedBy as $blockerId) {
                if ($blockerId === $resolvedToId) {
                    $found = true;
                } else {
                    $newBlockedBy[] = $blockerId;
                }
            }

            if (! $found) {
                throw new RuntimeException('No dependency exists between these tasks');
            }

            $fromTask['blocked_by'] = $newBlockedBy;
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
        // Support both old 'fuel-' prefix and new 'f-' prefix for backward compatibility
        $matches = $tasks->filter(function (array $task) use ($id): bool {
            $taskId = $task['id'];
            if (! is_string($taskId)) {
                return false;
            }

            return str_starts_with($taskId, $id) ||
                   str_starts_with($taskId, $this->prefix.'-'.$id) ||
                   str_starts_with($taskId, 'fuel-'.$id);
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
