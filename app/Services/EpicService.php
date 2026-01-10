<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use RuntimeException;

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

        $id = $this->generateId();
        $now = Carbon::now('UTC')->toIso8601String();

        $this->db->query(
            'INSERT INTO epics (id, title, description, status, created_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $title, $description, 'planning', $now]
        );

        $epic = [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'status' => 'planning', // New epics have no tasks, so status is 'planning'
            'created_at' => $now,
            'reviewed_at' => null,
            'approved_at' => null,
            'approved_by' => null,
        ];

        // Compute status (will be 'planning' for new epic with no tasks)
        $epic['status'] = $this->getEpicStatus($id);

        return $epic;
    }

    public function getEpic(string $id): ?array
    {
        $this->db->initialize();

        $resolvedId = $this->resolveId($id);
        if ($resolvedId === null) {
            return null;
        }

        $epic = $this->db->fetchOne('SELECT * FROM epics WHERE id = ?', [$resolvedId]);
        if ($epic === null) {
            return null;
        }

        $epic['status'] = $this->getEpicStatus($resolvedId);

        return $epic;
    }

    public function getAllEpics(): array
    {
        $this->db->initialize();

        $epics = $this->db->fetchAll('SELECT * FROM epics ORDER BY created_at DESC');

        return array_map(function (array $epic): array {
            $epic['status'] = $this->getEpicStatus($epic['id']);

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

        $epic = $this->db->fetchOne('SELECT * FROM epics WHERE id = ?', [$resolvedId]);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        $updates = [];
        $params = [];

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

        if (isset($data['status'])) {
            $this->validateStatus($data['status']);
            $updates[] = 'status = ?';
            $params[] = $data['status'];
            $epic['status'] = $data['status'];
        }

        if ($updates !== []) {
            $params[] = $resolvedId;
            $this->db->query(
                'UPDATE epics SET '.implode(', ', $updates).' WHERE id = ?',
                $params
            );
        }

        // Compute status from task states
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

        $epic = $this->db->fetchOne('SELECT * FROM epics WHERE id = ?', [$resolvedId]);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $id));
        }

        // Compute status before deleting
        $epic['status'] = $this->getEpicStatus($resolvedId);

        $this->db->query('DELETE FROM epics WHERE id = ?', [$resolvedId]);

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

        $epic = $this->db->fetchOne('SELECT approved_at FROM epics WHERE id = ?', [$resolvedId]);
        if ($epic === null) {
            throw new RuntimeException(sprintf("Epic '%s' not found", $epicId));
        }

        // If approved_at is set, epic is approved regardless of task states
        if ($epic['approved_at'] !== null) {
            return 'approved';
        }

        $tasks = $this->getTasksForEpic($resolvedId);

        // If no tasks, epic is in planning
        if (count($tasks) === 0) {
            return 'planning';
        }

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
            return 'in_progress';
        }

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
            return 'review_pending';
        }

        // Fallback: if tasks exist but not all closed and none active, still in_progress
        return 'in_progress';
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
            $epic = $this->db->fetchOne('SELECT id FROM epics WHERE id = ?', [$id]);

            return $epic !== null ? $id : null;
        }

        $epics = $this->db->fetchAll(
            'SELECT id FROM epics WHERE id LIKE ? OR id LIKE ?',
            [$id.'%', 'e-'.$id.'%']
        );

        if (count($epics) === 1) {
            return $epics[0]['id'];
        }

        if (count($epics) > 1) {
            $ids = array_column($epics, 'id');
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

        $epic = $this->db->fetchOne('SELECT * FROM epics WHERE id = ?', [$resolvedId]);
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
        foreach ($commits as $commit) {
            try {
                $process = new Process(['git', 'show', '--stat', $commit]);
                $process->run();
                if ($process->isSuccessful()) {
                    $diffOutput .= "=== Commit: {$commit} ===\n";
                    $diffOutput .= $process->getOutput()."\n";
                }
            } catch (\Throwable) {
            }
        }

        if ($diffOutput === '') {
            return 'Unable to retrieve git diffs for commits: '.implode(', ', $commits);
        }

        return $diffOutput;
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
     * Create a needs-human task for epic review.
     *
     * @param  array<string, mixed>  $epic  The epic data
     * @param  string  $summary  The generated summary
     * @return array<string, mixed>
     */
    private function createEpicReviewTask(array $epic, string $summary): array
    {
        $title = "Review completed epic: {$epic['title']} ({$epic['id']})";

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
