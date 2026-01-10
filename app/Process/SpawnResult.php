<?php

declare(strict_types=1);

namespace App\Process;

/**
 * Result of a spawn attempt.
 */
final readonly class SpawnResult
{
    private function __construct(
        public bool $success,
        public ?AgentProcess $process = null,
        public ?string $error = null,
        public SpawnFailureReason $reason = SpawnFailureReason::None,
    ) {}

    public static function success(AgentProcess $process): self
    {
        return new self(success: true, process: $process);
    }

    public static function atCapacity(string $agentName): self
    {
        return new self(
            success: false,
            error: sprintf("Agent '%s' is at maximum capacity", $agentName),
            reason: SpawnFailureReason::AtCapacity,
        );
    }

    public static function agentNotFound(string $agentCommand): self
    {
        return new self(
            success: false,
            error: 'Agent command not found: '.$agentCommand,
            reason: SpawnFailureReason::AgentNotFound,
        );
    }

    public static function spawnFailed(string $taskId): self
    {
        return new self(
            success: false,
            error: 'Failed to spawn agent for '.$taskId,
            reason: SpawnFailureReason::SpawnFailed,
        );
    }

    public static function configError(string $message): self
    {
        return new self(
            success: false,
            error: $message,
            reason: SpawnFailureReason::ConfigError,
        );
    }

    public static function agentInBackoff(string $agentName, int $remainingSeconds): self
    {
        $formatted = $remainingSeconds < 60
            ? "{$remainingSeconds}s"
            : sprintf('%dm %ds', (int) ($remainingSeconds / 60), $remainingSeconds % 60);

        return new self(
            success: false,
            error: sprintf("Agent '%s' is in backoff for %s", $agentName, $formatted),
            reason: SpawnFailureReason::AgentInBackoff,
        );
    }

    public function isAtCapacity(): bool
    {
        return $this->reason === SpawnFailureReason::AtCapacity;
    }

    public function isInBackoff(): bool
    {
        return $this->reason === SpawnFailureReason::AgentInBackoff;
    }
}
