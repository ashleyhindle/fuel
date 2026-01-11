<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Support\Collection;
use RuntimeException;

class TaskService
{
    private const VALID_TYPES = ['bug', 'fix', 'feature', 'task', 'epic', 'chore', 'docs', 'test', 'refactor'];

    private const VALID_COMPLEXITIES = ['trivial', 'simple', 'moderate', 'complex'];

    private string $prefix = 'f';

    public function __construct(private readonly DatabaseService $db) {}

    /**
     * Load all tasks from SQLite.
     *
     * @return Collection<int, Task>
     */
    public function all(): Collection
    {
        $rows = $this->db->fetchAll('SELECT * FROM tasks ORDER BY short_id');

        return collect($rows)->map(fn (array $row): Task => Task::fromArray($this->rowToTask($row)));
    }

    /**
     * Get all backlog items (tasks with status=someday).
     *
     * @return Collection<int, Task>
     */
    public function backlog(): Collection
    {
        $rows = $this->db->fetchAll('SELECT * FROM tasks WHERE status = ? ORDER BY created_at', [TaskStatus::Someday->value]);

        return collect($rows)->map(fn (array $row): Task => Task::fromArray($this->rowToTask($row)));
    }

    /**
     * Find a task by ID (supports partial ID matching).
     */
    public function find(string $id): ?Task
    {
        // Try exact match first
        $row = $this->db->fetchOne('SELECT * FROM tasks WHERE short_id = ?', [$id]);
        if ($row !== null) {
            return Task::fromArray($this->rowToTask($row));
        }

        // Try partial match (prefix matching)
        // Support both old 'fuel-' prefix and new 'f-' prefix for backward compatibility
        $rows = $this->db->fetchAll(
            'SELECT * FROM tasks WHERE short_id LIKE ? OR short_id LIKE ? OR short_id LIKE ?',
            [$id.'%', $this->prefix.'-'.$id.'%', 'fuel-'.$id.'%']
        );

        if (count($rows) === 1) {
            return Task::fromArray($this->rowToTask($rows[0]));
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
            $valueStr = is_object($value) ? get_class($value) : (string) $value;
            throw new RuntimeException(
                sprintf("Invalid %s '%s'. Must be one of: ", $fieldName, $valueStr).implode(', ', $validValues)
            );
        }
    }

    /**
     * Create a new task.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Task
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

        // Validate complexity enum
        $complexity = $data['complexity'] ?? 'simple';
        $this->validateEnum($complexity, self::VALID_COMPLEXITIES, 'task complexity');

        // Validate status enum if provided, otherwise default to Open
        $status = $data['status'] ?? TaskStatus::Open->value;
        $this->validateEnum($status, array_column(TaskStatus::cases(), 'value'), 'status');

        $shortId = $this->generateId();
        $now = now()->toIso8601String();

        // Look up epic integer ID if provided
        $epicId = null;
        if (isset($data['epic_id']) && $data['epic_id'] !== null) {
            $epicId = $this->resolveEpicId($data['epic_id']);
        }

        $blockedBy = $data['blocked_by'] ?? [];

        $this->db->query(
            'INSERT INTO tasks (short_id, title, description, status, type, priority, complexity, labels, blocked_by, epic_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $shortId,
                $data['title'] ?? throw new RuntimeException('Task title is required'),
                $data['description'] ?? null,
                $status,
                $type,
                $priority,
                $complexity,
                json_encode($labels),
                json_encode($blockedBy),
                $epicId,
                $now,
                $now,
            ]
        );

        return Task::fromArray([
            'id' => $shortId,
            'title' => $data['title'],
            'status' => $status,
            'description' => $data['description'] ?? null,
            'type' => $type,
            'priority' => $priority,
            'labels' => $labels,
            'complexity' => $complexity,
            'blocked_by' => $blockedBy,
            'epic_id' => $data['epic_id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Update a task with new data.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): Task
    {
        $taskModel = $this->find($id);
        if (! $taskModel instanceof Task) {
            throw new RuntimeException(sprintf("Task '%s' not found", $id));
        }

        $task = $taskModel->toArray();
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
            $this->validateEnum($data['status'], array_column(TaskStatus::cases(), 'value'), 'status');
            $updates[] = 'status = ?';
            $params[] = $data['status'];
            $task['status'] = $data['status'];
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

        $arbitraryFields = ['commit_hash', 'reason', 'consumed', 'consumed_at', 'consumed_exit_code', 'consumed_output', 'consume_pid', 'last_review_issues'];

        foreach ($data as $key => $value) {
            if (in_array($key, $arbitraryFields, true)) {
                $updates[] = $key.' = ?';
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

        $params[] = $shortId;
        $this->db->query(
            'UPDATE tasks SET '.implode(', ', $updates).' WHERE short_id = ?',
            $params
        );

        return Task::fromArray($task);
    }

    /**
     * Mark a task as in progress (claim it).
     */
    public function start(string $id): Task
    {
        return $this->update($id, ['status' => TaskStatus::InProgress->value]);
    }

