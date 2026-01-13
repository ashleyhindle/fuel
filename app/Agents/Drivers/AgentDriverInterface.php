<?php

declare(strict_types=1);

namespace App\Agents\Drivers;

/**
 * Interface for agent driver classes that encapsulate required arguments
 * for each supported agent. This interface provides a consistent API
 * for accessing agent configuration and building commands.
 */
interface AgentDriverInterface
{
    /**
     * Get the unique identifier/name for this agent driver.
     * This is typically the agent name used in configuration (e.g., 'claude-opus', 'cursor-composer').
     *
     * @return string The agent identifier
     */
    public function getName(): string;

    /**
     * Get a human-friendly label for this agent.
     * Used for display purposes in CLI output and user interfaces.
     *
     * @return string The human-readable label (e.g., 'Claude', 'Cursor Agent')
     */
    public function getLabel(): string;

    /**
     * Get the command/binary name for this agent.
     * This is the executable name used to invoke the agent (e.g., 'claude', 'cursor-agent').
     *
     * @return string The command/binary name
     */
    public function getCommand(): string;

    /**
     * Get the default arguments array for this agent.
     * These are arguments that are always included when running the agent.
     *
     * @return array<string> Array of default argument strings
     */
    public function getDefaultArgs(): array;

    /**
     * Get the prompt arguments array for this agent.
     * These arguments are inserted before the prompt when building a command.
     * For example: ['-p'] for Claude, ['run'] for OpenCode.
     *
     * @return array<string> Array of prompt argument strings
     */
    public function getPromptArgs(): array;

    /**
     * Get the default environment variables for this agent.
     * These environment variables are set when spawning the agent process.
     *
     * @return array<string, string> Associative array of environment variable name => value
     */
    public function getDefaultEnv(): array;

    /**
     * Get the model argument for this agent.
     * Returns null if the agent doesn't use a model argument or if the model
     * is controlled by other means (e.g., via mode flags).
     *
     * @return string|null The model identifier, or null if not applicable
     */
    public function getModelArg(): ?string;

    /**
     * Check if this agent supports resuming sessions.
     * Some agents may not support session resumption.
     *
     * @return bool True if resume is supported, false otherwise
     */
    public function supportsResume(): bool;

    /**
     * Get the arguments array for resuming a session interactively.
     * Used with pcntl_exec for interactive resume (no prompt).
     * The session ID will be inserted appropriately based on the agent's format.
     *
     * @param  string  $sessionId  The session ID to resume
     * @return array<string> Array of argument strings for resume command
     */
    public function getResumeArgs(string $sessionId): array;

    /**
     * Get the full command string to resume a session interactively.
     * This is a shell-escaped command string suitable for display or passthru().
     * The session ID will be properly escaped for shell execution.
     *
     * @param  string  $sessionId  The session ID to resume
     * @return string The complete resume command string
     */
    public function getResumeCommand(string $sessionId): string;

    /**
     * Get the full command string to resume a session with a prompt (headless mode).
     * This is a shell-escaped command string suitable for display or passthru().
     * Both the session ID and prompt will be properly escaped for shell execution.
     *
     * @param  string  $sessionId  The session ID to resume
     * @param  string  $prompt  The prompt to execute in the resumed session
     * @return string The complete resume-with-prompt command string
     */
    public function getResumeWithPromptCommand(string $sessionId, string $prompt): string;
}
