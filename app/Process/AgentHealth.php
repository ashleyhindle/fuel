<?php

declare(strict_types=1);

namespace App\Process;

use DateTimeImmutable;

/**
 * Value object representing the health status of an agent.
 */
final readonly class AgentHealth
{
    public function __construct(
        public string $agent,
        public ?DateTimeImmutable $lastSuccessAt,
        public ?DateTimeImmutable $lastFailureAt,
        public int $consecutiveFailures,
        public ?DateTimeImmutable $backoffUntil,
        public int $totalRuns,
        public int $totalSuccesses,
    ) {}

    /**
     * Check if the agent is currently available (not in backoff).
     */
    public function isAvailable(): bool
    {
        if ($this->backoffUntil === null) {
            return true;
        }

        return $this->backoffUntil < new DateTimeImmutable;
    }

    /**
     * Get the remaining backoff time in seconds.
     * Returns 0 if not in backoff.
     */
    public function getBackoffSeconds(): int
    {
        if ($this->backoffUntil === null) {
            return 0;
        }

        $now = new DateTimeImmutable;
        if ($this->backoffUntil <= $now) {
            return 0;
        }

        return $this->backoffUntil->getTimestamp() - $now->getTimestamp();
    }

    /**
     * Get the health status as a simple label.
     */
    public function getStatus(): string
    {
        if ($this->consecutiveFailures === 0) {
            return 'healthy';
        }

        if ($this->consecutiveFailures >= 5) {
            return 'unhealthy';
        }

        if ($this->consecutiveFailures >= 2) {
            return 'degraded';
        }

        return 'warning';
    }

    /**
     * Get the success rate as a percentage.
     * Returns null if no runs have been recorded.
     */
    public function getSuccessRate(): ?float
    {
        if ($this->totalRuns === 0) {
            return null;
        }

        return ($this->totalSuccesses / $this->totalRuns) * 100;
    }

    /**
     * Get total failures.
     */
    public function getTotalFailures(): int
    {
        return $this->totalRuns - $this->totalSuccesses;
    }

    /**
     * Create a new AgentHealth with no history (for new agents).
     */
    public static function forNewAgent(string $agent): self
    {
        return new self(
            agent: $agent,
            lastSuccessAt: null,
            lastFailureAt: null,
            consecutiveFailures: 0,
            backoffUntil: null,
            totalRuns: 0,
            totalSuccesses: 0,
        );
    }

    /**
     * Create AgentHealth from a database row.
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            agent: $row['agent'],
            lastSuccessAt: $row['last_success_at'] !== null ? new DateTimeImmutable($row['last_success_at']) : null,
            lastFailureAt: $row['last_failure_at'] !== null ? new DateTimeImmutable($row['last_failure_at']) : null,
            consecutiveFailures: (int) $row['consecutive_failures'],
            backoffUntil: $row['backoff_until'] !== null ? new DateTimeImmutable($row['backoff_until']) : null,
            totalRuns: (int) $row['total_runs'],
            totalSuccesses: (int) $row['total_successes'],
        );
    }
}
