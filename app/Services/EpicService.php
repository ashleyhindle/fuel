<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use RuntimeException;
use Symfony\Component\Process\Process;

class EpicService
{
    private const VALID_STATUSES = ['planning', 'active', 'completed', 'cancelled'];

    private DatabaseService $db;

    private TaskService $taskService;

    public function __construct(?DatabaseService $db = null, ?TaskService $taskService = null)
    {
        $this->db = $db ?? new DatabaseService;
        $this->taskService = $taskService ?? new TaskService;
    }

    public function createEpic(string $title, ?string $description = null): array
    {
        $this->db->initialize();

        $shortId = $this->generateId();
        $now = Carbon::now('UTC')->toIso8601String();

        $this->db->query(
            'INSERT INTO epics (short_id, title, description, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$shortId, $title, $description, 'planning', $now, $now]
        );

        $epic = [
            'id' => $shortId,
            'title' => $title,
            'description' => $description,
            'status' => 'planning', // New epics have no tasks, so status is 'planning'
            'created_at' => $now,
            'updated_at' => $now,
            'reviewed_at' => null,
        ];

        // Compute status (will be 'planning' for new epic with no tasks)
        $epic['status'] = $this->getEpicStatus($shortId);

        return $epic;
    }

    public function getEpic(string $id): ?array
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
        $epic['status'] = $this->getEpicStatus($resolvedId);