    /**
     * Promote a backlog item to an active task (someday -> open).
     */
    public function promote(string $id): Task
    {
        $taskModel = $this->find($id);
        if (! $taskModel instanceof Task) {
            throw new RuntimeException(sprintf("Task '%s' not found", $id));
        }

        $task = $taskModel->toArray();
        if (($task['status'] ?? '') !== TaskStatus::Someday->value) {
            throw new RuntimeException(sprintf("Task '%s' is not a backlog item (status is not 'someday')", $id));
        }

        return $this->update($id, ['status' => TaskStatus::Open->value]);
    }

    /**
     * Defer a task to backlog (any status -> someday).
     */
    public function defer(string $id): Task
    {
        return $this->update($id, ['status' => TaskStatus::Someday->value]);
    }

    /**
     * Mark a task as done (closed).
     *
     * @param  string|null  $reason  Optional reason for completion
     * @param  string|null  $commitHash  Optional git commit hash
     */
    public function done(string $id, ?string $reason = null, ?string $commitHash = null): Task
    {
        $data = ['status' => TaskStatus::Closed->value];
        if ($reason !== null) {
            $data['reason'] = $reason;
        }

        if ($commitHash !== null) {
            $data['commit_hash'] = $commitHash;
        }

        // Clear any previous review issues when task is completed
        $data['last_review_issues'] = null;

        return $this->update($id, $data);
    }

    /**
     * Set or clear the last review issues on a task.
     *
     * @param  array<string>|null  $issues  Array of issue strings, or null to clear
     */
    public function setLastReviewIssues(string $id, ?array $issues): Task
    {
        $encodedIssues = $issues !== null ? json_encode($issues) : null;

        $taskModel = $this->update($id, ['last_review_issues' => $encodedIssues]);
        $task = $taskModel->toArray();

        // Ensure the returned task has the decoded array, not the JSON string
        if ($issues !== null) {
            $task['last_review_issues'] = $issues;
        } else {
            unset($task['last_review_issues']);
        }

        return Task::fromArray($task);
    }

    /**
     * Reopen a closed or in_progress task (set status back to open).
     */
    public function reopen(string $id): Task
    {
        $taskModel = $this->find($id);
        if (! $taskModel instanceof Task) {
            throw new RuntimeException(sprintf("Task '%s' not found", $id));
        }

        $task = $taskModel->toArray();
        $status = $task['status'] ?? '';
        if (! in_array($status, [TaskStatus::Closed->value, TaskStatus::InProgress->value, TaskStatus::Review->value], true)) {
            throw new RuntimeException(sprintf("Task '%s' is not closed, in_progress, or review. Only these statuses can be reopened.", $id));
        }

        $shortId = $task['id'];
        $now = now()->toIso8601String();

        $this->db->query(
            'UPDATE tasks SET status = ?, reason = NULL, consumed = NULL, consumed_at = NULL, consumed_exit_code = NULL, consumed_output = NULL, updated_at = ? WHERE short_id = ?',
            [TaskStatus::Open->value, $now, $shortId]
        );

        $task['status'] = TaskStatus::Open->value;
        $task['updated_at'] = $now;
        unset($task['reason'], $task['consumed'], $task['consumed_at'], $task['consumed_exit_code'], $task['consumed_output']);

        return Task::fromArray($task);
    }

