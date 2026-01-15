<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EpicStatus;
use App\Enums\TaskStatus;
use App\Models\Epic;
use Carbon\Carbon;
use RuntimeException;

class EpicService
{
    public function __construct(
        private readonly TaskService $taskService
    ) {}

    public function createEpic(string $title, ?string $description = null, bool $selfGuided = false): Epic
    {
        $shortId = $this->generateId();
        $now = Carbon::now('UTC')->toIso8601String();

        $epic = Epic::create([
            'short_id' => $shortId,
            'title' => $title,
            'description' => $description,
            'self_guided' => $selfGuided,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // If self-guided, create implementation task
        if ($selfGuided) {
            $this->taskService->create([
                'title' => 'Implement: '.$title,
                'description' => 'Self-guided implementation. See epic plan for acceptance criteria.',
                'epic_id' => $epic->short_id,
                'agent' => 'selfguided',
                'complexity' => 'complex',
                'type' => 'feature',
            ]);
        }

        // Set computed status (will be 'planning' for new epic with no tasks)
        $epic->status = $this->getEpicStatus($shortId);

        return $epic;
    }

    public function getEpic(string $id): ?Epic
    {
        $epic = Epic::findByPartialId($id);
        if (! $epic instanceof Epic) {
            return null;
        }

        $epic->status = $this->getEpicStatus($epic->short_id);

        return $epic;
    }

    /**
     * @return array<int, Epic>
     */
    public function getAllEpics(): array
    {
        $epics = Epic::orderBy('created_at', 'desc')->get();

        // Set computed status on each epic as the enum (not string)
        foreach ($epics as $epic) {
            $epic->status = $this->getEpicStatus($epic->short_id);
        }

        return $epics->all();
    }

    /**
     * Get all epics that are pending human review.
     * An epic is review_pending when: has tasks, all tasks done, and not yet reviewed.
     *
     * @return array<int, Epic>
     */
    public function getEpicsPendingReview(): array
    {
        $epics = Epic::pendingReview();

        foreach ($epics as $epic) {
            $epic->status = EpicStatus::ReviewPending;
        }

        return $epics->all();
    }

    public function updateEpic(string $id, array $data): Epic
    {
        $epic = Epic::findByPartialId($id);
        if (! $epic instanceof Epic) {
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

        // Set self_guided explicitly if requested
        if (array_key_exists('self_guided', $data)) {
            $updates['self_guided'] = $data['self_guided'];
        }

        $epic->update($updates);
        $epic->refresh();
        $epic->status = $this->getEpicStatus($epic->short_id);

        return $epic;
    }

    public function markAsReviewed(string $id): Epic
    {
        $epic = Epic::findByPartialId($id);
        if (! $epic instanceof Epic) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $now = Carbon::now('UTC')->toIso8601String();
        $epic->update([
            'reviewed_at' => $now,
            'updated_at' => $now,
        ]);
        $epic->refresh();
        $epic->status = $this->getEpicStatus($epic->short_id);

        return $epic;
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
        if (! $epic instanceof Epic) {
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
        $epic->status = $this->getEpicStatus($epic->short_id);

        // Trigger reality.md update after epic approval
        app(UpdateRealityService::class)->triggerUpdate(null, $epic);

        return $epic;
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
        if (! $epic instanceof Epic) {
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

        // Reopen tasks in the epic that were done (move back to in_progress)
        $tasks = $this->getTasksForEpic($epic->short_id);
        foreach ($tasks as $task) {
            if ($task->status === TaskStatus::Done) {
                $this->taskService->update($task->short_id, ['status' => TaskStatus::Open->value]);
            }
        }

        $epic->status = $this->getEpicStatus($epic->short_id);

        return $epic;
    }

    public function deleteEpic(string $id): Epic
    {
        $epic = Epic::findByPartialId($id);
        if (! $epic instanceof Epic) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $epic->status = $this->getEpicStatus($epic->short_id);
        $epic->delete();

        return $epic;
    }

    public function getTasksForEpic(string $epicId): array
    {
        $epic = Epic::findByPartialId($epicId);
        if (! $epic instanceof Epic) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $epicId));
        }

        return $epic->tasks->all();
    }

    public function getEpicStatus(string $epicId): EpicStatus
    {
        $epic = Epic::findByPartialId($epicId);
        if (! $epic instanceof Epic) {
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
                if ($task->status === TaskStatus::Open || $task->status === TaskStatus::InProgress) {
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
            if ($task->status === TaskStatus::Open || $task->status === TaskStatus::InProgress) {
                $hasActiveTask = true;
                break;
            }
        }

        if ($hasActiveTask) {
            return EpicStatus::InProgress;
        }

        // Check if all tasks are done
        $allDone = true;
        foreach ($tasks as $task) {
            if ($task->status !== TaskStatus::Done) {
                $allDone = false;
                break;
            }
        }

        if ($allDone) {
            return EpicStatus::ReviewPending;
        }

        // Fallback: if tasks exist but not all done and none active, still in_progress
        return EpicStatus::InProgress;
    }

    private function generateId(): string
    {
        return 'e-'.bin2hex(random_bytes(3));
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
        if (! $epic instanceof Epic) {
            return ['completed' => false];
        }

        $tasks = $this->getTasksForEpic($epic->short_id);
        if ($tasks === []) {
            return ['completed' => false];
        }

        $allDone = true;
        foreach ($tasks as $task) {
            if ($task->status !== TaskStatus::Done && $task->status !== TaskStatus::Cancelled) {
                $allDone = false;
                break;
            }
        }

        if (! $allDone) {
            return ['completed' => false];
        }

        return ['completed' => true];
    }
}
