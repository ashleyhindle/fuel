<?php

declare(strict_types=1);

namespace App\Agents\Tasks;

use App\Models\Epic;
use App\Models\Task;
use App\Process\CompletionResult;
use App\Process\ProcessType;
use App\Services\ConfigService;
use App\Services\PromptService;
use App\Services\TaskService;

/**
 * Agent task for updating .fuel/reality.md after task/epic completion.
 *
 * Encapsulates:
 * - 'reality' agent from config (falls back to 'primary')
 * - Prompt construction for reality index updates
 * - Fire-and-forget execution (no lifecycle hooks needed)
 */
class UpdateRealityAgentTask extends AbstractAgentTask
{
    private ?Epic $epic = null;

    public function __construct(
        Task $task,
        TaskService $taskService,
        private readonly PromptService $promptService,
        private readonly string $cwd,
        ?Epic $epic = null,
    ) {
        parent::__construct($task, $taskService);
        $this->epic = $epic;
    }

    /**
     * Create from a solo task completion (task with no epic).
     */
    public static function fromTask(Task $task, string $cwd): self
    {
        return new self(
            $task,
            app(TaskService::class),
            app(PromptService::class),
            $cwd,
        );
    }

    /**
     * Create from epic approval.
     */
    public static function fromEpic(Epic $epic, string $cwd): self
    {
        // Use a dummy task for the abstract class requirement
        // The epic context provides the real update context
        $task = new Task([
            'short_id' => 'reality-'.$epic->short_id,
            'title' => 'Update reality for epic: '.$epic->title,
            'description' => $epic->description,
        ]);

        return new self(
            $task,
            app(TaskService::class),
            app(PromptService::class),
            $cwd,
            $epic,
        );
    }

    /**
     * Get the task ID for tracking purposes.
     */
    public function getTaskId(): string
    {
        return 'reality-'.($this->epic?->short_id ?? $this->task->short_id);
    }

    /**
     * Get agent name using 'reality' agent from config.
     * Falls back to 'primary' agent if not configured.
     */
    public function getAgentName(ConfigService $configService): ?string
    {
        return $configService->getRealityAgent();
    }

    /**
     * Build the prompt for updating reality.md.
     */
    public function buildPrompt(string $cwd): string
    {
        $template = $this->promptService->loadTemplate('reality');

        $variables = [
            'context' => [
                'completed_work' => $this->buildContextSection(),
                'reality_path' => $cwd.'/.fuel/reality.md',
            ],
        ];

        return $this->promptService->render($template, $variables);
    }

    /**
     * Build the context section based on task or epic.
     */
    private function buildContextSection(): string
    {
        if ($this->epic !== null) {
            $tasks = $this->epic->tasks()->get();
            $taskList = $tasks->map(fn (Task $t) => "- [{$t->short_id}] {$t->title}")->implode("\n");

            return <<<CONTEXT
Epic: {$this->epic->title} ({$this->epic->short_id})
Description: {$this->epic->description}

Tasks completed:
{$taskList}
CONTEXT;
        }

        $description = $this->task->description ?: '(no description)';

        return <<<CONTEXT
Task: {$this->task->title} ({$this->task->short_id})
Type: {$this->task->type}
Description: {$description}
CONTEXT;
    }

    public function getProcessType(): ProcessType
    {
        return ProcessType::Task;
    }

    /**
     * No action needed on success - fire and forget.
     */
    public function onSuccess(CompletionResult $result): void
    {
        // Reality updates are fire-and-forget
        // No task state to update
    }

    /**
     * No action needed on failure - fire and forget.
     */
    public function onFailure(CompletionResult $result): void
    {
        // Reality updates are fire-and-forget
        // Failures are logged but don't block anything
    }
}
