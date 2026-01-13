<?php

declare(strict_types=1);

namespace App\Agents\Drivers;

/**
 * Driver for the Codex agent.
 *
 * Codex uses a positional prompt (no prompt_args) and 'resume' as a subcommand.
 */
class CodexDriver implements AgentDriverInterface
{
    /**
     * Get the unique identifier/name for this agent driver.
     *
     * @return string The agent identifier
     */
    public function getName(): string
    {
        return 'codex';
    }

    /**
     * Get a human-friendly label for this agent.
     *
     * @return string The human-readable label
     */
    public function getLabel(): string
    {
        return 'Codex';
    }

    /**
     * Get the command/binary name for this agent.
     *
     * @return string The command/binary name
     */
    public function getCommand(): string
    {
        return 'codex';
    }

    /**
     * Get the default arguments array for this agent.
     *
     * @return array<string> Array of default argument strings
     */
    public function getDefaultArgs(): array
    {
        return [
            'exec',
            '--dangerously-bypass-approvals-and-sandbox',
            '--json',
            '--skip-git-repo-check',
            '--color=never',
        ];
    }

    /**
     * Get the prompt arguments array for this agent.
     * Codex uses positional prompts, so this returns an empty array.
     *
     * @return array<string> Array of prompt argument strings
     */
    public function getPromptArgs(): array
    {
        return [];
    }

    /**
     * Get the default environment variables for this agent.
     *
     * @return array<string, string> Associative array of environment variable name => value
     */
    public function getDefaultEnv(): array
    {
        return [];
    }

    /**
     * Get the model argument for this agent.
     * Codex doesn't use a model argument in the default args.
     *
     * @return string|null The model identifier, or null if not applicable
     */
    public function getModelArg(): ?string
    {
        return null;
    }

    /**
     * Check if this agent supports resuming sessions.
     *
     * @return bool True if resume is supported, false otherwise
     */
    public function supportsResume(): bool
    {
        return true;
    }

    /**
     * Get the arguments array for resuming a session interactively.
     * Codex uses 'resume' as a subcommand followed by the session ID.
     *
     * @param  string  $sessionId  The session ID to resume
     * @return array<string> Array of argument strings for resume command
     */
    public function getResumeArgs(string $sessionId): array
    {
        return ['resume', $sessionId];
    }

    /**
     * Get the full command string to resume a session interactively.
     *
     * @param  string  $sessionId  The session ID to resume
     * @return string The complete resume command string
     */
    public function getResumeCommand(string $sessionId): string
    {
        $escapedSessionId = escapeshellarg($sessionId);

        return sprintf('codex resume %s', $escapedSessionId);
    }

    /**
     * Get the full command string to resume a session with a prompt (headless mode).
     *
     * @param  string  $sessionId  The session ID to resume
     * @param  string  $prompt  The prompt to execute in the resumed session
     * @return string The complete resume-with-prompt command string
     */
    public function getResumeWithPromptCommand(string $sessionId, string $prompt): string
    {
        $escapedSessionId = escapeshellarg($sessionId);
        $escapedPrompt = escapeshellarg($prompt);

        return sprintf('codex resume %s %s', $escapedSessionId, $escapedPrompt);
    }
}
