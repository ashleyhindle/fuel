<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Repositories\EpicRepository;
use App\Repositories\TaskRepository;
use Illuminate\Support\Collection;
use RuntimeException;

class TaskService
{
    private const VALID_TYPES = ['bug', 'fix', 'feature', 'task', 'epic', 'chore', 'docs', 'test', 'refactor'];

    private const VALID_COMPLEXITIES = ['trivial', 'simple', 'moderate', 'complex'];

    private string $prefix = 'f';

    public function __construct(
        private readonly DatabaseService $db,
        private readonly TaskRepository $taskRepository,
        private readonly EpicRepository $epicRepository
    ) {}

    /**
     * Load all tasks from SQLite.
     *
     * @return Collection<int, Task>
     */
    public function all(): Collection
    {
        return Task::orderBy('short_id')->get();
    }

    /**
     * Get all backlog items (tasks with status=someday).
     *
     * @return Collection<int, Task>
     */
    public function backlog(): Collection
    {
        return Task::backlog()->orderBy('created_at')->get();
    }

    /**
     * Find a task by ID (supports partial ID matching).
     */
    public function find(string $id): ?Task
    {
        // Try exact match first
        $task = Task::where('short_id', $id)->first();
        if ($task !== null) {
            return $task;
        }

        // Try partial match (prefix matching)
        // Support both 'f-' prefix and bare ID
        $rows = Task::where('short_id', 'like', $id.'%')
            ->orWhere('short_id', 'like', $this->prefix.'-'.$id.'%')
            ->orWhere('short_id', 'like', 'fuel-'.$id.'%')
            ->get();

        if ($rows->count() === 1) {
            return $rows->first();
        }

        if ($rows->count() > 1) {
            $ids = $rows->pluck('short_id')->toArray();
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
        // Convert enum to its string value for validation
        $valueToCheck = $value instanceof \BackedEnum ? $value->value : $value;

        if (! in_array($valueToCheck, $validValues, true)) {
            $valueStr = is_object($value) ? $value::class : (string) $value;
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
            $epicId = $this->epicRepository->resolveToIntegerId($data['epic_id']);
        }

        $blockedBy = $data['blocked_by'] ?? [];

        return Task::create([
            'short_id' => $shortId,
            'title' => $data['title'] ?? throw new RuntimeException('Task title is required'),
            'description' => $data['description'] ?? null,
            'status' => $status,
            'type' => $type,
            'priority' => $priority,
            'complexity' => $complexity,
            'labels' => $labels,
            'blocked_by' => $blockedBy,
            'epic_id' => $epicId,
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
        $shortId = $task['short_id'];
        $updates = [];

        // Field mapping: data key => [column, validator, use_array_key_exists]
        $fieldMap = [
            'title' => ['column' => 'title', 'validator' => null, 'use_array_key_exists' => false],
            'description' => ['column' => 'description', 'validator' => null, 'use_array_key_exists' => true],
            'type' => ['column' => 'type', 'validator' => fn ($v) => $this->validateEnum($v, self::VALID_TYPES, 'task type'), 'use_array_key_exists' => false],
            'status' => ['column' => 'status', 'validator' => fn ($v) => $this->validateEnum($v, array_column(TaskStatus::cases(), 'value'), 'status'), 'use_array_key_exists' => false],
            'complexity' => ['column' => 'complexity', 'validator' => fn ($v) => $this->validateEnum($v, self::VALID_COMPLEXITIES, 'task complexity'), 'use_array_key_exists' => false],
        ];

        // Process mapped fields
        foreach ($fieldMap as $key => $config) {
            $checkExists = $config['use_array_key_exists'] ? array_key_exists($key, $data) : isset($data[$key]);
            if ($checkExists) {
                $value = $data[$key];

                // Run validator if provided
                if ($config['validator'] !== null) {
                    ($config['validator'])($value);
                }

                $updates[$config['column']] = $value;
                $task[$key] = $value;
            }
        }

        // Handle priority with custom validation
        if (isset($data['priority'])) {
            $priority = $data['priority'];
            if (! is_int($priority) || $priority < 0 || $priority > 4) {
                throw new RuntimeException(
                    sprintf("Invalid priority '%s'. Must be an integer between 0 and 4.", $priority)
                );
            }

            $updates['priority'] = $priority;
            $task['priority'] = $priority;
        }

        // Handle epic_id with custom logic
        if (array_key_exists('epic_id', $data)) {
            $epicId = null;
            if ($data['epic_id'] !== null) {
                $epicId = $this->epicRepository->resolveToIntegerId($data['epic_id']);
            }

            $updates['epic_id'] = $epicId;
            $task['epic_id'] = $data['epic_id'];
        }

        // Handle labels updates with custom logic
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

            $updates['labels'] = $labels;
            $task['labels'] = $labels;
        }

        // Handle arbitrary fields
        $arbitraryFields = ['commit_hash', 'reason', 'consumed', 'consumed_at', 'consumed_exit_code', 'consumed_output', 'consume_pid', 'last_review_issues'];
        foreach ($data as $key => $value) {
            if (in_array($key, $arbitraryFields, true)) {
                $updates[$key] = $value;
                $task[$key] = $value;
            }
        }

        // Update updated_at only if not explicitly provided
        if (! isset($data['updated_at'])) {
            $now = now()->toIso8601String();
            $updates['updated_at'] = $now;
            $task['updated_at'] = $now;
        } else {
            $updates['updated_at'] = $data['updated_at'];
            $task['updated_at'] = $data['updated_at'];
        }

        $taskModel->update($updates);

        return $taskModel->fresh();
    }

    /**
     * Mark a task as in progress (claim it).
     */
    public function start(string $id): Task
    {
        return $this->update($id, ['status' => TaskStatus::InProgress]);
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

        return $this->update($id, ['status' => TaskStatus::Open]);
    }

    /**
     * Defer a task to backlog (any status -> someday).
     */
    public function defer(string $id): Task
    {
        return $this->update($id, ['status' => TaskStatus::Someday]);
    }

    /**
     * Mark a task as done (closed).
     *
     * @param  string|null  $reason  Optional reason for completion
     * @param  string|null  $commitHash  Optional git commit hash
     */
    public function done(string $id, ?string $reason = null, ?string $commitHash = null): Task
    {
        $data = ['status' => TaskStatus::Closed];
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

        $shortId = $task['short_id'];
        $now = now()->toIso8601String();

        $this->taskRepository->updateByShortId($shortId, [
            'status' => TaskStatus::Open,
            'reason' => null,
            'consumed' => null,
            'consumed_at' => null,
            'consumed_exit_code' => null,
            'consumed_output' => null,
            'updated_at' => $now,
        ]);

        $task['status'] = TaskStatus::Open;
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

        $shortId = $task['short_id'];
        $now = now()->toIso8601String();

        $this->taskRepository->updateByShortId($shortId, [
            'status' => TaskStatus::Open,
            'reason' => null,
            'consumed' => null,
            'consumed_at' => null,
            'consumed_exit_code' => null,
            'consumed_output' => null,
            'consume_pid' => null,
            'updated_at' => $now,
        ]);

        $task['status'] = TaskStatus::Open;
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
            ->filter(fn (Task $t): bool => ! in_array($t->short_id, $blockedIds, true))
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
        $taskMap = $tasks->keyBy('short_id');
        $blockedIds = [];

        foreach ($tasks as $task) {
            $blockedBy = $task->blocked_by ?? [];
            foreach ($blockedBy as $blockerId) {
                if (is_string($blockerId)) {
                    $blocker = $taskMap->get($blockerId);
                    if ($blocker !== null && ($blocker->status ?? '') !== TaskStatus::Closed->value) {
                        $blockedIds[] = $task->short_id;
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

        $this->taskRepository->updateBlockedBy($resolvedTaskId, $blockedBy);

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

        $existingIds = $this->taskRepository->getAllShortIds();

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

        $this->taskRepository->deleteByShortId($task->short_id);

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
        $this->taskRepository->updateBlockedBy($fromTaskModel->id, $newBlockedBy);

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
            $task['epic_id'] = $this->epicRepository->resolveToShortId((int) $row['epic_id']);
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
}
