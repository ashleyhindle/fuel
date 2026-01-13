<?php

declare(strict_types=1);

namespace App\Agents\Tasks;

use App\Process\CompletionResult;
use App\Process\ProcessType;
use App\Services\ConfigService;

/**
 * Interface for agent task abstractions.
 *
 * Each implementation encapsulates:
 * - Agent selection logic (which agent to use)
 * - Prompt building (how to construct the prompt)
 * - Lifecycle hooks (onComplete, onSuccess, onFailure)
 */
interface AgentTaskInterface
{
    /**
     * Get the task ID for tracking purposes.
     */
    public function getTaskId(): string;

    /**
     * Get the agent name to use for this task.
     *
     * Different implementations may use different routing:
     * - WorkAgentTask: complexity-based routing
     * - ReviewAgentTask: 'review' agent from config
     * - BreakdownAgentTask: 'primary' agent
     */
    public function getAgentName(ConfigService $configService): ?string;

    /**
     * Build the prompt to send to the agent.
     */
    public function buildPrompt(string $cwd): string;

    /**
     * Get the process type (Task or Review).
     */
    public function getProcessType(): ProcessType;

    /**
     * Called when the agent process completes (regardless of success/failure).
     *
     * This hook is called before onSuccess or onFailure.
     * Use for common cleanup or logging that applies to all completions.
     */
    public function onComplete(CompletionResult $result): void;

    /**
     * Called when the agent process completes successfully (exit code 0).
     *
     * Use for task-specific success handling:
     * - WorkAgentTask: trigger review or mark done
     * - ReviewAgentTask: parse JSON result and update task
     */
    public function onSuccess(CompletionResult $result): void;

    /**
     * Called when the agent process fails (non-zero exit code).
     *
     * Use for task-specific failure handling:
     * - WorkAgentTask: reopen task for retry
     * - ReviewAgentTask: reopen task without issues
     */
    public function onFailure(CompletionResult $result): void;
}
