<?php

declare(strict_types=1);

namespace App\Agents\Drivers;

/**
 * Driver for OpenCode agent.
 *
 * Command: opencode
 * Prompt via 'run' subcommand
 * Env: OPENCODE_PERMISSION
 * Resume: --session
 * Model is part of model string (opencode/model-name)
 */
class OpenCodeDriver implements AgentDriverInterface
{
    /**
     * Get the unique identifier/name for this agent driver.
     */
    public function getName(): string
    {
        return 'opencode';
    }

    /**
     * Get a human-friendly label for this agent.
     */
    public function getLabel(): string
    {
        return 'OpenCode';
    }

    /**
     * Get the command/binary name for this agent.
     */
    public function getCommand(): string
    {
        return 'opencode';
    }

    /**
     * Get the default arguments array for this agent.
     * OpenCode has no default arguments.
     */
    public function getDefaultArgs(): array
    {
        return [];
    }

    /**
     * Get the prompt arguments array for this agent.
     * Prompt via 'run' subcommand.
     */
    public function getPromptArgs(): array
    {
        return ['run'];
    }

    /**
     * Get the default environment variables for this agent.
     * Env: OPENCODE_PERMISSION
     */
    public function getDefaultEnv(): array
    {
        return [
            'OPENCODE_PERMISSION' => '{"permission":"allow"}',
        ];
    }

    /**
     * Get the model argument for this agent.
     * Model is part of model string (opencode/model-name).
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
     * Resume: --session
     */
    public function getResumeArgs(string $sessionId): array
    {
        return ['--session', $sessionId];
    }

    /**
     * Get the full command string to resume a session interactively.
     */
    public function getResumeCommand(string $sessionId): string
    {
        $escapedSessionId = escapeshellarg($sessionId);

        return sprintf('%s --session %s', $this->getCommand(), $escapedSessionId);
    }

    /**
     * Get the full command string to resume a session with a prompt (headless mode).
     * Format: opencode run {prompt} --session {sessionId}
     */
    public function getResumeWithPromptCommand(string $sessionId, string $prompt): string
    {
        $escapedSessionId = escapeshellarg($sessionId);
        $escapedPrompt = escapeshellarg($prompt);

        return sprintf('%s run %s --session %s', $this->getCommand(), $escapedPrompt, $escapedSessionId);
    }
}
