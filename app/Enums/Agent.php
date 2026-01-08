<?php

declare(strict_types=1);

namespace App\Enums;

enum Agent: string
{
    case Claude = 'claude';
    case CursorAgent = 'cursor-agent';

    /**
     * Get the command to resume a session interactively.
     */
    public function resumeCommand(string $sessionId): string
    {
        $escapedSessionId = escapeshellarg($sessionId);

        return match ($this) {
            self::Claude => "claude --resume {$escapedSessionId}",
            self::CursorAgent => "cursor-agent --resume={$sessionId}",
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
            self::Claude => "claude --resume {$escapedSessionId} -p {$escapedPrompt}",
            self::CursorAgent => "cursor-agent --resume={$sessionId} -p {$escapedPrompt}",
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
        };
    }

    /**
     * Parse agent name from string (e.g., from run data).
     *
     * @return self|null Returns null if the agent name is unknown or null
     */
    public static function fromString(?string $name): ?self
    {
        if ($name === null) {
            return null;
        }

        return match ($name) {
            'claude' => self::Claude,
            'cursor-agent' => self::CursorAgent,
            default => null,
        };
    }
}
