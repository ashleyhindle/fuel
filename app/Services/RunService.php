<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class RunService
{
    private const OUTPUT_MAX_LENGTH = 10240; // 10KB

    // Run status constants
    // Lifecycle: running -> completed (via updateLatestRun) or failed (via cleanupOrphanedRuns)
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly DatabaseService $databaseService
    ) {}

    /**
     * Log a run for a task.
     *
     * @param  string  $taskId  Task ID (e.g., 'f-xxxxxx')
     * @param  array<string, mixed>  $data  Run data (agent, model, started_at, ended_at, exit_code, output)
     */
    public function logRun(string $taskId, array $data): void
    {
        // Resolve task short_id to integer id
        $taskIntId = $this->resolveTaskId($taskId);
        if ($taskIntId === null) {
            throw new RuntimeException(sprintf('Task %s not found', $taskId));
        }

        // Generate run_id
        $runId = $this->generateRunId();

        // Truncate output to 10KB if present
        $output = $data['output'] ?? null;
        if ($output !== null && is_string($output) && strlen($output) > self::OUTPUT_MAX_LENGTH) {
            $output = substr($output, 0, self::OUTPUT_MAX_LENGTH);
        }

        // Insert into runs table - all runs start as STATUS_RUNNING
        $this->databaseService->query(
            'INSERT INTO runs (id, task_id, agent, model, started_at, ended_at, exit_code, output, session_id, cost_usd, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $runId,
                $taskIntId,
                $data['agent'] ?? null,
                $data['model'] ?? null,
                $data['started_at'] ?? now()->toIso8601String(),
                $data['ended_at'] ?? null,
                $data['exit_code'] ?? null,
                $output,
                $data['session_id'] ?? null,
                $data['cost_usd'] ?? null,
                self::STATUS_RUNNING,
            ]
        );
    }

    /**
     * Get all runs for a task.
     *
     * @param  string  $taskId  Task ID
     * @return array<int, array<string, mixed>>
     */
    public function getRuns(string $taskId): array
    {
        // Resolve task short_id to integer id
        $taskIntId = $this->resolveTaskId($taskId);
        if ($taskIntId === null) {
            return [];
        }

        $runs = $this->databaseService->fetchAll(
            'SELECT id, agent, model, started_at, ended_at, exit_code, output, session_id, cost_usd
             FROM runs
             WHERE task_id = ?
             ORDER BY started_at ASC',
            [$taskIntId]
        );

        // Transform to match old JSONL format (id becomes run_id)
        return array_map(function (array $run): array {
            return [
                'run_id' => $run['id'],
                'agent' => $run['agent'],
                'model' => $run['model'],
                'started_at' => $run['started_at'],
                'ended_at' => $run['ended_at'],
                'exit_code' => $run['exit_code'],
                'output' => $run['output'],
                'session_id' => $run['session_id'],
                'cost_usd' => $run['cost_usd'],
            ];
        }, $runs);
    }

    /**
     * Get the latest run for a task.
     *
     * @param  string  $taskId  Task ID
     * @return array<string, mixed>|null
     */
    public function getLatestRun(string $taskId): ?array
    {
        // Resolve task short_id to integer id
        $taskIntId = $this->resolveTaskId($taskId);
        if ($taskIntId === null) {
            return null;
        }

        $run = $this->databaseService->fetchOne(
            'SELECT id, agent, model, started_at, ended_at, exit_code, output, session_id, cost_usd
             FROM runs
             WHERE task_id = ?
             ORDER BY started_at DESC, ROWID DESC
             LIMIT 1',
            [$taskIntId]
        );

        if ($run === null) {
            return null;
        }

        // Transform to match old JSONL format (id becomes run_id)
        return [
            'run_id' => $run['id'],
            'agent' => $run['agent'],
            'model' => $run['model'],
            'started_at' => $run['started_at'],
            'ended_at' => $run['ended_at'],
            'exit_code' => $run['exit_code'],
            'output' => $run['output'],
            'session_id' => $run['session_id'],
            'cost_usd' => $run['cost_usd'],
        ];
    }

    /**
     * Update the latest run for a task with completion data.
     *
     * @param  string  $taskId  Task ID
     * @param  array<string, mixed>  $data  Update data (ended_at, exit_code, output, session_id, cost_usd)
     */
    public function updateLatestRun(string $taskId, array $data): void
    {
        // Resolve task short_id to integer id
        $taskIntId = $this->resolveTaskId($taskId);
        if ($taskIntId === null) {
            throw new RuntimeException(sprintf('Task %s not found', $taskId));
        }

        // Get the latest run ID
        $latestRun = $this->databaseService->fetchOne(
            'SELECT id FROM runs WHERE task_id = ? ORDER BY started_at DESC, ROWID DESC LIMIT 1',
            [$taskIntId]
        );

        if ($latestRun === null) {
            throw new RuntimeException(sprintf('No runs found for task %s to update', $taskId));
        }

        // Truncate output to 10KB if present
        $output = $data['output'] ?? null;
        if ($output !== null && is_string($output) && strlen($output) > self::OUTPUT_MAX_LENGTH) {
            $output = substr($output, 0, self::OUTPUT_MAX_LENGTH);
        }

        // Build dynamic UPDATE query based on provided fields
        $updateFields = [];
        $params = [];

        if (isset($data['ended_at'])) {
            $updateFields[] = 'ended_at = ?';
            $params[] = $data['ended_at'];
            // Also update status to completed when ended_at is set
            $updateFields[] = 'status = ?';
            $params[] = self::STATUS_COMPLETED;
        }

        if (isset($data['exit_code'])) {
            $updateFields[] = 'exit_code = ?';
            $params[] = $data['exit_code'];
        }

        if (array_key_exists('output', $data)) {
            $updateFields[] = 'output = ?';
            $params[] = $output;
        }

        if (isset($data['session_id'])) {
            $updateFields[] = 'session_id = ?';
            $params[] = $data['session_id'];
        }

        if (isset($data['cost_usd'])) {
            $updateFields[] = 'cost_usd = ?';
            $params[] = $data['cost_usd'];
        }

        if (empty($updateFields)) {
            return; // Nothing to update
        }

        // Add run ID to params
        $params[] = $latestRun['id'];

        $this->databaseService->query(
            'UPDATE runs SET '.implode(', ', $updateFields).' WHERE id = ?',
            $params
        );
    }

    /**
     * Clean up orphaned runs (runs that started but never completed due to consume crash).
     * Marks incomplete runs as failed with a special exit code and message.
     *
     * @param  callable  $isPidDead  Callback (int $pid): bool to check if a PID is dead
     * @return int Number of orphaned runs cleaned up
     */
    public function cleanupOrphanedRuns(callable $isPidDead): int
    {
        // Find all runs with status='running' and ended_at IS NULL
        $orphanedRuns = $this->databaseService->fetchAll(
            'SELECT id FROM runs WHERE status = ? AND ended_at IS NULL',
            [self::STATUS_RUNNING]
        );

        if (empty($orphanedRuns)) {
            return 0;
        }

        // Mark each orphaned run as failed
        $endedAt = date('c');
        $cleanupCount = 0;

        foreach ($orphanedRuns as $run) {
            $this->databaseService->query(
                'UPDATE runs SET status = ?, ended_at = ?, exit_code = ?, output = ? WHERE id = ?',
                [
                    self::STATUS_FAILED,
                    $endedAt,
                    -1,
                    '[Run orphaned - consume process died before completion]',
                    $run['id'],
                ]
            );
            $cleanupCount++;
        }

        return $cleanupCount;
    }

    /**
     * Generate a unique run ID.
     */
    private function generateRunId(): string
    {
        $length = 6;
        $maxAttempts = 100;

        $attempts = 0;
        while ($attempts < $maxAttempts) {
            $hash = hash('sha256', uniqid('run-', true).microtime(true));
            $id = 'run-'.substr($hash, 0, $length);

            // Check if ID already exists in database
            $existing = $this->databaseService->fetchOne('SELECT id FROM runs WHERE id = ?', [$id]);
            if ($existing === null) {
                return $id;
            }

            $attempts++;
        }

        throw new RuntimeException(
            sprintf('Failed to generate unique run ID after %d attempts. This is extremely unlikely.', $maxAttempts)
        );
    }

    /**
     * Resolve a task short_id to its integer id.
     *
     * @param  string  $taskShortId  The task short_id (e.g., 'f-xxxxxx')
     * @return int|null The integer id, or null if task not found
     */
    private function resolveTaskId(string $taskShortId): ?int
    {
        $task = $this->databaseService->fetchOne('SELECT id FROM tasks WHERE short_id = ?', [$taskShortId]);

        return $task !== null ? (int) $task['id'] : null;
    }
}
