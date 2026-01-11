<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Run;
use App\Repositories\RunRepository;
use App\Repositories\TaskRepository;
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
        private readonly RunRepository $runRepository,
        private readonly TaskRepository $taskRepository
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
        $this->runRepository->insert([
            'short_id' => $shortId,
            'task_id' => $taskIntId,
            'agent' => $data['agent'] ?? null,
            'model' => $data['model'] ?? null,
            'started_at' => $data['started_at'] ?? now()->toIso8601String(),
            'ended_at' => null,
            'exit_code' => null,
            'output' => null,
            'session_id' => $data['session_id'] ?? null,
            'cost_usd' => $data['cost_usd'] ?? null,
            'status' => self::STATUS_RUNNING,
            'duration_seconds' => null,
        ]);

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
        $this->runRepository->insert([
            'short_id' => $shortId,
            'task_id' => $taskIntId,
            'agent' => $data['agent'] ?? null,
            'model' => $data['model'] ?? null,
            'started_at' => $data['started_at'] ?? now()->toIso8601String(),
            'ended_at' => $data['ended_at'] ?? null,
            'exit_code' => $data['exit_code'] ?? null,
            'output' => $output,
            'session_id' => $data['session_id'] ?? null,
            'cost_usd' => $data['cost_usd'] ?? null,
            'status' => self::STATUS_RUNNING,
            'duration_seconds' => $durationSeconds,
        ]);
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

        $runs = $this->runRepository->findByTaskId($taskIntId);

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

        $run = $this->runRepository->getLatestForTask($taskIntId);

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
        $latestRun = $this->runRepository->getLatestForTask($taskIntId);

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

        if (isset($data['ended_at'])) {
            $updateFields['ended_at'] = $data['ended_at'];
            // Also update status to completed when ended_at is set
            $updateFields['status'] = self::STATUS_COMPLETED;

            // Calculate and set duration_seconds
            if ($latestRun['started_at'] !== null) {
                $start = strtotime($latestRun['started_at']);
                $end = strtotime((string) $data['ended_at']);
                if ($start !== false && $end !== false) {
                    $updateFields['duration_seconds'] = $end - $start;
                }
            }
        }

        if (isset($data['exit_code'])) {
            $updateFields['exit_code'] = $data['exit_code'];
        }

        if (array_key_exists('output', $data)) {
            $updateFields['output'] = $output;
        }

        if (isset($data['session_id'])) {
            $updateFields['session_id'] = $data['session_id'];
        }

        if (isset($data['cost_usd'])) {
            $updateFields['cost_usd'] = $data['cost_usd'];
        }

        if ($updateFields === []) {
            return; // Nothing to update
        }

        $this->runRepository->update((int) $latestRun['id'], $updateFields);
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
        $orphanedRuns = $this->runRepository->getOrphanedRuns();

        if ($orphanedRuns === []) {
            return 0;
        }

        // Mark each orphaned run as failed
        $endedAt = date('c');
        $cleanupCount = 0;

        foreach ($orphanedRuns as $run) {
            $this->runRepository->markAsFailed(
                (int) $run['id'],
                $endedAt,
                -1,
                '[Run orphaned - consume process died before completion]'
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
            if (! $this->runRepository->existsByShortId($shortId)) {
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
        $totalRuns = $this->runRepository->count();
        $byStatus = $this->runRepository->countByStatus();
        $byAgent = $this->runRepository->countByAgent();
        $byModel = $this->runRepository->countByModel();

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
        $avgOverall = $this->runRepository->getAverageDuration();
        $byAgentMap = $this->runRepository->getAverageDurationByAgent();
        $byModelMap = $this->runRepository->getAverageDurationByModel();
        $longest = $this->runRepository->getMaxDuration();
        $shortest = $this->runRepository->getMinDuration();

        return [
            'average_duration' => $avgOverall,
            'by_agent' => $byAgentMap,
            'by_model' => $byModelMap,
            'longest_run' => $longest,
            'shortest_run' => $shortest,
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
        return $this->taskRepository->resolveToIntegerId($taskShortId);
    }
}
