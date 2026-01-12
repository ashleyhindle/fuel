<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EpicStatus;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use Carbon\Carbon;
use RuntimeException;

class EpicService
{
    public function __construct(
        private readonly TaskService $taskService
    ) {}

    public function createEpic(string $title, ?string $description = null): Epic
    {
        $shortId = $this->generateId();
        $now = Carbon::now('UTC')->toIso8601String();

        // Note: status is not stored - it's computed from task states
        $epic = Epic::create([
            'short_id' => $shortId,
            'title' => $title,
            'description' => $description,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Map short_id to id and compute status for backward compatibility
        $epicArray = [
            'id' => $shortId,
            'short_id' => $shortId,
            'title' => $title,
            'description' => $description,
            'created_at' => $now,
            'updated_at' => $now,
            'reviewed_at' => null,
        ];

        // Compute status (will be 'planning' for new epic with no tasks)
        $epicArray['status'] = $this->getEpicStatus($shortId)->value;

        return Epic::fromArray($epicArray);
    }

    public function getEpic(string $id): ?Epic
    {
        $epic = Epic::findByPartialId($id);
        if ($epic === null) {
            return null;
        }

        // Convert to array for backward compatibility
        $epicArray = $epic->toArray();
        $epicArray['id'] = $epic->short_id;
        $epicArray['status'] = $this->getEpicStatus($epic->short_id)->value;

        return Epic::fromArray($epicArray);
    }

    /**
     * @return array<int, Epic>
     */
    public function getAllEpics(): array
    {
        $epics = Epic::orderBy('created_at', 'desc')->get();

        return $epics->map(function (Epic $epic): Epic {
            $epicArray = $epic->toArray();
            $epicArray['id'] = $epic->short_id;
            $epicArray['status'] = $this->getEpicStatus($epic->short_id)->value;

            return Epic::fromArray($epicArray);
        })->all();
    }

    /**
     * Get all epics that are pending human review.
     * An epic is review_pending when: has tasks, all tasks closed, and not yet reviewed.
     *
     * @return array<int, Epic>
     */
    public function getEpicsPendingReview(): array
    {
        $epics = Epic::pendingReview();

        return $epics->map(function (Epic $epic): Epic {
            $epicArray = $epic->toArray();
            $epicArray['id'] = $epic->short_id;
            $epicArray['status'] = EpicStatus::ReviewPending->value;

            return Epic::fromArray($epicArray);
        })->all();
    }

    public function updateEpic(string $id, array $data): Epic
    {
        $epic = Epic::findByPartialId($id);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $now = Carbon::now('UTC')->toIso8601String();
        $updates = ['updated_at' => $now];

        if (isset($data['title'])) {
            $updates['title'] = $data['title'];
        }

        if (array_key_exists('description', $data)) {
            $updates['description'] = $data['description'];
        }

        $epic->update($updates);
        $epic->refresh();

        // Convert to array for backward compatibility
        $epicArray = $epic->toArray();
        $epicArray['id'] = $epic->short_id;
        $epicArray['status'] = $this->getEpicStatus($epic->short_id)->value;

        return Epic::fromArray($epicArray);
    }

    public function markAsReviewed(string $id): Epic
    {
        $epic = Epic::findByPartialId($id);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $now = Carbon::now('UTC')->toIso8601String();
        $epic->update([
            'reviewed_at' => $now,
            'updated_at' => $now,
        ]);
        $epic->refresh();

        // Convert to array for backward compatibility
        $epicArray = $epic->toArray();
        $epicArray['id'] = $epic->short_id;
        $epicArray['status'] = $this->getEpicStatus($epic->short_id)->value;

        return Epic::fromArray($epicArray);
    }

    /**
     * Approve an epic (mark as approved).
     *
     * @param  string  $id  The epic ID
     * @param  string|null  $approvedBy  Who approved it (optional, defaults to 'human')
     */
    public function approveEpic(string $id, ?string $approvedBy = null): Epic
    {
        $epic = Epic::findByPartialId($id);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $now = Carbon::now('UTC')->toIso8601String();
        $approvedByValue = $approvedBy ?? 'human';

        // Clear changes_requested_at when approving
        $epic->update([
            'approved_at' => $now,
            'approved_by' => $approvedByValue,
            'changes_requested_at' => null,
            'updated_at' => $now,
        ]);
        $epic->refresh();

        // Convert to array for backward compatibility
        $epicArray = $epic->toArray();
        $epicArray['id'] = $epic->short_id;
        $epicArray['status'] = $this->getEpicStatus($epic->short_id)->value;

        return Epic::fromArray($epicArray);
    }

    /**
     * Reject an epic and request changes (moves back to in_progress).
     *
     * @param  string  $id  The epic ID
     * @param  string|null  $reason  Optional reason for rejection
     */
    public function rejectEpic(string $id, ?string $reason = null): Epic
    {
        $epic = Epic::findByPartialId($id);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $now = Carbon::now('UTC')->toIso8601String();

        // Set changes_requested_at and clear approved_at
        $epic->update([
            'changes_requested_at' => $now,
            'approved_at' => null,
            'approved_by' => null,
            'updated_at' => $now,
        ]);
        $epic->refresh();

        // Reopen tasks in the epic that were closed (move back to in_progress)
        $tasks = $this->getTasksForEpic($epic->short_id);
        foreach ($tasks as $task) {
            if (($task->status ?? '') === TaskStatus::Closed->value) {
                $this->taskService->update($task->short_id, ['status' => TaskStatus::Open->value]);
            }
        }

        // Convert to array for backward compatibility
        $epicArray = $epic->toArray();
        $epicArray['id'] = $epic->short_id;
        $epicArray['status'] = $this->getEpicStatus($epic->short_id)->value;

        return Epic::fromArray($epicArray);
    }

    public function deleteEpic(string $id): Epic
    {
        $epic = Epic::findByPartialId($id);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        // Convert to array for backward compatibility before deleting
        $epicArray = $epic->toArray();
        $epicArray['id'] = $epic->short_id;
        $epicArray['status'] = $this->getEpicStatus($epic->short_id)->value;

        $epic->delete();

        return Epic::fromArray($epicArray);
    }

    public function getTasksForEpic(string $epicId): array
    {
        $epic = Epic::findByPartialId($epicId);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $epicId));
        }

        return $this->taskService->all()
            ->filter(fn (Task $task): bool => ($task->epic_id ?? null) === $epic->short_id)
            ->values()
            ->all();
    }

    public function getEpicStatus(string $epicId): EpicStatus
    {
        $epic = Epic::findByPartialId($epicId);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $epicId));
        }

        // Check approval/rejection status first (these override computed status)
        if ($epic->approved_at !== null) {
            return EpicStatus::Approved;
        }

        if ($epic->changes_requested_at !== null) {
            // If changes were requested, check if tasks are back in progress
            $tasks = $this->getTasksForEpic($epic->short_id);
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
        if ($epic->reviewed_at !== null) {
            return EpicStatus::Reviewed;
        }

        $tasks = $this->getTasksForEpic($epic->short_id);

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
        $epic = Epic::findByPartialId($id);

        return $epic?->short_id;
    }

    /**
     * Check if all tasks in an epic are complete.
     *
     * @param  string  $epicId  The epic ID to check
     * @return array{completed: bool} Whether the epic is complete
     */
    public function checkEpicCompletion(string $epicId): array
    {
        $epic = Epic::findByPartialId($epicId);
        if ($epic === null) {
            return ['completed' => false];
        }

        $tasks = $this->getTasksForEpic($epic->short_id);
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
