<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * @deprecated Use AgentDriverRegistry instead. This enum will be removed in a future version.
 */
enum Agent: string
{
    case Claude = 'claude';
    case CursorAgent = 'cursor-agent';
    case OpenCode = 'opencode';
    case Amp = 'amp';

    /**
     * Get the command to resume a session interactively.
     */
    public function resumeCommand(string $sessionId): string
    {
        $escapedSessionId = escapeshellarg($sessionId);

        return match ($this) {
            self::Claude => 'claude --resume '.$escapedSessionId,
            self::CursorAgent => 'cursor-agent --resume='.$escapedSessionId,
            self::OpenCode => 'opencode --session '.$escapedSessionId,
            self::Amp => 'amp threads continue '.$escapedSessionId,
        };
    }

    /**
     * Get the command to resume with a prompt (headless).
     */
    public function resumeWithPromptCommand(string $sessionId, string $prompt): string
    {
        $escapedSessionId = escapeshellarg($sessionId);
        $escapedPrompt = escapeshellarg($prompt);

        return match ($this) {
            self::Claude => sprintf('claude --resume %s -p %s', $escapedSessionId, $escapedPrompt),
            self::CursorAgent => sprintf('cursor-agent --resume %s -p %s', $escapedSessionId, $escapedPrompt),
            self::OpenCode => sprintf('opencode run %s --session %s', $escapedPrompt, $escapedSessionId),
            self::Amp => sprintf('amp threads continue %s --execute %s', $escapedSessionId, $escapedPrompt),
        };
    }

    /**
     * Get human-friendly label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Claude => 'Claude',
            self::CursorAgent => 'Cursor Agent',
            self::OpenCode => 'OpenCode',
            self::Amp => 'Amp',
        };
    }

    /**
     * Get the binary name for this agent.
     */
    public function binary(): string
    {
        return match ($this) {
            self::Claude => 'claude',
            self::CursorAgent => 'cursor-agent',
            self::OpenCode => 'opencode',
            self::Amp => 'amp',
        };
    }

    /**
     * Get arguments for resuming a session interactively (for pcntl_exec).
     *
     * @return array<string>
     */
    public function resumeArgs(string $sessionId): array
    {
        return match ($this) {
            self::Claude => ['--resume', $sessionId],
            self::CursorAgent => ['--resume='.$sessionId],
            self::OpenCode => ['--session', $sessionId],
            self::Amp => ['threads', 'continue', $sessionId],
        };
    }

    /**
     * Parse agent name from string (e.g., from run data).
     * Tries to match against known agent binaries.
     *
     * @return self|null Returns null if the agent name is unknown or null
     */
    public static function fromString(?string $name): ?self
    {
        if ($name === null) {
            return null;
        }

        // Match against binary names
        return match ($name) {
            'claude' => self::Claude,
            'cursor-agent' => self::CursorAgent,
            'opencode' => self::OpenCode,
            'amp' => self::Amp,
            default => null,
        };
    }

    /**
     * Try to determine the agent type from an agent name that might be a custom config name.
     * Falls back to matching the command binary.
     *
     * @param  string  $agentName  The agent name (could be 'claude-sonnet', 'opencode-glm', etc.)
     * @param  string|null  $command  The command binary if known
     * @return self|null Returns null if the agent cannot be determined
     */
    public static function fromAgentName(string $agentName, ?string $command = null): ?self
    {
        // First try direct match
        $direct = self::fromString($agentName);
        if ($direct instanceof \App\Enums\Agent) {
            return $direct;
        }

        // Try matching the command
        if ($command !== null) {
            return self::fromString($command);
        }

        // Try matching common patterns in agent name
        if (str_contains($agentName, 'claude')) {
            return self::Claude;
        }

        if (str_contains($agentName, 'cursor')) {
            return self::CursorAgent;
        }

        if (str_contains($agentName, 'opencode')) {
            return self::OpenCode;
        }

        if (str_contains($agentName, 'amp')) {
            return self::Amp;
        }

        return null;
    }
}
