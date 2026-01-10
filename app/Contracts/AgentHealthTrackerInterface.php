<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\FailureType;
use App\Process\AgentHealth;

/**
 * Contract for tracking agent health and managing backoff.
 */
interface AgentHealthTrackerInterface
{
    /**
     * Record a successful run for an agent.
     * Resets consecutive failures and updates success metrics.
     *
     * @param  string  $agent  The agent name
     */
    public function recordSuccess(string $agent): void;

    /**
     * Record a failed run for an agent.
     * Increments consecutive failures and may trigger backoff.
     *
     * @param  string  $agent  The agent name
     * @param  FailureType  $failureType  The type of failure that occurred
     */
    public function recordFailure(string $agent, FailureType $failureType): void;

    /**
     * Check if an agent is available (not in backoff).
     *
     * @param  string  $agent  The agent name
     * @return bool True if the agent is available, false if in backoff
     */
    public function isAvailable(string $agent): bool;

    /**
     * Get the remaining backoff time in seconds for an agent.
     * Returns 0 if the agent is not in backoff.
     *
     * @param  string  $agent  The agent name
     * @return int Remaining backoff seconds
     */
    public function getBackoffSeconds(string $agent): int;

    /**
     * Get the full health status for an agent.
     *
     * @param  string  $agent  The agent name
     * @return AgentHealth The agent's health status
     */
    public function getHealthStatus(string $agent): AgentHealth;

    /**
     * Get health status for all tracked agents.
     *
     * @return array<AgentHealth> Array of health status objects
     */
    public function getAllHealthStatus(): array;

    /**
     * Clear all health data for an agent.
     * Useful for manual recovery.
     *
     * @param  string  $agent  The agent name
     */
    public function clearHealth(string $agent): void;
}