    /**
     * Retry a failed task by moving it back to open status.
     */
    public function retry(string $id): Task
    {
        $taskModel = $this->find($id);
        if (! $taskModel instanceof Task) {
            throw new RuntimeException(sprintf("Task '%s' not found", $id));
        }

        $task = $taskModel->toArray();
        $consumed = ! empty($task['consumed']);
        $status = $task['status'] ?? '';

        if (! ($consumed && $status === TaskStatus::InProgress->value)) {
            throw new RuntimeException(sprintf("Task '%s' is not a consumed in_progress task. Use 'reopen' for closed tasks.", $id));
        }

        $shortId = $task['id'];
        $now = now()->toIso8601String();

        $this->db->query(
            'UPDATE tasks SET status = ?, reason = NULL, consumed = NULL, consumed_at = NULL, consumed_exit_code = NULL, consumed_output = NULL, consume_pid = NULL, updated_at = ? WHERE short_id = ?',
            [TaskStatus::Open->value, $now, $shortId]
        );

        $task['status'] = TaskStatus::Open->value;
        $task['updated_at'] = $now;
        unset($task['reason'], $task['consumed'], $task['consumed_at'], $task['consumed_exit_code'], $task['consumed_output'], $task['consume_pid']);

        return Task::fromArray($task);
    }

    /**
     * Get tasks that are ready (open with no open blockers).
     *
     * @return Collection<int, Task>
     */
    public function ready(): Collection
    {
        return $this->readyFrom($this->all());
    }

    /**
     * Get tasks that are blocked (open with unresolved dependencies).
     *
     * @return Collection<int, Task>
     */
    public function blocked(): Collection
    {
        return $this->blockedFrom($this->all());
    }

