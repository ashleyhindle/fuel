<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AgentHealthTrackerInterface;
use App\Enums\FailureType;
use App\Process\AgentHealth;
use DateTimeImmutable;

/**
 * Tracks agent health and manages exponential backoff for failures.
 * Uses SQLite database for persistence.
 */
class AgentHealthTracker implements AgentHealthTrackerInterface
{
    /**
     * Backoff durations in seconds for consecutive failures.
     * Array index corresponds to (consecutive_failures - 1).
     */
    private const array BACKOFF_SECONDS = [
        30,   // 1st failure: 30 seconds
        60,   // 2nd failure: 1 minute
        120,  // 3rd failure: 2 minutes
        240,  // 4th failure: 4 minutes
        480,  // 5th+ failure: 8 minutes
    ];

    public function __construct(
        private readonly DatabaseService $database,
    ) {}

    /**
     * Record a successful run for an agent.
     */
    public function recordSuccess(string $agent): void
    {
        $now = (new DateTimeImmutable)->format('c');

        $this->database->beginTransaction();

        try {
            // Check if agent exists
            $existing = $this->database->fetchOne(
                'SELECT agent FROM agent_health WHERE agent = ?',
                [$agent]
            );

            if ($existing === null) {
                // Insert new agent
                $this->database->query(
                    'INSERT INTO agent_health (agent, last_success_at, consecutive_failures, backoff_until, total_runs, total_successes)
                     VALUES (?, ?, 0, NULL, 1, 1)',
                    [$agent, $now]
                );
            } else {
                // Update existing agent
                $this->database->query(
                    'UPDATE agent_health 
                     SET last_success_at = ?,
                         consecutive_failures = 0,
                         backoff_until = NULL,
                         total_runs = total_runs + 1,
                         total_successes = total_successes + 1
                     WHERE agent = ?',
                    [$now, $agent]
                );
            }

            $this->database->commit();
        } catch (\Exception $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    /**
     * Record a failed run for an agent.
     */
    public function recordFailure(string $agent, FailureType $failureType): void
    {
        $now = new DateTimeImmutable;
        $nowStr = $now->format('c');

        $this->database->beginTransaction();

        try {
            // Get current state
            $existing = $this->database->fetchOne(
                'SELECT consecutive_failures FROM agent_health WHERE agent = ?',
                [$agent]
            );

            $consecutiveFailures = $existing !== null ? (int) $existing['consecutive_failures'] + 1 : 1;

            // Calculate backoff
            $backoffUntil = $this->calculateBackoff($now, $consecutiveFailures, $failureType);
            $backoffUntilStr = $backoffUntil?->format('c');

            if ($existing === null) {
                // Insert new agent
                $this->database->query(
                    'INSERT INTO agent_health (agent, last_failure_at, consecutive_failures, backoff_until, total_runs, total_successes)
                     VALUES (?, ?, ?, ?, 1, 0)',
                    [$agent, $nowStr, $consecutiveFailures, $backoffUntilStr]
                );
            } else {
                // Update existing agent
                $this->database->query(
                    'UPDATE agent_health 
                     SET last_failure_at = ?,
                         consecutive_failures = ?,
                         backoff_until = ?,
                         total_runs = total_runs + 1
                     WHERE agent = ?',
                    [$nowStr, $consecutiveFailures, $backoffUntilStr, $agent]
                );
            }

            $this->database->commit();
        } catch (\Exception $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    /**
     * Check if an agent is available (not in backoff).
     */
    public function isAvailable(string $agent): bool
    {
        $row = $this->database->fetchOne(
            'SELECT backoff_until FROM agent_health WHERE agent = ?',
            [$agent]
        );

        if ($row === null || $row['backoff_until'] === null) {
            return true;
        }

        $backoffUntil = new DateTimeImmutable($row['backoff_until']);

        return $backoffUntil <= new DateTimeImmutable;
    }

    /**
     * Get the remaining backoff time in seconds for an agent.
     */
    public function getBackoffSeconds(string $agent): int
    {
        $row = $this->database->fetchOne(
            'SELECT backoff_until FROM agent_health WHERE agent = ?',
            [$agent]
        );

        if ($row === null || $row['backoff_until'] === null) {
            return 0;
        }

        $backoffUntil = new DateTimeImmutable($row['backoff_until']);
        $now = new DateTimeImmutable;

        if ($backoffUntil <= $now) {
            return 0;
        }

        return $backoffUntil->getTimestamp() - $now->getTimestamp();
    }

    /**
     * Get the full health status for an agent.
     */
    public function getHealthStatus(string $agent): AgentHealth
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM agent_health WHERE agent = ?',
            [$agent]
        );

        if ($row === null) {
            return AgentHealth::forNewAgent($agent);
        }

        return AgentHealth::fromDatabaseRow($row);
    }

    /**
     * Get health status for all tracked agents.
     *
     * @return array<AgentHealth>
     */
    public function getAllHealthStatus(): array
    {
        $rows = $this->database->fetchAll('SELECT * FROM agent_health ORDER BY agent');

        return array_map(
            AgentHealth::fromDatabaseRow(...),
            $rows
        );
    }

    /**
     * Clear all health data for an agent.
     */
    public function clearHealth(string $agent): void
    {
        $this->database->query(
            'DELETE FROM agent_health WHERE agent = ?',
            [$agent]
        );
    }

    /**
     * Check if an agent is dead (exceeded max_retries consecutive failures).
     */
    public function isDead(string $agent, int $maxRetries): bool
    {
        $row = $this->database->fetchOne(
            'SELECT consecutive_failures FROM agent_health WHERE agent = ?',
            [$agent]
        );

        if ($row === null) {
            return false;
        }

        return (int) $row['consecutive_failures'] >= $maxRetries;
    }

    /**
     * Calculate the backoff time based on consecutive failures.
     */
    private function calculateBackoff(
        DateTimeImmutable $now,
        int $consecutiveFailures,
        FailureType $failureType
    ): ?DateTimeImmutable {
        // Permission failures don't get backoff - they need human intervention
        if ($failureType === FailureType::Permission) {
            return null;
        }

        // Get backoff duration based on consecutive failures
        $index = min($consecutiveFailures - 1, count(self::BACKOFF_SECONDS) - 1);
        $backoffSeconds = self::BACKOFF_SECONDS[$index];

        return $now->modify(sprintf('+%s seconds', $backoffSeconds));
    }
}
