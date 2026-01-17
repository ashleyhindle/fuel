<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EpicStatus;
use App\Enums\MirrorStatus;
use App\Enums\TaskStatus;
use App\Models\Epic;
use Carbon\Carbon;
use RuntimeException;

class EpicService
{
    public function __construct(
        private readonly TaskService $taskService,
        private readonly FuelContext $fuelContext
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
            'paused_at' => $now, // Start paused so tasks aren't consumed before setup is complete
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

    /**
     * Pause an epic (set status to paused).
     */
    public function pause(string $id): Epic
    {
        $epic = Epic::findByPartialId($id);
        if (! $epic instanceof Epic) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $now = Carbon::now('UTC')->toIso8601String();

        // Set paused_at to indicate paused status
        $epic->update([
            'paused_at' => $now,
            'status' => EpicStatus::Paused->value,
            'updated_at' => $now,
        ]);
        $epic->refresh();

        return $epic;
    }

    /**
     * Unpause an epic (clear paused_at, status returns to in_progress).
     */
    public function unpause(string $id): Epic
    {
        $epic = Epic::findByPartialId($id);
        if (! $epic instanceof Epic) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $now = Carbon::now('UTC')->toIso8601String();

        // Clear paused_at to unpause
        $epic->update([
            'paused_at' => null,
            'updated_at' => $now,
        ]);
        $epic->refresh();

        // Compute and persist the new status
        $status = $this->getEpicStatus($epic->short_id);
        $epic->update(['status' => $status->value]);
        $epic->refresh();

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

        // Compute and persist the new status
        $status = $this->getEpicStatus($epic->short_id);
        $epic->update(['status' => $status->value]);
        $epic->refresh();

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
            'status' => EpicStatus::Approved->value,
            'updated_at' => $now,
        ]);
        $epic->refresh();

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
            'status' => EpicStatus::ChangesRequested->value,
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

        // Recompute status after reopening tasks
        $status = $this->getEpicStatus($epic->short_id);
        $epic->update(['status' => $status->value]);
        $epic->refresh();

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

        // Check paused status first
        if ($epic->paused_at !== null) {
            return EpicStatus::Paused;
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

    /**
     * Get the project path (delegates to FuelContext).
     */
    public function getProjectPath(): string
    {
        return $this->fuelContext->getProjectPath();
    }

    /**
     * Update the mirror status of an epic.
     */
    public function updateMirrorStatus(Epic $epic, MirrorStatus $status): void
    {
        $now = Carbon::now('UTC')->toIso8601String();
        $epic->update([
            'mirror_status' => $status->value,
            'updated_at' => $now,
        ]);
    }

    /**
     * Set mirror as ready with path, branch, and base commit.
     */
    public function setMirrorReady(Epic $epic, string $path, string $branch, string $baseCommit): void
    {
        $now = Carbon::now('UTC')->toIso8601String();
        $epic->update([
            'mirror_path' => $path,
            'mirror_branch' => $branch,
            'mirror_base_commit' => $baseCommit,
            'mirror_status' => MirrorStatus::Ready->value,
            'mirror_created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Cleanup mirror directory and mark as cleaned.
     */
    public function cleanupMirror(Epic $epic): void
    {
        // If mirror path exists, remove it
        if (! empty($epic->mirror_path) && is_dir($epic->mirror_path)) {
            // Use rm -rf to remove the directory recursively
            $escapedPath = escapeshellarg($epic->mirror_path);
            exec("rm -rf {$escapedPath}");
        }

        // Update status to cleaned
        $now = Carbon::now('UTC')->toIso8601String();
        $epic->update([
            'mirror_status' => MirrorStatus::Cleaned->value,
            'updated_at' => $now,
        ]);
    }

    /**
     * Get epics with merge failed status.
     *
     * @return array<int, Epic>
     */
    public function getEpicsWithMergeFailed(): array
    {
        $epics = Epic::where('mirror_status', MirrorStatus::MergeFailed->value)
            ->orderBy('updated_at', 'desc')
            ->get();

        // Set computed status on each epic
        foreach ($epics as $epic) {
            $epic->status = $this->getEpicStatus($epic->short_id);
        }

        return $epics->all();
    }

    /**
     * Get epics with stale mirrors (updated_at > 7 days ago, not approved).
     *
     * @return array<int, Epic>
     */
    public function getEpicsWithStaleMirrors(): array
    {
        $sevenDaysAgo = Carbon::now('UTC')->subDays(7);

        $epics = Epic::whereNotNull('mirror_path')
            ->where('mirror_status', '!=', MirrorStatus::None->value)
            ->where('mirror_status', '!=', MirrorStatus::Cleaned->value)
            ->where('updated_at', '<', $sevenDaysAgo)
            ->whereNull('approved_at')
            ->orderBy('updated_at', 'asc')
            ->get();

        // Set computed status on each epic
        foreach ($epics as $epic) {
            $epic->status = $this->getEpicStatus($epic->short_id);
        }

        return $epics->all();
    }

    /**
     * Find orphaned mirror directories.
     * Returns array of paths to mirrors whose epic doesn't exist or is Approved/deleted.
     *
     * @return array<int, array{path: string, epic_id: string, reason: string}>
     */
    public function findOrphanedMirrors(): array
    {
        $projectSlug = $this->fuelContext->getProjectName();
        $mirrorsBasePath = $_SERVER['HOME'].'/.fuel/mirrors/'.$projectSlug;

        if (! is_dir($mirrorsBasePath)) {
            return [];
        }

        $orphaned = [];
        $directories = scandir($mirrorsBasePath);

        if ($directories === false) {
            return [];
        }

        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $fullPath = $mirrorsBasePath.'/'.$dir;
            if (! is_dir($fullPath)) {
                continue;
            }

            // Expect directory name to be epic-id format (e-xxxxxx)
            if (! preg_match('/^e-[a-f0-9]{6}$/', $dir)) {
                $orphaned[] = [
                    'path' => $fullPath,
                    'epic_id' => $dir,
                    'reason' => 'Invalid epic ID format',
                ];

                continue;
            }

            // Check if epic exists
            $epic = Epic::where('short_id', $dir)->first();

            if ($epic === null) {
                $orphaned[] = [
                    'path' => $fullPath,
                    'epic_id' => $dir,
                    'reason' => 'Epic does not exist',
                ];

                continue;
            }

            // Check if epic is approved (mirror should have been cleaned)
            if ($epic->approved_at !== null) {
                $orphaned[] = [
                    'path' => $fullPath,
                    'epic_id' => $dir,
                    'reason' => 'Epic is approved but mirror not cleaned',
                ];

                continue;
            }
        }

        return $orphaned;
    }
}
