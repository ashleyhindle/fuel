<?php

declare(strict_types=1);

namespace App\Process;

/**
 * Result of a spawn attempt.
 */
final class SpawnResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?AgentProcess $process = null,
        public readonly ?string $error = null,
        public readonly SpawnFailureReason $reason = SpawnFailureReason::None,
    ) {}

    public static function success(AgentProcess $process): self
    {
        return new self(success: true, process: $process);
    }

    public static function atCapacity(string $agentName): self
    {
        return new self(
            success: false,
            error: "Agent '{$agentName}' is at maximum capacity",
            reason: SpawnFailureReason::AtCapacity,
        );
    }

    public static function agentNotFound(string $agentCommand): self
    {
        return new self(
            success: false,
            error: "Agent command not found: {$agentCommand}",
            reason: SpawnFailureReason::AgentNotFound,
        );
    }

    public static function spawnFailed(string $taskId): self
    {
        return new self(
            success: false,
            error: "Failed to spawn agent for {$taskId}",
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

    public function isAtCapacity(): bool
    {
        return $this->reason === SpawnFailureReason::AtCapacity;
    }
}
