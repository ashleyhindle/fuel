<?php

declare(strict_types=1);

namespace App\Repositories;

class RunRepository extends BaseRepository
{
    protected function getTable(): string
    {
        return 'runs';
    }

    /**
     * Get all runs for a specific task.
     */
    public function findByTaskId(int $taskId): array
    {
        return $this->fetchAll(
            'SELECT * FROM runs WHERE task_id = ? ORDER BY started_at ASC',
            [$taskId]
        );
    }

    /**
     * Get the latest run for a task.
     */
    public function getLatestForTask(int $taskId): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM runs WHERE task_id = ? ORDER BY started_at DESC, id DESC LIMIT 1',
            [$taskId]
        );
    }

    /**
     * Get all runs by agent.
     */
    public function findByAgent(string $agent): array
    {
        return $this->fetchAll(
            'SELECT * FROM runs WHERE agent = ? ORDER BY started_at DESC',
            [$agent]
        );
    }

    /**
     * Get all runs by status.
     */
    public function findByStatus(string $status): array
    {
        return $this->fetchAll(
            'SELECT * FROM runs WHERE status = ? ORDER BY started_at DESC',
            [$status]
        );
    }

    /**
     * Get all running runs.
     */
    public function getRunningRuns(): array
    {
        return $this->findByStatus('running');
    }

    /**
     * Get all completed runs.
     */
    public function getCompletedRuns(): array
    {
        return $this->findByStatus('completed');
    }

    /**
     * Get all failed runs.
     */
    public function getFailedRuns(): array
    {
        return $this->findByStatus('failed');
    }

    /**
     * Get orphaned runs (running status with no ended_at).
     */
    public function getOrphanedRuns(): array
    {
        return $this->fetchAll(
            'SELECT * FROM runs WHERE status = ? AND ended_at IS NULL',
            ['running']
        );
    }

    /**
     * Mark a run as failed.
     */
    public function markAsFailed(int $runId, string $endedAt, int $exitCode = -1, string $output = '[Run orphaned]'): void
    {
        $this->update($runId, [
            'status' => 'failed',
            'ended_at' => $endedAt,
            'exit_code' => $exitCode,
            'output' => $output,
        ]);
    }

    /**
     * Mark a run as completed.
     */
    public function markAsCompleted(int $runId, array $data): void
    {
        $updates = ['status' => 'completed'];

        if (isset($data['ended_at'])) {
            $updates['ended_at'] = $data['ended_at'];
        }

        if (isset($data['exit_code'])) {
            $updates['exit_code'] = $data['exit_code'];
        }

        if (isset($data['output'])) {
            $updates['output'] = $data['output'];
        }

        if (isset($data['duration_seconds'])) {
            $updates['duration_seconds'] = $data['duration_seconds'];
        }

        if (isset($data['cost_usd'])) {
            $updates['cost_usd'] = $data['cost_usd'];
        }

        $this->update($runId, $updates);
    }

    /**
     * Get all run short IDs (for ID generation collision detection).
     */
    public function getAllShortIds(): array
    {
        $rows = $this->fetchAll('SELECT short_id FROM runs');
        return array_column($rows, 'short_id');
    }

    /**
     * Get runs by model.
     */
    public function findByModel(string $model): array
    {
        return $this->fetchAll(
            'SELECT * FROM runs WHERE model = ? ORDER BY started_at DESC',
            [$model]
        );
    }

    /**
     * Get runs within a date range.
     */
    public function findByDateRange(string $startDate, string $endDate): array
    {
        return $this->fetchAll(
            'SELECT * FROM runs WHERE started_at >= ? AND started_at <= ? ORDER BY started_at DESC',
            [$startDate, $endDate]
        );
    }

    /**
     * Get average duration for runs.
     */
    public function getAverageDuration(): ?float
    {
        $result = $this->fetchOne(
            'SELECT AVG(duration_seconds) as avg_duration FROM runs WHERE duration_seconds IS NOT NULL'
        );

        return $result !== null && $result['avg_duration'] !== null ? (float) $result['avg_duration'] : null;
    }

    /**
     * Get average duration by agent.
     */
    public function getAverageDurationByAgent(): array
    {
        $rows = $this->fetchAll(
            'SELECT agent, AVG(duration_seconds) as avg_duration
             FROM runs
             WHERE duration_seconds IS NOT NULL AND agent IS NOT NULL
             GROUP BY agent'
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['agent']] = (float) $row['avg_duration'];
        }

        return $result;
    }

    /**
     * Get average duration by model.
     */
    public function getAverageDurationByModel(): array
    {
        $rows = $this->fetchAll(
            'SELECT model, AVG(duration_seconds) as avg_duration
             FROM runs
             WHERE duration_seconds IS NOT NULL AND model IS NOT NULL
             GROUP BY model'
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['model']] = (float) $row['avg_duration'];
        }

        return $result;
    }

    /**
     * Get the longest run duration.
     */
    public function getMaxDuration(): ?int
    {
        $result = $this->fetchOne(
            'SELECT MAX(duration_seconds) as max_duration FROM runs WHERE duration_seconds IS NOT NULL'
        );

        return $result !== null && $result['max_duration'] !== null ? (int) $result['max_duration'] : null;
    }

    /**
     * Get the shortest run duration.
     */
    public function getMinDuration(): ?int
    {
        $result = $this->fetchOne(
            'SELECT MIN(duration_seconds) as min_duration FROM runs WHERE duration_seconds IS NOT NULL AND duration_seconds > 0'
        );

        return $result !== null && $result['min_duration'] !== null ? (int) $result['min_duration'] : null;
    }

    /**
     * Count runs by status.
     */
    public function countByStatus(): array
    {
        $rows = $this->fetchAll(
            'SELECT status, COUNT(*) as count FROM runs GROUP BY status'
        );

        $result = [
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        foreach ($rows as $row) {
            $status = $row['status'] ?? 'running';
            if (isset($result[$status])) {
                $result[$status] = (int) $row['count'];
            }
        }

        return $result;
    }

    /**
     * Count runs by agent.
     */
    public function countByAgent(): array
    {
        $rows = $this->fetchAll(
            'SELECT agent, COUNT(*) as count FROM runs WHERE agent IS NOT NULL GROUP BY agent ORDER BY count DESC'
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['agent']] = (int) $row['count'];
        }

        return $result;
    }

    /**
     * Count runs by model.
     */
    public function countByModel(): array
    {
        $rows = $this->fetchAll(
            'SELECT model, COUNT(*) as count FROM runs WHERE model IS NOT NULL GROUP BY model ORDER BY count DESC'
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['model']] = (int) $row['count'];
        }

        return $result;
    }

    /**
     * Resolve a task short ID to its integer ID.
     */
    public function resolveTaskId(string $taskShortId): ?int
    {
        $result = $this->fetchOne('SELECT id FROM tasks WHERE short_id = ?', [$taskShortId]);

        return $result !== null ? (int) $result['id'] : null;
    }
}