    /**
     * Compute ready tasks from a given collection (for snapshot-based operations).
     *
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    public function readyFrom(Collection $tasks): Collection
    {
        $blockedIds = $this->getBlockedIds($tasks);

        return $tasks
            ->filter(fn (Task $t): bool => ($t->status ?? '') === TaskStatus::Open->value)
            ->filter(fn (Task $t): bool => ! in_array($t->id, $blockedIds, true))
            ->filter(function (Task $t): bool {
                $labels = $t->labels ?? [];
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
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    public function blockedFrom(Collection $tasks): Collection
    {
        $blockedIds = $this->getBlockedIds($tasks);

        return $tasks
            ->filter(fn (Task $t): bool => ($t->status ?? '') === TaskStatus::Open->value)
            ->filter(fn (Task $t): bool => in_array($t->id, $blockedIds, true))
            ->sortBy([
                ['priority', 'asc'],
                ['created_at', 'asc'],
            ])
            ->values();
    }

    /**
     * Get IDs of tasks that are blocked by open dependencies.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<string>
     */
    public function getBlockedIds(Collection $tasks): array
    {
        $taskMap = $tasks->keyBy('id');
        $blockedIds = [];

        foreach ($tasks as $task) {
            $blockedBy = $task->blocked_by ?? [];
            foreach ($blockedBy as $blockerId) {
                if (is_string($blockerId)) {
                    $blocker = $taskMap->get($blockerId);
                    if ($blocker !== null && ($blocker->status ?? '') !== TaskStatus::Closed->value) {
                        $blockedIds[] = $task->id;
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
     * @param  array<int>  $excludePids  PIDs to exclude
     */
    public function isFailed(Task $task, array $excludePids = []): bool
    {
        $consumed = ! empty($task->consumed);
        $exitCode = $task->consumed_exit_code ?? null;
        $status = $task->status ?? '';
        $pid = $task->consume_pid ?? null;

        // Case 1: Explicit failure (consumed with non-zero exit code)
        if ($consumed && $exitCode !== null && $exitCode !== 0) {
            return true;
        }

        // Case 2: in_progress + consumed + null PID (spawn failed or PID lost)
        if ($status === TaskStatus::InProgress->value && $consumed && $pid === null) {
            return true;
        }

        // Case 3: in_progress + PID that is dead
        if ($status === TaskStatus::InProgress->value && $pid !== null) {
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
     * @return Collection<int, Task>
     */
    public function failed(array $excludePids = []): Collection
    {
        return $this->all()
            ->filter(fn (Task $task): bool => $this->isFailed($task, $excludePids))
            ->values();
    }

    /**
     * Add a dependency to a task.
     */
    public function addDependency(string $taskId, string $dependsOnId): Task
    {
        $tasks = $this->all();

        $taskModel = $this->findInCollection($tasks, $taskId);
        if (! $taskModel instanceof Task) {
            throw new RuntimeException(sprintf("Task '%s' not found", $taskId));
        }

        $dependsOnTask = $this->findInCollection($tasks, $dependsOnId);
        if (! $dependsOnTask instanceof Task) {
            throw new RuntimeException(sprintf("Dependency target '%s' not found", $dependsOnId));
        }

        $resolvedTaskId = $taskModel->id;
        $resolvedDependsOnId = $dependsOnTask->id;

        if ($resolvedTaskId === $resolvedDependsOnId) {
            throw new RuntimeException('A task cannot depend on itself');
        }

        if (! $this->validateNoCycles($tasks, $resolvedTaskId, $resolvedDependsOnId)) {
            throw new RuntimeException('Circular dependency detected! Adding this dependency would create a cycle.');
        }

        $blockedBy = $taskModel->blocked_by ?? [];
        if (! is_array($blockedBy)) {
            $blockedBy = [];
        }

        if (in_array($resolvedDependsOnId, $blockedBy, true)) {
            return $taskModel;
        }

        $blockedBy[] = $resolvedDependsOnId;
        $now = now()->toIso8601String();

        $this->db->query(
            'UPDATE tasks SET blocked_by = ?, updated_at = ? WHERE short_id = ?',
            [json_encode($blockedBy), $now, $resolvedTaskId]
        );

        $task = $taskModel->toArray();
        $task['blocked_by'] = $blockedBy;
        $task['updated_at'] = $now;

        return Task::fromArray($task);
    }

    /**
     * Get tasks that block the given task (open blockers).
     *
     * @return Collection<int, Task>
     */
    public function getBlockers(string $taskId): Collection
    {
        $tasks = $this->all();
        $taskMap = $tasks->keyBy('id');

        $task = $this->findInCollection($tasks, $taskId);
        if (! $task instanceof Task) {
            throw new RuntimeException(sprintf("Task '%s' not found", $taskId));
        }

        $blockers = collect();
        $blockedBy = $task->blocked_by ?? [];

        foreach ($blockedBy as $blockerId) {
            if (is_string($blockerId)) {
                $blocker = $taskMap->get($blockerId);
                if ($blocker !== null && ($blocker->status ?? '') !== TaskStatus::Closed->value) {
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

    public function setDatabasePath(string $path): void
    {
        $this->db->setDatabasePath($path);
    }

    /**
     * Delete a task.
     */
    public function delete(string $id): Task
    {
        $task = $this->find($id);
        if (! $task instanceof Task) {
            throw new RuntimeException(sprintf("Task '%s' not found", $id));
        }

        $this->db->query('DELETE FROM tasks WHERE short_id = ?', [$task->id]);

        return $task;
    }

    /**
     * Remove a dependency between tasks.
     */
    public function removeDependency(string $fromId, string $toId): Task
    {
        $tasks = $this->all();

        $fromTaskModel = $this->findInCollection($tasks, $fromId);
        if (! $fromTaskModel instanceof Task) {
            throw new RuntimeException(sprintf("Task '%s' not found", $fromId));
        }

        $toTask = $this->findInCollection($tasks, $toId);
        if (! $toTask instanceof Task) {
            throw new RuntimeException(sprintf("Task '%s' not found", $toId));
        }

        $blockedBy = $fromTaskModel->blocked_by ?? [];
        if (! is_array($blockedBy)) {
            $blockedBy = [];
        }

        $resolvedToId = $toTask->id;
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
            [json_encode($newBlockedBy), $now, $fromTaskModel->id]
        );

        $fromTask = $fromTaskModel->toArray();
        $fromTask['blocked_by'] = $newBlockedBy;
        $fromTask['updated_at'] = $now;

        return Task::fromArray($fromTask);
    }

    /**
     * Validate that adding a dependency won't create a cycle.
     *
     * @param  Collection<int, Task>  $tasks
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
                $blockedBy = $currentTask->blocked_by ?? [];
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
     * @param  Collection<int, Task>  $tasks
     */
    private function findInCollection(Collection $tasks, string $id): ?Task
    {
        $task = $tasks->firstWhere('id', $id);
        if ($task !== null) {
            return $task;
        }

        $matches = $tasks->filter(function (Task $task) use ($id): bool {
            $taskId = $task->id;
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

        if (isset($row['last_review_issues']) && $row['last_review_issues'] !== null) {
            $task['last_review_issues'] = json_decode((string) $row['last_review_issues'], true);
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
