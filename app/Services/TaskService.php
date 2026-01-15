<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use Illuminate\Support\Collection;
use RuntimeException;

class TaskService
{
    private const VALID_TYPES = ['bug', 'fix', 'feature', 'task', 'epic', 'chore', 'docs', 'test', 'refactor', 'reality'];

    private const VALID_COMPLEXITIES = ['trivial', 'simple', 'moderate', 'complex'];

    private const COMPLETION_SOUND = '/System/Library/Sounds/Glass.aiff';

    private string $prefix = 'f';

    /**
     * Load all tasks from SQLite.
     *
     * @return Collection<int, Task>
     */
    public function all(): Collection
    {
        return Task::with('epic')->orderBy('short_id')->get();
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
        return Task::findByPartialId($id);
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
     * Resolve an epic short ID (or integer ID) to the integer primary key.
     */
    private function resolveEpicId(mixed $epicId): ?int
    {
        if ($epicId === null) {
            return null;
        }

        if (is_int($epicId)) {
            return $epicId;
        }

        $epic = Epic::findByPartialId((string) $epicId);

        return $epic?->getKey();
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
        if (array_key_exists('epic_id', $data) && $data['epic_id'] !== null) {
            $epicId = $this->resolveEpicId($data['epic_id']);
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
            'agent' => $data['agent'] ?? null,
            'selfguided_iteration' => $data['selfguided_iteration'] ?? 0,
            'selfguided_stuck_count' => $data['selfguided_stuck_count'] ?? 0,
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
            }
        }

        // Handle priority with custom validation
        if (array_key_exists('priority', $data)) {
            $priority = $data['priority'];
            if (! is_int($priority) || $priority < 0 || $priority > 4) {
                throw new RuntimeException(
                    sprintf("Invalid priority '%s'. Must be an integer between 0 and 4.", $priority)
                );
            }

            $updates['priority'] = $priority;
        }

        // Handle epic_id with custom logic
        if (array_key_exists('epic_id', $data)) {
            $epicId = null;
            if ($data['epic_id'] !== null) {
                $epicId = $this->resolveEpicId($data['epic_id']);
            }

            $updates['epic_id'] = $epicId;
        }

        // Handle labels updates with custom logic
        if (isset($data['add_labels']) || isset($data['remove_labels'])) {
            $labels = $taskModel->labels ?? [];
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
        }

        // Handle arbitrary fields
        $arbitraryFields = ['commit_hash', 'reason', 'consumed', 'consumed_at', 'consumed_output', 'last_review_issues', 'agent', 'selfguided_iteration', 'selfguided_stuck_count'];
        foreach ($data as $key => $value) {
            if (in_array($key, $arbitraryFields, true)) {
                $updates[$key] = $value;
            }
        }

        // Update updated_at only if not explicitly provided
        if (! array_key_exists('updated_at', $data)) {
            $now = now()->toIso8601String();
            $updates['updated_at'] = $now;
        } else {
            $updates['updated_at'] = $data['updated_at'];
        }

        $shouldNotifyCompletion = $this->shouldPlayCompletionSound($taskModel, $updates);

        $taskModel->update($updates);

        if ($shouldNotifyCompletion) {
            $this->notifyTaskCompletion($taskModel);
        }

        return $taskModel->fresh();
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

        if ($taskModel->status !== TaskStatus::Someday) {
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
     * Mark a task as done.
     *
     * @param  string|null  $reason  Optional reason for completion
     * @param  string|null  $commitHash  Optional git commit hash
     */
    public function done(string $id, ?string $reason = null, ?string $commitHash = null): Task
    {
        $data = ['status' => TaskStatus::Done->value];
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
     * Determine whether a task completion should trigger a sound.
     *
     * @param  array<string, mixed>  $updates
     */
    private function shouldPlayCompletionSound(Task $taskModel, array $updates): bool
    {
        if (! array_key_exists('status', $updates)) {
            return false;
        }

        if ($updates['status'] !== TaskStatus::Done->value) {
            return false;
        }

        return $taskModel->status !== TaskStatus::Done->value;
    }

    /**
     * Play a completion sound and send desktop notification for a newly completed task.
     */
    private function notifyTaskCompletion(Task $task): void
    {
        if (function_exists('app') && app()->environment('testing')) {
            return;
        }

        // Build a short useful notification message
        $title = $task->title;
        // Truncate if too long for notification
        if (mb_strlen($title) > 50) {
            $title = mb_substr($title, 0, 47).'...';
        }
        $message = sprintf('✓ %s (%s)', $title, $task->short_id);

        $notificationService = app(NotificationService::class);
        $notificationService->playSound(self::COMPLETION_SOUND);
        $notificationService->desktopNotify($message, 'Fuel: Task Complete');
    }

    /**
     * Set or clear the last review issues on a task.
     *
     * @param  array<string>|null  $issues  Array of issue strings, or null to clear
     */
    public function setLastReviewIssues(string $id, ?array $issues): Task
    {
        return $this->update($id, ['last_review_issues' => $issues]);
    }

    /**
     * Reopen a done or in_progress task (set status back to open).
     */
    public function reopen(string $id): Task
    {
        $taskModel = $this->find($id);
        if (! $taskModel instanceof Task) {
            throw new RuntimeException(sprintf("Task '%s' not found", $id));
        }

        $status = $taskModel->status;
        if (! in_array($status, [TaskStatus::Done, TaskStatus::InProgress, TaskStatus::Review], true)) {
            throw new RuntimeException(sprintf("Task '%s' is not done, in_progress, or review. Only these statuses can be reopened.", $id));
        }

        return $this->update($id, [
            'status' => TaskStatus::Open->value,
            'reason' => null,
            'consumed' => 0,
            'consumed_at' => null,
            'consumed_output' => null,
        ]);
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

        $consumed = ! empty($taskModel->consumed);
        $status = $taskModel->status;

        if (! ($consumed && $status === TaskStatus::InProgress)) {
            throw new RuntimeException(sprintf("Task '%s' is not a consumed in_progress task. Use 'reopen' for done tasks.", $id));
        }

        return $this->update($id, [
            'status' => TaskStatus::Open->value,
            'reason' => null,
            'consumed' => 0,
            'consumed_at' => null,
            'consumed_output' => null,
        ]);
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
            ->filter(fn (Task $t): bool => $t->status === TaskStatus::Open)
            ->filter(fn (Task $t): bool => $t->type !== 'reality')
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
            ->filter(fn (Task $t): bool => $t->status === TaskStatus::Open)
            ->filter(fn (Task $t): bool => $t->type !== 'reality')
            ->filter(fn (Task $t): bool => in_array($t->short_id, $blockedIds, true))
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
                    if ($blocker !== null && $blocker->status !== TaskStatus::Done) {
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
        $status = $task->status;

        // Get latest run info for PID and exit code checks
        $latestRun = app(RunService::class)->getLatestRun($task->short_id);
        $pid = $latestRun?->pid;

        // Case 1: Explicit failure (consumed with non-zero exit code from latest run)
        if ($consumed) {
            $exitCode = $latestRun?->exit_code;
            if ($exitCode !== null && $exitCode !== 0) {
                return true;
            }
        }

        // Case 2: in_progress + consumed + null PID (spawn failed or PID lost)
        if ($status === TaskStatus::InProgress && $consumed && $pid === null) {
            return true;
        }

        // Case 3: in_progress + PID that is dead
        if ($status === TaskStatus::InProgress && $pid !== null) {
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

        $resolvedTaskId = $taskModel->short_id;
        $resolvedDependsOnId = $dependsOnTask->short_id;

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

        $taskModel->update([
            'blocked_by' => $blockedBy,
            'updated_at' => $now,
        ]);

        return $taskModel->fresh();
    }

    /**
     * Get tasks that block the given task (open blockers).
     *
     * @return Collection<int, Task>
     */
    public function getBlockers(string $taskId): Collection
    {
        $tasks = $this->all();
        $taskMap = $tasks->keyBy('short_id');

        $task = $this->findInCollection($tasks, $taskId);
        if (! $task instanceof Task) {
            throw new RuntimeException(sprintf("Task '%s' not found", $taskId));
        }

        $blockers = collect();
        $blockedBy = $task->blocked_by ?? [];

        foreach ($blockedBy as $blockerId) {
            if (is_string($blockerId)) {
                $blocker = $taskMap->get($blockerId);
                if ($blocker !== null && $blocker->status !== TaskStatus::Done) {
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

        $existingIds = Task::pluck('short_id')->all();

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
     * Delete a task (soft delete - sets status to cancelled).
     */
    public function delete(string $id): Task
    {
        $task = $this->find($id);
        if (! $task instanceof Task) {
            throw new RuntimeException(sprintf("Task '%s' not found", $id));
        }

        $task->status = TaskStatus::Cancelled;
        $task->save();

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

        $resolvedToId = $toTask->short_id;
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
        $fromTaskModel->update([
            'blocked_by' => $newBlockedBy,
            'updated_at' => $now,
        ]);

        return $fromTaskModel->fresh();
    }

    /**
     * Validate that adding a dependency won't create a cycle.
     *
     * @param  Collection<int, Task>  $tasks
     */
    private function validateNoCycles(Collection $tasks, string $taskId, string $dependsOnId): bool
    {
        $taskMap = $tasks->keyBy('short_id');
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
        $task = $tasks->firstWhere('short_id', $id);
        if ($task !== null) {
            return $task;
        }

        $matches = $tasks->filter(function (Task $task) use ($id): bool {
            $taskId = $task->short_id;
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
                sprintf("Ambiguous task ID '%s'. Matches: ", $id).$matches->pluck('short_id')->implode(', ')
            );
        }

        return null;
    }
}
