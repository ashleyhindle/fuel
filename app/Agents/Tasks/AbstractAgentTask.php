<?php

declare(strict_types=1);

namespace App\Agents\Tasks;

use App\Models\Task;
use App\Process\CompletionResult;
use App\Services\TaskService;

/**
 * Abstract base class for agent task implementations.
 *
 * Provides default empty implementations for lifecycle hooks.
 * Subclasses override specific hooks as needed.
 */
abstract class AbstractAgentTask implements AgentTaskInterface
{
    public function __construct(
        protected readonly Task $task,
        protected readonly TaskService $taskService,
    ) {}

    public function getTaskId(): string
    {
        return $this->task->short_id;
    }

    /**
     * Get the underlying task model.
     */
    public function getTask(): Task
    {
        return $this->task;
    }

    /**
     * Default empty implementation - subclasses override as needed.
     */
    public function onComplete(CompletionResult $result): void
    {
        // Default: no action on completion
    }

    /**
     * Default empty implementation - subclasses override as needed.
     */
    public function onSuccess(CompletionResult $result): void
    {
        // Default: no action on success
    }

    /**
     * Default empty implementation - subclasses override as needed.
     */
    public function onFailure(CompletionResult $result): void
    {
        // Default: no action on failure
    }
}
