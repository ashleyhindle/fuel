<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Run;
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
     * Create a new run for a task with status='running'.
     * This method generates the run ID upfront so it can be used for process directories.
     *
     * @param  string  $taskId  Task ID (e.g., 'f-xxxxxx')
     * @param  array<string, mixed>  $data  Run data (agent, model, started_at, session_id, cost_usd)
     * @return string The generated run short_id (e.g., 'run-abc123')
     */
    public function createRun(string $taskId, array $data = []): string
    {
        // Resolve task short_id to integer id
        $taskIntId = $this->resolveTaskId($taskId);
        if ($taskIntId === null) {
            throw new RuntimeException(sprintf('Task %s not found', $taskId));
        }

        // Generate short_id for the run upfront
        $shortId = $this->generateShortId();

        // Insert into runs table with status='running'
        $this->databaseService->query(
            'INSERT INTO runs (short_id, task_id, agent, model, started_at, ended_at, exit_code, output, session_id, cost_usd, status, duration_seconds)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $shortId,
                $taskIntId,
                $data['agent'] ?? null,
                $data['model'] ?? null,
                $data['started_at'] ?? now()->toIso8601String(),
                null, // ended_at
                null, // exit_code
                null, // output
                $data['session_id'] ?? null,
                $data['cost_usd'] ?? null,
                self::STATUS_RUNNING,
                null, // duration_seconds
            ]
        );

        return $shortId;
    }

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

        // Generate short_id for the run
        $shortId = $this->generateShortId();

        // Truncate output to 10KB if present
        $output = $data['output'] ?? null;
        if ($output !== null && is_string($output) && strlen($output) > self::OUTPUT_MAX_LENGTH) {
            $output = substr($output, 0, self::OUTPUT_MAX_LENGTH);
        }

        // Calculate duration_seconds if both timestamps are provided
        $durationSeconds = null;
        if (isset($data['started_at'], $data['ended_at'])) {
            $start = strtotime((string) $data['started_at']);
            $end = strtotime((string) $data['ended_at']);
            if ($start !== false && $end !== false) {
                $durationSeconds = $end - $start;
            }
        }

        // Insert into runs table - id auto-increments, all runs start as STATUS_RUNNING
        $this->databaseService->query(
            'INSERT INTO runs (short_id, task_id, agent, model, started_at, ended_at, exit_code, output, session_id, cost_usd, status, duration_seconds)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $shortId,
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
                $durationSeconds,
            ]
        );
    }

    /**
     * Get all runs for a task.
     *
     * @param  string  $taskId  Task ID
     * @return array<int, Run>
     */
    public function getRuns(string $taskId): array
    {
        // Resolve task short_id to integer id
        $taskIntId = $this->resolveTaskId($taskId);
        if ($taskIntId === null) {
            return [];
        }

        $runs = $this->databaseService->fetchAll(
            'SELECT short_id, agent, model, started_at, ended_at, exit_code, output, session_id, cost_usd, duration_seconds, status
             FROM runs
             WHERE task_id = ?
             ORDER BY started_at ASC',
            [$taskIntId]
        );

        // Transform to Run models (short_id becomes run_id for backward compatibility)
        return array_map(fn (array $run): Run => Run::fromArray([
            'run_id' => $run['short_id'],
            'agent' => $run['agent'],
            'model' => $run['model'],
            'started_at' => $run['started_at'],
            'ended_at' => $run['ended_at'],
            'exit_code' => $run['exit_code'],
            'output' => $run['output'],
            'session_id' => $run['session_id'],
            'cost_usd' => $run['cost_usd'],
            'duration_seconds' => $run['duration_seconds'],
            'status' => $run['status'],
        ]), $runs);
    }

    /**
     * Get the latest run for a task.
     *
     * @param  string  $taskId  Task ID
     */
    public function getLatestRun(string $taskId): ?Run
    {
        // Resolve task short_id to integer id
        $taskIntId = $this->resolveTaskId($taskId);
        if ($taskIntId === null) {
            return null;
        }

        $run = $this->databaseService->fetchOne(
            'SELECT short_id, agent, model, started_at, ended_at, exit_code, output, session_id, cost_usd, duration_seconds, status
             FROM runs
             WHERE task_id = ?
             ORDER BY started_at DESC, id DESC
             LIMIT 1',
            [$taskIntId]
        );

        if ($run === null) {
            return null;
        }

        // Transform to Run model (short_id becomes run_id for backward compatibility)
        return Run::fromArray([
            'run_id' => $run['short_id'],
            'agent' => $run['agent'],
            'model' => $run['model'],
            'started_at' => $run['started_at'],
            'ended_at' => $run['ended_at'],
            'exit_code' => $run['exit_code'],
            'output' => $run['output'],
            'session_id' => $run['session_id'],
            'cost_usd' => $run['cost_usd'],
            'duration_seconds' => $run['duration_seconds'],
            'status' => $run['status'],
        ]);
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

        // Get the latest run ID and started_at (needed for duration calculation)
        $latestRun = $this->databaseService->fetchOne(
            'SELECT id, started_at FROM runs WHERE task_id = ? ORDER BY started_at DESC, id DESC LIMIT 1',
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

            // Calculate and set duration_seconds
            if ($latestRun['started_at'] !== null) {
                $start = strtotime($latestRun['started_at']);
                $end = strtotime((string) $data['ended_at']);
                if ($start !== false && $end !== false) {
                    $updateFields[] = 'duration_seconds = ?';
                    $params[] = $end - $start;
                }
            }
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

        if ($updateFields === []) {
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

        if ($orphanedRuns === []) {
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
     * Generate a unique short_id for a run.
     */
    private function generateShortId(): string
    {
        $length = 6;
        $maxAttempts = 100;

        $attempts = 0;
        while ($attempts < $maxAttempts) {
            $hash = hash('sha256', uniqid('run-', true).microtime(true));
            $shortId = 'run-'.substr($hash, 0, $length);

            // Check if short_id already exists in database
            $existing = $this->databaseService->fetchOne('SELECT id FROM runs WHERE short_id = ?', [$shortId]);
            if ($existing === null) {
                return $shortId;
            }

            $attempts++;
        }

        throw new RuntimeException(
            sprintf('Failed to generate unique run short_id after %d attempts. This is extremely unlikely.', $maxAttempts)
        );
    }

    /**
     * Get aggregated statistics for all runs.
     *
     * @return array{
     *     total_runs: int,
     *     by_status: array{running: int, completed: int, failed: int},
     *     by_agent: array<string, int>,
     *     by_model: array<string, int>
     * }
     */
    public function getStats(): array
    {
        // Get all runs with their agent, model, and status
        $runs = $this->databaseService->fetchAll(
            'SELECT agent, model, status FROM runs'
        );

        $totalRuns = count($runs);
        $byStatus = [
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
        ];
        $byAgent = [];
        $byModel = [];

        foreach ($runs as $run) {
            // Count by status
            $status = $run['status'] ?? self::STATUS_RUNNING;
            if (isset($byStatus[$status])) {
                $byStatus[$status]++;
            }

            // Count by agent (skip if agent is null)
            if ($run['agent'] !== null) {
                $agent = (string) $run['agent'];
                $byAgent[$agent] = ($byAgent[$agent] ?? 0) + 1;
            }

            // Count by model (skip if model is null)
            if ($run['model'] !== null) {
                $model = (string) $run['model'];
                $byModel[$model] = ($byModel[$model] ?? 0) + 1;
            }
        }

        // Sort by count descending
        arsort($byAgent);
        arsort($byModel);

        return [
            'total_runs' => $totalRuns,
            'by_status' => $byStatus,
            'by_agent' => $byAgent,
            'by_model' => $byModel,
        ];
    }

    /**
     * Get timing statistics for runs.
     *
     * @return array{
     *     average_duration: float|null,
     *     by_agent: array<string, float>,
     *     by_model: array<string, float>,
     *     longest_run: int|null,
     *     shortest_run: int|null
     * }
     */
    public function getTimingStats(): array
    {
        // Get average duration overall
        $avgOverall = $this->databaseService->fetchOne(
            'SELECT AVG(duration_seconds) as avg_duration FROM runs WHERE duration_seconds IS NOT NULL'
        );

        // Get average duration by agent
        $byAgent = $this->databaseService->fetchAll(
            'SELECT agent, AVG(duration_seconds) as avg_duration
             FROM runs
             WHERE duration_seconds IS NOT NULL AND agent IS NOT NULL
             GROUP BY agent'
        );

        // Get average duration by model
        $byModel = $this->databaseService->fetchAll(
            'SELECT model, AVG(duration_seconds) as avg_duration
             FROM runs
             WHERE duration_seconds IS NOT NULL AND model IS NOT NULL
             GROUP BY model'
        );

        // Get longest run
        $longest = $this->databaseService->fetchOne(
            'SELECT MAX(duration_seconds) as max_duration FROM runs WHERE duration_seconds IS NOT NULL'
        );

        // Get shortest completed run
        $shortest = $this->databaseService->fetchOne(
            'SELECT MIN(duration_seconds) as min_duration FROM runs WHERE duration_seconds IS NOT NULL AND duration_seconds > 0'
        );

        // Transform byAgent to associative array
        $byAgentMap = [];
        foreach ($byAgent as $row) {
            $byAgentMap[(string) $row['agent']] = (float) $row['avg_duration'];
        }

        // Transform byModel to associative array
        $byModelMap = [];
        foreach ($byModel as $row) {
            $byModelMap[(string) $row['model']] = (float) $row['avg_duration'];
        }

        return [
            'average_duration' => $avgOverall['avg_duration'] !== null ? (float) $avgOverall['avg_duration'] : null,
            'by_agent' => $byAgentMap,
            'by_model' => $byModelMap,
            'longest_run' => $longest['max_duration'] !== null ? (int) $longest['max_duration'] : null,
            'shortest_run' => $shortest['min_duration'] !== null ? (int) $shortest['min_duration'] : null,
        ];
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
