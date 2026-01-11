<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EpicStatus;
use App\Models\Epic;
use App\Models\Task;
use Carbon\Carbon;
use RuntimeException;
use Symfony\Component\Process\Process;

class EpicService
{
    private readonly TaskService $taskService;

    public function __construct(private readonly DatabaseService $db, ?TaskService $taskService = null)
    {
        $this->taskService = $taskService ?? new TaskService($this->db);
    }

    public function createEpic(string $title, ?string $description = null): Epic
    {
        $this->db->initialize();

        $shortId = $this->generateId();
        $now = Carbon::now('UTC')->toIso8601String();

        // Note: status is not stored - it's computed from task states
        $this->db->query(
            'INSERT INTO epics (short_id, title, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$shortId, $title, $description, $now, $now]
        );

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
        $this->db->initialize();

        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            return null;
        }

        $epic = $this->db->fetchOne('SELECT * FROM epics WHERE short_id = ?', [$resolvedId]);
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
        $this->db->initialize();

        $epics = $this->db->fetchAll('SELECT * FROM epics ORDER BY created_at DESC');

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
        $this->db->initialize();

        $sql = "
            SELECT e.*
            FROM epics e
            WHERE e.reviewed_at IS NULL
              AND EXISTS (SELECT 1 FROM tasks t WHERE t.epic_id = e.short_id)
              AND NOT EXISTS (
                  SELECT 1 FROM tasks t
                  WHERE t.epic_id = e.short_id
                    AND t.status NOT IN ('closed', 'cancelled')
              )
            ORDER BY e.created_at DESC
        ";

        $epics = $this->db->fetchAll($sql);

