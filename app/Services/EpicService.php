<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EpicStatus;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Repositories\EpicRepository;
use App\Repositories\TaskRepository;
use Carbon\Carbon;
use RuntimeException;

class EpicService
{
    private readonly TaskService $taskService;

    private readonly EpicRepository $epicRepository;

    public function __construct(
        private readonly DatabaseService $db,
        ?TaskService $taskService = null,
        ?EpicRepository $epicRepository = null
    ) {
        $this->taskService = $taskService ?? new TaskService(
            $this->db,
            new TaskRepository($this->db),
            $epicRepository ?? new EpicRepository($this->db)
        );
        $this->epicRepository = $epicRepository ?? new EpicRepository($this->db);
    }

    public function createEpic(string $title, ?string $description = null): Epic
    {
        $shortId = $this->generateId();
        $now = Carbon::now('UTC')->toIso8601String();

        // Note: status is not stored - it's computed from task states
        $this->epicRepository->insert([
            'short_id' => $shortId,
            'title' => $title,
            'description' => $description,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $epic = [
            'id' => $shortId,
            'title' => $title,
            'description' => $description,
            'created_at' => $now,
            'updated_at' => $now,
            'reviewed_at' => null,
        ];

        // Compute status (will be 'planning' for new epic with no tasks)
        $epic['status'] = $this->getEpicStatus($shortId)->value;

        return Epic::fromArray($epic);
    }

    public function getEpic(string $id): ?Epic
    {
        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            return null;
        }

        $epic = $this->epicRepository->findByShortId($resolvedId);
        if ($epic === null) {
            return null;
        }

        // Map short_id to id for public interface compatibility
        $epic['id'] = $epic['short_id'];
        $epic['status'] = $this->getEpicStatus($resolvedId)->value;

        return Epic::fromArray($epic);
    }

    /**
     * @return array<int, Epic>
     */
    public function getAllEpics(): array
    {
        $epics = $this->epicRepository->all();

        return array_map(function (array $epic): Epic {
            // Map short_id to id for public interface compatibility
            $epic['id'] = $epic['short_id'];
            $epic['status'] = $this->getEpicStatus($epic['short_id'])->value;

            return Epic::fromArray($epic);
        }, $epics);
    }

    /**
     * Get all epics that are pending human review.
     * An epic is review_pending when: has tasks, all tasks closed, and not yet reviewed.
     *
     * @return array<int, Epic>
     */
    public function getEpicsPendingReview(): array
    {
        $epics = $this->epicRepository->findEpicsPendingReview();

        return array_map(function (array $epic): Epic {
            $epic['id'] = $epic['short_id'];
            $epic['status'] = EpicStatus::ReviewPending->value;

            return Epic::fromArray($epic);
        }, $epics);
    }

    public function updateEpic(string $id, array $data): Epic
    {
        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $epic = $this->epicRepository->findByShortId($resolvedId);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $now = Carbon::now('UTC')->toIso8601String();
        $updates = ['updated_at' => $now];

        if (isset($data['title'])) {
            $updates['title'] = $data['title'];
            $epic['title'] = $data['title'];
        }

        if (array_key_exists('description', $data)) {
            $updates['description'] = $data['description'];
            $epic['description'] = $data['description'];
        }

        $this->epicRepository->updateByShortId($resolvedId, $updates);

        // Map short_id to id for public interface compatibility
        $epic['id'] = $epic['short_id'];
        // Compute status from task states
        $epic['status'] = $this->getEpicStatus($resolvedId)->value;

        return Epic::fromArray($epic);
    }

    public function markAsReviewed(string $id): Epic
    {
        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $epic = $this->epicRepository->findByShortId($resolvedId);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $now = Carbon::now('UTC')->toIso8601String();
        $this->epicRepository->updateByShortId($resolvedId, [
            'reviewed_at' => $now,
            'updated_at' => $now,
        ]);

        // Map short_id to id for public interface compatibility
        $epic['id'] = $epic['short_id'];
        $epic['reviewed_at'] = $now;
        $epic['status'] = $this->getEpicStatus($resolvedId)->value;

        return Epic::fromArray($epic);
    }

    /**
     * Approve an epic (mark as approved).
     *
     * @param  string  $id  The epic ID
     * @param  string|null  $approvedBy  Who approved it (optional, defaults to 'human')
     */
    public function approveEpic(string $id, ?string $approvedBy = null): Epic
    {
        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $epic = $this->epicRepository->findByShortId($resolvedId);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $now = Carbon::now('UTC')->toIso8601String();
        $approvedByValue = $approvedBy ?? 'human';

        // Clear changes_requested_at when approving
        $this->epicRepository->updateByShortId($resolvedId, [
            'approved_at' => $now,
            'approved_by' => $approvedByValue,
            'changes_requested_at' => null,
            'updated_at' => $now,
        ]);

        // Map short_id to id for public interface compatibility
        $epic['id'] = $epic['short_id'];
        $epic['approved_at'] = $now;
        $epic['approved_by'] = $approvedByValue;
        $epic['changes_requested_at'] = null;
        $epic['status'] = $this->getEpicStatus($resolvedId)->value;

        return Epic::fromArray($epic);
    }

    /**
     * Reject an epic and request changes (moves back to in_progress).
     *
     * @param  string  $id  The epic ID
     * @param  string|null  $reason  Optional reason for rejection
     */
    public function rejectEpic(string $id, ?string $reason = null): Epic
    {
        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $epic = $this->epicRepository->findByShortId($resolvedId);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $now = Carbon::now('UTC')->toIso8601String();

        // Set changes_requested_at and clear approved_at
        $this->epicRepository->updateByShortId($resolvedId, [
            'changes_requested_at' => $now,
            'approved_at' => null,
            'approved_by' => null,
            'updated_at' => $now,
        ]);

        // Reopen tasks in the epic that were closed (move back to in_progress)
        $tasks = $this->getTasksForEpic($resolvedId);
        foreach ($tasks as $task) {
            if (($task->status ?? '') === TaskStatus::Closed->value) {
                $this->taskService->update($task->id, ['status' => TaskStatus::Open->value]);
            }
        }

        // Map short_id to id for public interface compatibility
        $epic['id'] = $epic['short_id'];
        $epic['changes_requested_at'] = $now;
        $epic['approved_at'] = null;
        $epic['approved_by'] = null;
        $epic['status'] = $this->getEpicStatus($resolvedId)->value;

        return Epic::fromArray($epic);
    }

    public function deleteEpic(string $id): Epic
    {
        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $epic = $this->epicRepository->findByShortId($resolvedId);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        // Map short_id to id for public interface compatibility
        $epic['id'] = $epic['short_id'];
        // Compute status before deleting
        $epic['status'] = $this->getEpicStatus($resolvedId)->value;

        $this->epicRepository->deleteByShortId($resolvedId);

        return Epic::fromArray($epic);
    }

    public function getTasksForEpic(string $epicId): array
    {
        $resolvedId = $this->resolveId($epicId);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $epicId));
        }

        return $this->taskService->all()
            ->filter(fn (Task $task): bool => ($task->epic_id ?? null) === $resolvedId)
            ->values()
            ->all();
    }

    public function getEpicStatus(string $epicId): EpicStatus
    {
        $resolvedId = $this->resolveId($epicId);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $epicId));
        }

        $epic = $this->epicRepository->findByShortId($resolvedId);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $epicId));
        }

        // Check approval/rejection status first (these override computed status)
        if ($epic['approved_at'] !== null) {
            return EpicStatus::Approved;
        }

        if ($epic['changes_requested_at'] !== null) {
            // If changes were requested, check if tasks are back in progress
            $tasks = $this->getTasksForEpic($resolvedId);
            $hasActiveTask = false;
            foreach ($tasks as $task) {
                $status = $task->status ?? '';
                if ($status === TaskStatus::Open->value || $status === TaskStatus::InProgress->value) {
                    $hasActiveTask = true;
                    break;
                }
            }

            // If tasks are active again, it's in_progress; otherwise still changes_requested
            return $hasActiveTask ? EpicStatus::InProgress : EpicStatus::ChangesRequested;
        }

        // If reviewed_at is set but not approved, epic is reviewed (but not approved)
        if ($epic['reviewed_at'] !== null) {
            return EpicStatus::Reviewed;
        }

        $tasks = $this->getTasksForEpic($resolvedId);

        // If no tasks, epic is in planning
        if ($tasks === []) {
            return EpicStatus::Planning;
        }

        // Check if any task is open or in_progress
        $hasActiveTask = false;
        foreach ($tasks as $task) {
            $status = $task->status ?? '';
            if ($status === TaskStatus::Open->value || $status === TaskStatus::InProgress->value) {
                $hasActiveTask = true;
                break;
            }
        }

        if ($hasActiveTask) {
            return EpicStatus::InProgress;
        }

        // Check if all tasks are closed
        $allClosed = true;
        foreach ($tasks as $task) {
            $status = $task->status ?? '';
            if ($status !== TaskStatus::Closed->value) {
                $allClosed = false;
                break;
            }
        }

        if ($allClosed) {
            return EpicStatus::ReviewPending;
        }

        // Fallback: if tasks exist but not all closed and none active, still in_progress
        return EpicStatus::InProgress;
    }

    private function generateId(): string
    {
        return 'e-'.bin2hex(random_bytes(3));
    }

    private function resolveId(string $id): ?string
    {
        $epic = $this->epicRepository->findWithPartialMatch($id);

        return $epic !== null ? $epic['short_id'] : null;
    }

    /**
     * Check if all tasks in an epic are complete.
     *
     * @param  string  $epicId  The epic ID to check
     * @return array{completed: bool} Whether the epic is complete
     */
    public function checkEpicCompletion(string $epicId): array
    {
        $resolvedId = $this->resolveId($epicId);
        if ($resolvedId === null) {
            return ['completed' => false];
        }

        $epic = $this->epicRepository->findByShortId($resolvedId);
        if ($epic === null) {
            return ['completed' => false];
        }

        $tasks = $this->getTasksForEpic($resolvedId);
        if ($tasks === []) {
            return ['completed' => false];
        }

        $allClosed = true;
        foreach ($tasks as $task) {
            $status = $task->status ?? '';
            if ($status !== TaskStatus::Closed->value && $status !== TaskStatus::Cancelled->value) {
                $allClosed = false;
                break;
            }
        }

        if (! $allClosed) {
            return ['completed' => false];
        }

        return ['completed' => true];
    }
}
