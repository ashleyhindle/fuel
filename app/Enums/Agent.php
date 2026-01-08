<?php

declare(strict_types=1);

namespace App\Enums;

enum Agent: string
{
    case Claude = 'claude';
    case CursorAgent = 'cursor-agent';

    /**
     * Get the command to resume a session interactively.
     * Note: For cursor-agent, sessions created with --output-format require -p flag.
     * We provide a minimal prompt to satisfy the requirement while avoiding config.yaml args.
     */
    public function resumeCommand(string $sessionId): string
    {
        $escapedSessionId = escapeshellarg($sessionId);

        return match ($this) {
            self::Claude => "claude --resume {$escapedSessionId}",
            // cursor-agent requires -p when session was created with --output-format
            // We provide a minimal prompt to satisfy the requirement
            self::CursorAgent => "cursor-agent --resume {$escapedSessionId} -p ".escapeshellarg('continue'),
        };
    }

    /**
     * Get the command to resume with a prompt (headless).
     * Note: For cursor-agent, we use space format (not =) for consistency.
     */
    public function resumeWithPromptCommand(string $sessionId, string $prompt): string
    {
        $escapedSessionId = escapeshellarg($sessionId);
        $escapedPrompt = escapeshellarg($prompt);

        return match ($this) {
            self::Claude => "claude --resume {$escapedSessionId} -p {$escapedPrompt}",
            self::CursorAgent => "cursor-agent --resume {$escapedSessionId} -p {$escapedPrompt}",
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