        return array_map(function (array $epic): Epic {
            $epic['id'] = $epic['short_id'];
            $epic['status'] = EpicStatus::ReviewPending->value;

            return Epic::fromArray($epic);
        }, $epics);
    }

    public function updateEpic(string $id, array $data): Epic
    {
        $this->db->initialize();

        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $epic = $this->db->fetchOne('SELECT * FROM epics WHERE short_id = ?', [$resolvedId]);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $updates = ['updated_at = ?'];
        $params = [Carbon::now('UTC')->toIso8601String()];

        if (isset($data['title'])) {
            $updates[] = 'title = ?';
            $params[] = $data['title'];
            $epic['title'] = $data['title'];
        }

        if (array_key_exists('description', $data)) {
            $updates[] = 'description = ?';
            $params[] = $data['description'];
            $epic['description'] = $data['description'];
        }

        $params[] = $resolvedId;
        $this->db->query(
            'UPDATE epics SET '.implode(', ', $updates).' WHERE short_id = ?',
            $params
        );

        // Map short_id to id for public interface compatibility
        $epic['id'] = $epic['short_id'];
        // Compute status from task states
        $epic['status'] = $this->getEpicStatus($resolvedId)->value;

        return Epic::fromArray($epic);
    }

    public function markAsReviewed(string $id): Epic
    {
        $this->db->initialize();

        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $epic = $this->db->fetchOne('SELECT * FROM epics WHERE short_id = ?', [$resolvedId]);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $now = Carbon::now('UTC')->toIso8601String();
        $this->db->query('UPDATE epics SET reviewed_at = ?, updated_at = ? WHERE short_id = ?', [$now, $now, $resolvedId]);

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
        $this->db->initialize();

        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $epic = $this->db->fetchOne('SELECT * FROM epics WHERE short_id = ?', [$resolvedId]);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $now = Carbon::now('UTC')->toIso8601String();
        $approvedByValue = $approvedBy ?? 'human';

        // Clear changes_requested_at when approving
        $this->db->query(
            'UPDATE epics SET approved_at = ?, approved_by = ?, changes_requested_at = NULL, updated_at = ? WHERE short_id = ?',
            [$now, $approvedByValue, $now, $resolvedId]
        );

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
        $this->db->initialize();

        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $epic = $this->db->fetchOne('SELECT * FROM epics WHERE short_id = ?', [$resolvedId]);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $now = Carbon::now('UTC')->toIso8601String();

        // Set changes_requested_at and clear approved_at
        $this->db->query(
            'UPDATE epics SET changes_requested_at = ?, approved_at = NULL, approved_by = NULL, updated_at = ? WHERE short_id = ?',
            [$now, $now, $resolvedId]
        );

        // Reopen tasks in the epic that were closed (move back to in_progress)
        $tasks = $this->getTasksForEpic($resolvedId);
        foreach ($tasks as $task) {
            if (($task->status ?? '') === 'closed') {
                $this->taskService->update($task->id, ['status' => 'open']);
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
        $this->db->initialize();

        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $epic = $this->db->fetchOne('SELECT * FROM epics WHERE short_id = ?', [$resolvedId]);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        // Map short_id to id for public interface compatibility
        $epic['id'] = $epic['short_id'];
        // Compute status before deleting
        $epic['status'] = $this->getEpicStatus($resolvedId)->value;

        $this->db->query('DELETE FROM epics WHERE short_id = ?', [$resolvedId]);

        return Epic::fromArray($epic);
    }

    public function getTasksForEpic(string $epicId): array
    {
        $this->db->initialize();

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
        $this->db->initialize();

        $resolvedId = $this->resolveId($epicId);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $epicId));
        }

        $epic = $this->db->fetchOne('SELECT approved_at, approved_by, changes_requested_at, reviewed_at FROM epics WHERE short_id = ?', [$resolvedId]);
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
                if ($status === 'open' || $status === 'in_progress') {
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
            if ($status === 'open' || $status === 'in_progress') {
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
            if ($status !== 'closed') {
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
        if (str_starts_with($id, 'e-') && strlen($id) === 8) {
            $epic = $this->db->fetchOne('SELECT short_id FROM epics WHERE short_id = ?', [$id]);

            return $epic !== null ? $id : null;
        }

        $epics = $this->db->fetchAll(
            'SELECT short_id FROM epics WHERE short_id LIKE ? OR short_id LIKE ?',
            [$id.'%', 'e-'.$id.'%']
        );

        if (count($epics) === 1) {
            return $epics[0]['short_id'];
        }

        if (count($epics) > 1) {
            $ids = array_column($epics, 'short_id');
            throw new RuntimeException(
                sprintf("Ambiguous epic ID '%s'. Matches: ", $id).implode(', ', $ids)
            );
        }

        return null;
    }

    /**
     * Check if all tasks in an epic are complete and create review task if needed.
     *
     * @param  string  $epicId  The epic ID to check
     * @return array{completed: bool, review_task_id: string|null} Whether the epic is complete and review task ID if created
     */
    public function checkEpicCompletion(string $epicId): array
    {
        $this->db->initialize();

        $resolvedId = $this->resolveId($epicId);
        if ($resolvedId === null) {
            return ['completed' => false, 'review_task_id' => null];
        }

        $epic = $this->db->fetchOne('SELECT * FROM epics WHERE short_id = ?', [$resolvedId]);
        if ($epic === null) {
            return ['completed' => false, 'review_task_id' => null];
        }

        $tasks = $this->getTasksForEpic($resolvedId);
        if ($tasks === []) {
            return ['completed' => false, 'review_task_id' => null];
        }

        $allClosed = true;
        foreach ($tasks as $task) {
            $status = $task->status ?? '';
            if ($status !== 'closed' && $status !== 'cancelled') {
                $allClosed = false;
                break;
            }
        }

        if (! $allClosed) {
            return ['completed' => false, 'review_task_id' => null];
        }

        // Check if a review task already exists for this epic (idempotency)
        $existingReviewTask = $this->findExistingReviewTask($resolvedId);
        if ($existingReviewTask instanceof Task) {
            return ['completed' => true, 'review_task_id' => $existingReviewTask->id];
        }

        // Map short_id to id for public interface compatibility (needed for createEpicReviewTask)
        $epic['id'] = $epic['short_id'];

        // Create review task
        $gitDiff = $this->getCombinedGitDiff($tasks);
        $summary = $this->generateEpicSummary($epic, $tasks, $gitDiff);
        $reviewTask = $this->createEpicReviewTask($epic, $summary);

        return ['completed' => true, 'review_task_id' => $reviewTask->id];
    }

    /**
     * Find an existing review task for an epic.
     *
     * @param  string  $epicId  The epic short_id
     * @return Task|null The review task if found
     */
    private function findExistingReviewTask(string $epicId): ?Task
    {
        $allTasks = $this->taskService->all();

        return $allTasks->first(function (Task $task) use ($epicId): bool {
            $labels = $task->labels ?? [];
            if (! is_array($labels) || ! in_array('epic-review', $labels, true)) {
                return false;
            }

            // Check if epic ID is in the title (format: "Review completed epic: ... ({$epicId})")
            $title = $task->title ?? '';
            if (str_contains($title, sprintf('(%s)', $epicId))) {
                return true;
            }

            // Also check description in case title format changed
            $description = $task->description ?? '';
            return str_contains($description, $epicId);
        });
    }

    /**
     * Create a needs-human task for epic review.
     *
     * @param  array<string, mixed>  $epic  The epic data
     * @param  string  $summary  The generated summary
     * @return Task The created review task
     */
    private function createEpicReviewTask(array $epic, string $summary): Task
    {
        $title = sprintf('Review completed epic: %s (%s)', $epic['title'], $epic['id']);

        return $this->taskService->create([
            'title' => $title,
            'description' => $summary,
            'type' => 'task',
            'priority' => 1,
            'labels' => ['needs-human', 'epic-review'],
            'complexity' => 'simple',
        ]);
    }

    /**
     * Get combined git diff from all commits associated with epic's tasks.
     *
     * @param  array<Task>  $tasks  The tasks in the epic
     */
    private function getCombinedGitDiff(array $tasks): string
    {
        $commits = [];
        foreach ($tasks as $task) {
            $commitHash = $task->commit_hash ?? null;
            if (is_string($commitHash) && $commitHash !== '') {
                $commits[] = $commitHash;
            }
        }

        if ($commits === []) {
            return 'No commits associated with tasks in this epic.';
        }

        $diffOutput = '';
        $errors = [];

        foreach ($commits as $commit) {
            try {
                // Check if git is available
                $gitCheckProcess = new Process(['git', '--version']);
                $gitCheckProcess->run();
                if (! $gitCheckProcess->isSuccessful()) {
                    $errors[] = [
                        'commit' => $commit,
                        'reason' => 'Git is not available',
                        'details' => $gitCheckProcess->getErrorOutput() ?: 'Git command failed',
                    ];

                    continue;
                }

                // Try to get the commit diff
                $process = new Process(['git', 'show', '--stat', $commit]);
                $process->run();

                if ($process->isSuccessful()) {
                    $diffOutput .= "=== Commit: {$commit} ===\n";
                    $diffOutput .= $process->getOutput()."\n";
                } else {
                    // Process failed - analyze the error
                    $errorOutput = $process->getErrorOutput();
                    $reason = $this->classifyGitError($errorOutput);
                    $errors[] = [
                        'commit' => $commit,
                        'reason' => $reason,
                        'details' => trim($errorOutput) ?: 'Unknown error',
                    ];
                }
            } catch (\Throwable $e) {
                // Exception occurred - determine the cause
                $reason = 'Exception occurred';
                $details = $e->getMessage();

                // Check if it's a "command not found" type error
                if (str_contains($details, 'not found') || str_contains($details, 'No such file')) {
                    $reason = 'Git is not available';
                }

                $errors[] = [
                    'commit' => $commit,
                    'reason' => $reason,
                    'details' => $details,
                ];
            }
        }

        // Build the output with error information
        if ($diffOutput === '' && $errors === []) {
            return 'Unable to retrieve git diffs for commits: '.implode(', ', $commits);
        }

        if ($errors !== []) {
            $diffOutput .= "\n=== Errors retrieving commits ===\n";
            foreach ($errors as $error) {
                $diffOutput .= sprintf(
                    "Commit %s: %s (%s)\n",
                    $error['commit'],
                    $error['reason'],
                    $error['details']
                );
            }
        }

        return $diffOutput;
    }

    /**
     * Classify git error output to determine the specific failure reason.
     *
     * @param  string  $errorOutput  The error output from git command
     * @return string A human-readable reason for the failure
     */
    private function classifyGitError(string $errorOutput): string
    {
        $errorLower = strtolower($errorOutput);

        // Check for invalid hash format
        if (preg_match('/ambiguous argument|bad object|not a valid object name/i', $errorLower)) {
            return 'Invalid commit hash';
        }

        // Check for commit not found in repository
        if (preg_match('/unknown revision|not found|does not exist/i', $errorLower)) {
            return 'Commit not found in repository';
        }

        // Check for git not available
        if (preg_match('/command not found|not found|no such file/i', $errorLower)) {
            return 'Git is not available';
        }

        // Generic failure
        return 'Failed to retrieve commit';
    }

    /**
     * Generate a summary of what was accomplished in the epic.
     *
     * @param  array<string, mixed>  $epic  The epic data
     * @param  array<Task>  $tasks  The tasks in the epic
     * @param  string  $gitDiff  The combined git diff
     */
    private function generateEpicSummary(array $epic, array $tasks, string $gitDiff): string
    {
        $title = $epic['title'] ?? 'Untitled Epic';
        $description = $epic['description'] ?? 'No description';

        $summary = "# Epic Completion Review: {$title}\n\n";
        $summary .= "## Epic Description\n{$description}\n\n";
        $summary .= '## Completed Tasks ('.count($tasks)." total)\n\n";

        foreach ($tasks as $task) {
            $taskTitle = $task->title ?? 'Untitled';
            $taskId = $task->id ?? 'unknown';
            $taskStatus = $task->status ?? 'unknown';
            $commitHash = $task->commit_hash ?? 'no commit';

            $summary .= sprintf('- [%s] %s (%s)', $taskId, $taskTitle, $taskStatus);
            if ($commitHash !== 'no commit') {
                $summary .= ' - commit: ' . $commitHash;
            }

            $summary .= "\n";
        }

        return $summary . "\n## Git Changes Summary\n\n```\n{$gitDiff}\n```\n";
    }
}
