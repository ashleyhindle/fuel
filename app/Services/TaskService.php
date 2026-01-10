<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class TaskService
{
    private const VALID_TYPES = ['bug', 'fix', 'feature', 'task', 'epic', 'chore', 'docs', 'test', 'refactor'];

    private const VALID_SIZES = ['xs', 's', 'm', 'l', 'xl'];

    private const VALID_COMPLEXITIES = ['trivial', 'simple', 'moderate', 'complex'];

    private const VALID_STATUSES = ['open', 'in_progress', 'review', 'closed', 'cancelled'];

    private string $prefix = 'f';

    private DatabaseService $db;

    public function __construct(DatabaseService $db)
    {
        $this->db = $db;
    }

    /**
     * Load all tasks from SQLite.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function all(): Collection
    {
        $rows = $this->db->fetchAll('SELECT * FROM tasks ORDER BY short_id');

        return collect($rows)->map(fn (array $row): array => $this->rowToTask($row));
    }

    /**
     * Find a task by ID (supports partial ID matching).
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        // Try exact match first
        $row = $this->db->fetchOne('SELECT * FROM tasks WHERE short_id = ?', [$id]);
        if ($row !== null) {
            return $this->rowToTask($row);
        }

        // Try partial match (prefix matching)
        // Support both old 'fuel-' prefix and new 'f-' prefix for backward compatibility
        $rows = $this->db->fetchAll(
            'SELECT * FROM tasks WHERE short_id LIKE ? OR short_id LIKE ? OR short_id LIKE ?',
            [$id.'%', $this->prefix.'-'.$id.'%', 'fuel-'.$id.'%']
        );

        if (count($rows) === 1) {
            return $this->rowToTask($rows[0]);
        }

        if (count($rows) > 1) {
            $ids = array_column($rows, 'short_id');
            throw new RuntimeException(
                sprintf("Ambiguous task ID '%s'. Matches: ", $id).implode(', ', $ids)
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
                sprintf("Invalid %s '%s'. Must be one of: ", $fieldName, $value).implode(', ', $validValues)
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
        // Validate type enum
        $type = $data['type'] ?? 'task';
        $this->validateEnum($type, self::VALID_TYPES, 'task type');

        // Validate priority range
        $priority = $data['priority'] ?? 2;
        if (! is_int($priority) || $priority < 0 || $priority > 4) {
            throw new RuntimeException(
                sprintf("Invalid priority '%s'. Must be an integer between 0 and 4.", $priority)
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

        $shortId = $this->generateId();
        $now = now()->toIso8601String();

        // Look up epic integer ID if provided
        $epicId = null;
        if (isset($data['epic_id']) && $data['epic_id'] !== null) {
            $epicId = $this->resolveEpicId($data['epic_id']);
        }

        $blockedBy = $data['blocked_by'] ?? [];

        $this->db->query(
            'INSERT INTO tasks (short_id, title, description, status, type, priority, size, complexity, labels, blocked_by, epic_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $shortId,
                $data['title'] ?? throw new RuntimeException('Task title is required'),
                $data['description'] ?? null,
                'open',
                $type,
                $priority,
                $size,
                $complexity,
                json_encode($labels),
                json_encode($blockedBy),
                $epicId,
                $now,
                $now,
            ]
        );

        return [
            'id' => $shortId,
            'title' => $data['title'],
            'status' => 'open',
            'description' => $data['description'] ?? null,
            'type' => $type,
            'priority' => $priority,
            'labels' => $labels,
            'size' => $size,
            'complexity' => $complexity,
            'blocked_by' => $blockedBy,
            'epic_id' => $data['epic_id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Update a task with new data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $task = $this->find($id);
        if ($task === null) {
            throw new RuntimeException(sprintf("Task '%s' not found", $id));
        }

        $shortId = $task['id'];
        $updates = [];
        $params = [];

        // Update title if provided
        if (isset($data['title'])) {
            $updates[] = 'title = ?';
            $params[] = $data['title'];
            $task['title'] = $data['title'];
        }

        // Update description if provided
        if (array_key_exists('description', $data)) {
            $updates[] = 'description = ?';
            $params[] = $data['description'];
            $task['description'] = $data['description'];
        }

        // Update type if provided (with validation)
        if (isset($data['type'])) {
            $this->validateEnum($data['type'], self::VALID_TYPES, 'task type');
            $updates[] = 'type = ?';
            $params[] = $data['type'];
            $task['type'] = $data['type'];
        }

        // Update priority if provided (with validation)
        if (isset($data['priority'])) {
            $priority = $data['priority'];
            if (! is_int($priority) || $priority < 0 || $priority > 4) {
                throw new RuntimeException(
                    sprintf("Invalid priority '%s'. Must be an integer between 0 and 4.", $priority)
                );
            }
            $updates[] = 'priority = ?';
            $params[] = $priority;
            $task['priority'] = $priority;
        }

        // Update status if provided (with validation)
        if (isset($data['status'])) {
            $this->validateEnum($data['status'], self::VALID_STATUSES, 'status');
            $updates[] = 'status = ?';
            $params[] = $data['status'];
            $task['status'] = $data['status'];
        }

        // Update size if provided (with validation)
        if (isset($data['size'])) {
            $this->validateEnum($data['size'], self::VALID_SIZES, 'task size');
            $updates[] = 'size = ?';
            $params[] = $data['size'];
            $task['size'] = $data['size'];
        }

        // Update complexity if provided (with validation)
        if (isset($data['complexity'])) {
            $this->validateEnum($data['complexity'], self::VALID_COMPLEXITIES, 'task complexity');
            $updates[] = 'complexity = ?';
            $params[] = $data['complexity'];
            $task['complexity'] = $data['complexity'];
        }

        // Update epic_id if provided
        if (array_key_exists('epic_id', $data)) {
            $epicId = null;
            if ($data['epic_id'] !== null) {
                $epicId = $this->resolveEpicId($data['epic_id']);
            }
            $updates[] = 'epic_id = ?';
            $params[] = $epicId;
            $task['epic_id'] = $data['epic_id'];
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

            $updates[] = 'labels = ?';
            $params[] = json_encode($labels);
            $task['labels'] = $labels;
        }

        // Handle arbitrary fields (e.g., consumed, consumed_at, etc.)
        $handledFields = ['title', 'description', 'type', 'priority', 'status', 'size', 'complexity', 'epic_id', 'add_labels', 'remove_labels'];
        $arbitraryFields = ['commit_hash', 'reason', 'consumed', 'consumed_at', 'consumed_exit_code', 'consumed_output', 'consume_pid'];

        foreach ($data as $key => $value) {
            if (in_array($key, $arbitraryFields, true)) {
                $updates[] = "{$key} = ?";
                $params[] = $value;
                $task[$key] = $value;
            }
        }

        // Update updated_at only if not explicitly provided
        if (! isset($data['updated_at'])) {
            $now = now()->toIso8601String();
            $updates[] = 'updated_at = ?';
            $params[] = $now;
            $task['updated_at'] = $now;
        } else {
            $updates[] = 'updated_at = ?';
            $params[] = $data['updated_at'];
            $task['updated_at'] = $data['updated_at'];
        }

        if ($updates !== []) {
            $params[] = $shortId;
            $this->db->query(
                'UPDATE tasks SET '.implode(', ', $updates).' WHERE short_id = ?',
                $params
            );
        }

        return $task;
    }

    /**
     * Mark a task as in progress (claim it).
     *
     * @return array<string, mixed>
     */
    public function start(string $id): array
    {
        return $this->update($id, ['status' => 'in_progress']);
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
        $data = ['status' => 'closed'];
        if ($reason !== null) {
            $data['reason'] = $reason;
        }
        if ($commitHash !== null) {
            $data['commit_hash'] = $commitHash;
        }

        return $this->update($id, $data);
    }

    /**
     * Reopen a closed or in_progress task (set status back to open).
     *
     * @return array<string, mixed>
     */
    public function reopen(string $id): array
    {
        $task = $this->find($id);
        if ($task === null) {
            throw new RuntimeException(sprintf("Task '%s' not found", $id));
        }

        $status = $task['status'] ?? '';
        if ($status !== 'closed' && $status !== 'in_progress') {
            throw new RuntimeException(sprintf("Task '%s' is not closed or in_progress. Only closed or in_progress tasks can be reopened.", $id));
        }

        $shortId = $task['id'];
        $now = now()->toIso8601String();

        $this->db->query(
            'UPDATE tasks SET status = ?, reason = NULL, consumed = NULL, consumed_at = NULL, consumed_exit_code = NULL, consumed_output = NULL, updated_at = ? WHERE short_id = ?',
            ['open', $now, $shortId]
        );

        $task['status'] = 'open';
        $task['updated_at'] = $now;
        unset($task['reason'], $task['consumed'], $task['consumed_at'], $task['consumed_exit_code'], $task['consumed_output']);

        return $task;
    }

    /**
     * Retry a failed task by moving it back to open status.
     *
     * @return array<string, mixed>
     */
    public function retry(string $id): array
    {
        $task = $this->find($id);
        if ($task === null) {
            throw new RuntimeException(sprintf("Task '%s' not found", $id));
        }

        $consumed = ! empty($task['consumed']);
        $status = $task['status'] ?? '';

        if (! ($consumed && $status === 'in_progress')) {
            throw new RuntimeException(sprintf("Task '%s' is not a consumed in_progress task. Use 'reopen' for closed tasks.", $id));
        }

        $shortId = $task['id'];
        $now = now()->toIso8601String();

        $this->db->query(
            'UPDATE tasks SET status = ?, reason = NULL, consumed = NULL, consumed_at = NULL, consumed_exit_code = NULL, consumed_output = NULL, consume_pid = NULL, updated_at = ? WHERE short_id = ?',
            ['open', $now, $shortId]
        );

        $task['status'] = 'open';
        $task['updated_at'] = $now;
        unset($task['reason'], $task['consumed'], $task['consumed_at'], $task['consumed_exit_code'], $task['consumed_output'], $task['consume_pid']);

        return $task;
    }

    /**
     * Get tasks that are ready (open with no open blockers).
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
     * @param  array<string, mixed>  $task
     * @param  array<int>  $excludePids  PIDs to exclude
     */
    public function isFailed(array $task, array $excludePids = []): bool
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

        // Case 3: in_progress + PID that is dead
        if ($status === 'in_progress' && $pid !== null) {
            $pidInt = (int) $pid;
            if (in_array($pidInt, $excludePids, true)) {
                return false;
            }

            return ! ProcessManager::isProcessAlive($pidInt);
        }

        return false;
    }

    /**
     * Find failed/stuck tasks that need retry.
     *
     * @param  array<int>  $excludePids  PIDs to exclude
     * @return Collection<int, array<string, mixed>>
     */
    public function failed(array $excludePids = []): Collection
    {
        return $this->all()
            ->filter(fn (array $task): bool => $this->isFailed($task, $excludePids))
            ->values();
    }

    /**
     * Add a dependency to a task.
     *
     * @return array<string, mixed>
     */
    public function addDependency(string $taskId, string $dependsOnId): array
    {
        $tasks = $this->all();

        $task = $this->findInCollection($tasks, $taskId);
        if ($task === null) {
            throw new RuntimeException(sprintf("Task '%s' not found", $taskId));
        }

        $dependsOnTask = $this->findInCollection($tasks, $dependsOnId);
        if ($dependsOnTask === null) {
            throw new RuntimeException(sprintf("Dependency target '%s' not found", $dependsOnId));
        }

        $resolvedTaskId = $task['id'];
        $resolvedDependsOnId = $dependsOnTask['id'];

        if ($resolvedTaskId === $resolvedDependsOnId) {
            throw new RuntimeException('A task cannot depend on itself');
        }

        if (! $this->validateNoCycles($tasks, $resolvedTaskId, $resolvedDependsOnId)) {
            throw new RuntimeException('Circular dependency detected! Adding this dependency would create a cycle.');
        }

        $blockedBy = $task['blocked_by'] ?? [];
        if (! is_array($blockedBy)) {
            $blockedBy = [];
        }

        if (in_array($resolvedDependsOnId, $blockedBy, true)) {
            return $task;
        }

        $blockedBy[] = $resolvedDependsOnId;
        $now = now()->toIso8601String();

        $this->db->query(
            'UPDATE tasks SET blocked_by = ?, updated_at = ? WHERE short_id = ?',
            [json_encode($blockedBy), $now, $resolvedTaskId]
        );

        $task['blocked_by'] = $blockedBy;
        $task['updated_at'] = $now;

        return $task;
    }

    /**
     * Get tasks that block the given task (open blockers).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getBlockers(string $taskId): Collection
    {
        $tasks = $this->all();
        $taskMap = $tasks->keyBy('id');

        $task = $this->findInCollection($tasks, $taskId);
        if ($task === null) {
            throw new RuntimeException(sprintf("Task '%s' not found", $taskId));
        }

        $blockers = collect();
        $blockedBy = $task['blocked_by'] ?? [];

        foreach ($blockedBy as $blockerId) {
            if (is_string($blockerId)) {
                $blocker = $taskMap->get($blockerId);
                if ($blocker !== null && ($blocker['status'] ?? '') !== 'closed') {
                    $blockers->push($blocker);
                }
            }
        }

        return $blockers->values();
    }

    /**
     * Generate a hash-based ID with collision detection.
     */
    public function generateId(): string
    {
        $length = 6;
        $maxAttempts = 100;

        $existingIds = array_column($this->db->fetchAll('SELECT short_id FROM tasks'), 'short_id');

        $attempts = 0;
        while ($attempts < $maxAttempts) {
            $hash = hash('sha256', uniqid($this->prefix.'-', true).microtime(true));
            $id = $this->prefix.'-'.substr($hash, 0, $length);

            if (! in_array($id, $existingIds, true)) {
                return $id;
            }

            $attempts++;
        }

        throw new RuntimeException(
            sprintf('Failed to generate unique task ID after %d attempts.', $maxAttempts)
        );
    }

    /**
     * Initialize the database schema.
     */
    public function initialize(): void
    {
        $this->db->initialize();
    }

    /**
     * Get the archive storage path.
     */
    public function getArchivePath(): string
    {
        return dirname($this->db->getPath()).'/archive.jsonl';
    }

    /**
     * Archive closed tasks older than N days (or all closed tasks if $all is true).
     *
     * @param  int  $days  Age threshold in days (ignored if $all is true)
     * @param  bool  $all  Archive all closed tasks regardless of age
     * @return array<string, mixed>
     */
    public function archiveTasks(int $days = 30, bool $all = false): array
    {
        $tasks = $this->all();
        $archivePath = $this->getArchivePath();

        $cutoffDate = null;
        if (! $all) {
            $cutoffDate = now()->subDays($days);
        }

        $tasksToArchive = $tasks->filter(function (array $task) use ($cutoffDate, $all): bool {
            if (($task['status'] ?? '') !== 'closed') {
                return false;
            }

            if ($all) {
                return true;
            }

            $updatedAt = $task['updated_at'] ?? null;
            if ($updatedAt === null) {
                return false;
            }

            try {
                $taskDate = Carbon::parse($updatedAt);

                return $taskDate->lt($cutoffDate);
            } catch (\Exception) {
                return false;
            }
        });

        if ($tasksToArchive->isEmpty()) {
            return ['archived' => 0, 'archived_tasks' => []];
        }

        $archivedTasks = $this->readArchive();

        $archivedTaskIds = $archivedTasks->pluck('id')->toArray();
        foreach ($tasksToArchive as $task) {
            if (! in_array($task['id'], $archivedTaskIds, true)) {
                $archivedTasks->push($task);
            }
        }

        $this->writeArchive($archivedTasks);

        // Delete archived tasks from SQLite
        $archivedIds = $tasksToArchive->pluck('id')->toArray();
        if (! empty($archivedIds)) {
            $placeholders = implode(',', array_fill(0, count($archivedIds), '?'));
            $this->db->query("DELETE FROM tasks WHERE short_id IN ({$placeholders})", $archivedIds);
        }

        return [
            'archived' => $tasksToArchive->count(),
            'archived_tasks' => $tasksToArchive->values()->toArray(),
        ];
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

        $sorted = $tasks->sortBy('id')->values();

        $content = $sorted
            ->map(fn (array $task): string => (string) json_encode($task, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->implode("\n");

        if ($content !== '') {
            $content .= "\n";
        }

        $tempPath = $archivePath.'.tmp';
        file_put_contents($tempPath, $content);
        rename($tempPath, $archivePath);
    }

    /**
     * Delete a task.
     *
     * @return array<string, mixed>
     */
    public function delete(string $id): array
    {
        $task = $this->find($id);
        if ($task === null) {
            throw new RuntimeException(sprintf("Task '%s' not found", $id));
        }

        $this->db->query('DELETE FROM tasks WHERE short_id = ?', [$task['id']]);

        return $task;
    }

    /**
     * Remove a dependency between tasks.
     *
     * @return array<string, mixed>
     */
    public function removeDependency(string $fromId, string $toId): array
    {
        $tasks = $this->all();

        $fromTask = $this->findInCollection($tasks, $fromId);
        if ($fromTask === null) {
            throw new RuntimeException(sprintf("Task '%s' not found", $fromId));
        }

        $toTask = $this->findInCollection($tasks, $toId);
        if ($toTask === null) {
            throw new RuntimeException(sprintf("Task '%s' not found", $toId));
        }

        $blockedBy = $fromTask['blocked_by'] ?? [];
        if (! is_array($blockedBy)) {
            $blockedBy = [];
        }

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

        $now = now()->toIso8601String();
        $this->db->query(
            'UPDATE tasks SET blocked_by = ?, updated_at = ? WHERE short_id = ?',
            [json_encode($newBlockedBy), $now, $fromTask['id']]
        );

        $fromTask['blocked_by'] = $newBlockedBy;
        $fromTask['updated_at'] = $now;

        return $fromTask;
    }

    /**
     * Validate that adding a dependency won't create a cycle.
     *
     * @param  Collection<int, array<string, mixed>>  $tasks
     */
    private function validateNoCycles(Collection $tasks, string $taskId, string $dependsOnId): bool
    {
        $taskMap = $tasks->keyBy('id');
        $visited = [];
        $queue = [$dependsOnId];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current === $taskId) {
                return false;
            }

            if (in_array($current, $visited, true)) {
                continue;
            }

            $visited[] = $current;

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

        return true;
    }

    /**
     * Find a task in a collection by ID (supports partial matching).
     *
     * @param  Collection<int, array<string, mixed>>  $tasks
     * @return array<string, mixed>|null
     */
    private function findInCollection(Collection $tasks, string $id): ?array
    {
        $task = $tasks->firstWhere('id', $id);
        if ($task !== null) {
            return $task;
        }

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
                sprintf("Ambiguous task ID '%s'. Matches: ", $id).$matches->pluck('id')->implode(', ')
            );
        }

        return null;
    }

    /**
     * Convert a database row to a task array.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowToTask(array $row): array
    {
        $task = [
            'id' => $row['short_id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'status' => $row['status'],
            'type' => $row['type'],
            'priority' => (int) $row['priority'],
            'size' => $row['size'],
            'complexity' => $row['complexity'],
            'labels' => $row['labels'] !== null ? json_decode($row['labels'], true) : [],
            'blocked_by' => $row['blocked_by'] !== null ? json_decode($row['blocked_by'], true) : [],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];

        // Add epic_id if present (map back to short_id for public interface)
        if (isset($row['epic_id']) && $row['epic_id'] !== null) {
            $epic = $this->db->fetchOne('SELECT short_id FROM epics WHERE id = ?', [$row['epic_id']]);
            $task['epic_id'] = $epic !== null ? $epic['short_id'] : null;
        } else {
            $task['epic_id'] = null;
        }

        // Add optional fields if present
        if (isset($row['commit_hash']) && $row['commit_hash'] !== null) {
            $task['commit_hash'] = $row['commit_hash'];
        }
        if (isset($row['reason']) && $row['reason'] !== null) {
            $task['reason'] = $row['reason'];
        }
        if (isset($row['consumed']) && $row['consumed'] !== null) {
            $task['consumed'] = (bool) $row['consumed'];
        }
        if (isset($row['consumed_at']) && $row['consumed_at'] !== null) {
            $task['consumed_at'] = $row['consumed_at'];
        }
        if (isset($row['consumed_exit_code']) && $row['consumed_exit_code'] !== null) {
            $task['consumed_exit_code'] = (int) $row['consumed_exit_code'];
        }
        if (isset($row['consumed_output']) && $row['consumed_output'] !== null) {
            $task['consumed_output'] = $row['consumed_output'];
        }
        if (isset($row['consume_pid']) && $row['consume_pid'] !== null) {
            $task['consume_pid'] = (int) $row['consume_pid'];
        }

        return $task;
    }

    /**
     * Resolve an epic ID (short_id string) to integer ID.
     */
    private function resolveEpicId(string $epicShortId): ?int
    {
        $epic = $this->db->fetchOne('SELECT id FROM epics WHERE short_id = ?', [$epicShortId]);

        return $epic !== null ? (int) $epic['id'] : null;
    }
}
