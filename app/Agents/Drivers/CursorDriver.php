<?php

declare(strict_types=1);

namespace App\Agents\Drivers;

/**
 * Driver for Cursor Agent.
 *
 * Command: cursor-agent
 * Default args: --force --output-format stream-json
 * Prompt args: -p
 * Resume: --resume=
 * Model arg: --model
 */
class CursorDriver implements AgentDriverInterface
{
    public function getName(): string
    {
        return 'cursor-agent';
    }

    public function getLabel(): string
    {
        return 'Cursor Agent';
    }

    public function getCommand(): string
    {
        return 'cursor-agent';
    }

    public function getDefaultArgs(): array
    {
        return ['--force', '--output-format', 'stream-json'];
    }

    public function getPromptArgs(): array
    {
        return ['-p'];
    }

    public function getDefaultEnv(): array
    {
        return [];
    }

    public function getModelArg(): ?string
    {
        return '--model';
    }

    public function supportsResume(): bool
    {
        return true;
    }

    public function getResumeArgs(string $sessionId): array
    {
        return ['--resume='.$sessionId];
    }

    public function getResumeCommand(string $sessionId): string
    {
        $escapedSessionId = escapeshellarg($sessionId);

        return sprintf('cursor-agent --resume=%s', $escapedSessionId);
    }

    public function getResumeWithPromptCommand(string $sessionId, string $prompt): string
    {
        $escapedSessionId = escapeshellarg($sessionId);
        $escapedPrompt = escapeshellarg($prompt);

        return sprintf('cursor-agent --resume=%s -p %s', $escapedSessionId, $escapedPrompt);
    }
}
