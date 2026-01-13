<?php

declare(strict_types=1);

namespace App\Agents\Drivers;

/**
 * Driver for Amp agent.
 *
 * Command: amp
 * Default args: --stream-json --dangerously-allow-all --no-notifications
 * Prompt args: --execute
 * Resume: threads continue (subcommand)
 * Model arg: -m
 */
class AmpDriver implements AgentDriverInterface
{
    /**
     * Get the unique identifier/name for this agent driver.
     */
    public function getName(): string
    {
        return 'amp';
    }

    /**
     * Get a human-friendly label for this agent.
     */
    public function getLabel(): string
    {
        return 'Amp';
    }

    /**
     * Get the command/binary name for this agent.
     */
    public function getCommand(): string
    {
        return 'amp';
    }

    /**
     * Get the default arguments array for this agent.
     * Default args: --stream-json --dangerously-allow-all --no-notifications
     */
    public function getDefaultArgs(): array
    {
        return [
            '--stream-json',
            '--dangerously-allow-all',
            '--no-notifications',
        ];
    }

    /**
     * Get the prompt arguments array for this agent.
     * Prompt args: --execute
     */
    public function getPromptArgs(): array
    {
        return ['--execute'];
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
     * Mode (-m) controls model.
     */
    public function getModelArg(): ?string
    {
        return '-m';
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
     * Resume via 'threads continue' subcommand.
     */
    public function getResumeArgs(string $sessionId): array
    {
        return ['threads', 'continue', $sessionId];
    }

    /**
     * Get the full command string to resume a session interactively.
     */
    public function getResumeCommand(string $sessionId): string
    {
        $escapedSessionId = escapeshellarg($sessionId);

        return sprintf('amp threads continue %s', $escapedSessionId);
    }

    /**
     * Get the full command string to resume a session with a prompt (headless mode).
     */
    public function getResumeWithPromptCommand(string $sessionId, string $prompt): string
    {
        $escapedSessionId = escapeshellarg($sessionId);
        $escapedPrompt = escapeshellarg($prompt);

        return sprintf('amp threads continue %s --execute %s', $escapedSessionId, $escapedPrompt);
    }
}
