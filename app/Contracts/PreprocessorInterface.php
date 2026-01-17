<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Task;

/**
 * Interface for task preprocessors that inject context before agent execution.
 *
 * Preprocessors run before the agent is spawned and can add relevant context
 * to the prompt (e.g., relevant files, similar past tasks, etc.).
 */
interface PreprocessorInterface
{
    /**
     * Process a task and return additional context to inject into the prompt.
     *
     * @param  Task  $task  The task being processed
     * @param  string  $cwd  The working directory
     * @return string|null Context to inject, or null if nothing to add
     */
    public function process(Task $task, string $cwd): ?string;

    /**
     * Get a unique name for this preprocessor (for logging/debugging).
     */
    public function getName(): string;
}
