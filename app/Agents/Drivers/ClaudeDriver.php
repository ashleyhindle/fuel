<?php

declare(strict_types=1);

namespace App\Agents\Drivers;

/**
 * Driver for Claude agent.
 * Encapsulates Claude-specific command arguments and configuration.
 */
class ClaudeDriver implements AgentDriverInterface
{
    /**
     * Get the unique identifier/name for this agent driver.
     */
    public function getName(): string
    {
        return 'claude';
    }

    /**
     * Get a human-friendly label for this agent.
     */
    public function getLabel(): string
    {
        return 'Claude';
    }

    /**
     * Get the command/binary name for this agent.
     */
    public function getCommand(): string
    {
        return 'claude';
    }

    /**
     * Get the default arguments array for this agent.
     * Default args: --dangerously-skip-permissions --output-format stream-json --verbose
     */
    public function getDefaultArgs(): array
    {
        return [
            '--dangerously-skip-permissions',
            '--output-format',
            'stream-json',
            '--verbose',
        ];
    }

    /**
     * Get the prompt arguments array for this agent.
     * Prompt args: -p
     */
    public function getPromptArgs(): array
    {
        return ['-p'];
    }

    /**
     * Get the default environment variables for this agent.
     */
    public function getDefaultEnv(): array
    {
        return [];
    }

    /**
     * Get the model argument for this agent.
     * Model arg: --model
     */
    public function getModelArg(): ?string
    {
        return '--model';
    }

    /**
     * Check if this agent supports resuming sessions.
     */
    public function supportsResume(): bool
    {
        return true;
    }

    /**
     * Get the arguments array for resuming a session interactively.
     */
    public function getResumeArgs(string $sessionId): array
    {
        return ['--resume', $sessionId];
    }

    /**
     * Get the full command string to resume a session interactively.
     */
    public function getResumeCommand(string $sessionId): string
    {
        $escapedSessionId = escapeshellarg($sessionId);

        return sprintf('%s --resume %s', $this->getCommand(), $escapedSessionId);
    }

    /**
     * Get the full command string to resume a session with a prompt (headless mode).
     */
    public function getResumeWithPromptCommand(string $sessionId, string $prompt): string
    {
        $escapedSessionId = escapeshellarg($sessionId);
        $escapedPrompt = escapeshellarg($prompt);

        return sprintf('%s --resume %s -p %s', $this->getCommand(), $escapedSessionId, $escapedPrompt);
    }
}