        return $epic;
    }

    public function getAllEpics(): array
    {
        $this->db->initialize();

        $epics = $this->db->fetchAll('SELECT * FROM epics ORDER BY created_at DESC');

        return array_map(function (array $epic): array {
            // Map short_id to id for public interface compatibility
            $epic['id'] = $epic['short_id'];
            $epic['status'] = $this->getEpicStatus($epic['short_id']);

            return $epic;
        }, $epics);
    }

    public function updateEpic(string $id, array $data): array
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
        $epic['status'] = $this->getEpicStatus($resolvedId);

        return $epic;
    }

    public function markAsReviewed(string $id): array
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
        $epic['status'] = $this->getEpicStatus($resolvedId);

        return $epic;
    }

    public function deleteEpic(string $id): array
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
        $epic['status'] = $this->getEpicStatus($resolvedId);

        $this->db->query('DELETE FROM epics WHERE short_id = ?', [$resolvedId]);

        return $epic;
    }

    public function getTasksForEpic(string $epicId): array
    {
        $this->db->initialize();

        $resolvedId = $this->resolveId($epicId);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $epicId));
        }

        return $this->taskService->all()
            ->filter(fn (array $task): bool => ($task['epic_id'] ?? null) === $resolvedId)
            ->values()
            ->toArray();
    }

    public function getEpicStatus(string $epicId): string
    {
        $this->db->initialize();

        $resolvedId = $this->resolveId($epicId);
        if ($resolvedId === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $epicId));
        }

        $epic = $this->db->fetchOne('SELECT reviewed_at FROM epics WHERE short_id = ?', [$resolvedId]);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $epicId));
        }

        $tasks = $this->getTasksForEpic($resolvedId);

        // If no tasks, epic is in planning
        if (count($tasks) === 0) {
            $computedStatus = 'planning';
        } else {
            // Check if any task is open or in_progress
            $hasActiveTask = false;
            foreach ($tasks as $task) {
                $status = $task['status'] ?? '';
                if ($status === 'open' || $status === 'in_progress') {
                    $hasActiveTask = true;
                    break;
                }
            }

            if ($hasActiveTask) {
                $computedStatus = 'in_progress';
            } else {
                // Check if all tasks are closed
                $allClosed = true;
                foreach ($tasks as $task) {
                    $status = $task['status'] ?? '';
                    if ($status !== 'closed') {
                        $allClosed = false;
                        break;
                    }
                }

                if ($allClosed) {
                    $computedStatus = 'review_pending';
                } else {
                    // Fallback: if tasks exist but not all closed and none active, still in_progress
                    $computedStatus = 'in_progress';
                }
            }
        }

        // If reviewed_at is set, epic is reviewed regardless of computed status
        if ($epic['reviewed_at'] !== null) {
            return 'reviewed';
        }

        return $computedStatus;
    }

    private function generateId(): string
    {
        return 'e-'.bin2hex(random_bytes(3));
    }

    private function validateStatus(string $status): void
    {
        if (! in_array($status, self::VALID_STATUSES, true)) {
            throw new RuntimeException(
                sprintf("Invalid status '%s'. Must be one of: ", $status).implode(', ', self::VALID_STATUSES)
            );
        }
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
     * Check if all tasks in an epic are complete and trigger review if so.
     *
     * @param  string  $epicId  The epic ID to check
     * @return array{completed: bool, review_task_id: string|null} Whether the epic is complete and the review task ID if created
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
            $status = $task['status'] ?? '';
            if ($status !== 'closed' && $status !== 'cancelled') {
                $allClosed = false;
                break;
            }
        }

        if (! $allClosed) {
            return ['completed' => false, 'review_task_id' => null];
        }

        // Check if a review task already exists for this epic
        $existingReviewTask = $this->findExistingReviewTask($resolvedId);
        if ($existingReviewTask !== null) {
            return ['completed' => true, 'review_task_id' => $existingReviewTask['id']];
        }

        $gitDiff = $this->getCombinedGitDiff($tasks);
        $summary = $this->generateEpicSummary($epic, $tasks, $gitDiff);
        $reviewTask = $this->createEpicReviewTask($epic, $summary);

        return ['completed' => true, 'review_task_id' => $reviewTask['id']];
    }

    /**
     * Get combined git diff from all commits associated with epic's tasks.
     *
     * @param  array<array<string, mixed>>  $tasks  The tasks in the epic
     */
    private function getCombinedGitDiff(array $tasks): string
    {
        $commits = [];
        foreach ($tasks as $task) {
            if (isset($task['commit_hash']) && is_string($task['commit_hash']) && $task['commit_hash'] !== '') {
                $commits[] = $task['commit_hash'];
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
                    $reason = $this->classifyGitError($errorOutput, $commit);
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
        if ($diffOutput === '' && empty($errors)) {
            return 'Unable to retrieve git diffs for commits: '.implode(', ', $commits);
        }

        if (! empty($errors)) {
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
     * @param  string  $commit  The commit hash that was being processed
     * @return string A human-readable reason for the failure
     */
    private function classifyGitError(string $errorOutput, string $commit): string
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
     * @param  array<array<string, mixed>>  $tasks  The tasks in the epic
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
            $taskTitle = $task['title'] ?? 'Untitled';
            $taskId = $task['id'] ?? 'unknown';
            $taskStatus = $task['status'] ?? 'unknown';
            $commitHash = $task['commit_hash'] ?? 'no commit';

            $summary .= "- [{$taskId}] {$taskTitle} ({$taskStatus})";
            if ($commitHash !== 'no commit') {
                $summary .= " - commit: {$commitHash}";
            }
            $summary .= "\n";
        }

        $summary .= "\n## Git Changes Summary\n\n```\n{$gitDiff}\n```\n";

        return $summary;
    }

    /**
     * Find an existing review task for an epic.
     *
     * @param  string  $epicId  The epic ID to search for
     * @return array<string, mixed>|null The existing review task, or null if not found
     */
    private function findExistingReviewTask(string $epicId): ?array
    {
        $allTasks = $this->taskService->all();

        return $allTasks->first(function (array $task) use ($epicId): bool {
            $labels = $task['labels'] ?? [];
            if (! is_array($labels) || ! in_array('epic-review', $labels, true)) {
                return false;
            }

            // Check if epic ID is in the title (format: "Review completed epic: ... ({$epicId})")
            $title = $task['title'] ?? '';
            if (str_contains($title, "({$epicId})")) {
                return true;
            }

            // Also check description in case title format changed
            $description = $task['description'] ?? '';
            if (str_contains($description, $epicId)) {
                return true;
            }

            return false;
        });
    }

    /**
     * Create a needs-human task for epic review.
     *
     * @param  array<string, mixed>  $epic  The epic data
     * @param  string  $summary  The generated summary
     * @return array<string, mixed>
     */
    private function createEpicReviewTask(array $epic, string $summary): array
    {
        $epicId = $epic['short_id'] ?? $epic['id'];
        $title = "Review completed epic: {$epic['title']} ({$epicId})";

        return $this->taskService->create([
            'title' => $title,
            'description' => $summary,
            'type' => 'task',
            'priority' => 1,
            'labels' => ['needs-human', 'epic-review'],
            'complexity' => 'simple',
        ]);
    }
}
