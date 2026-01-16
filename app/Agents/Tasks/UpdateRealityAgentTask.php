<?php

declare(strict_types=1);

namespace App\Agents\Tasks;

use App\Enums\TaskStatus;
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
    public function __construct(
        Task $task,
        TaskService $taskService,
        private readonly PromptService $promptService,
        private readonly ?Epic $epic = null,
        private readonly ?Task $contextTask = null,
    ) {
        parent::__construct($task, $taskService);
    }

    /**
     * Create from a solo task completion (task with no epic).
     */
    public static function fromTask(Task $task, string $cwd): self
    {
        $taskService = app(TaskService::class);
        $realityTask = $taskService->create([
            'title' => 'Update reality: '.$task->title,
            'description' => $task->description,
            'type' => 'reality',
            'status' => TaskStatus::InProgress->value,
        ]);

        return new self(
            $realityTask,
            $taskService,
            app(PromptService::class),
            $cwd,
            null,
            $task,
        );
    }

    /**
     * Create from epic approval.
     */
    public static function fromEpic(Epic $epic, string $cwd): self
    {
        $taskService = app(TaskService::class);
        $realityTask = $taskService->create([
            'title' => 'Update reality: '.$epic->title,
            'description' => $epic->description,
            'type' => 'reality',
            'status' => TaskStatus::InProgress->value,
        ]);

        return new self(
            $realityTask,
            $taskService,
            app(PromptService::class),
            $cwd,
            $epic,
        );
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
        if ($this->epic instanceof Epic) {
            $tasks = $this->epic->tasks()->get();
            $taskList = $tasks->map(fn (Task $t): string => sprintf('- [%s] %s', $t->short_id, $t->title))->implode("\n");

            return <<<CONTEXT
Epic: {$this->epic->title} ({$this->epic->short_id})
Description: {$this->epic->description}

Tasks completed:
{$taskList}
CONTEXT;
        }

        $task = $this->contextTask ?? $this->task;
        $description = $task->description ?: '(no description)';

        return <<<CONTEXT
Task: {$task->title} ({$task->short_id})
Type: {$task->type}
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
        try {
            $this->taskService->done($this->task->short_id, 'Auto-completed reality update');
        } catch (\RuntimeException) {
            // Fire-and-forget: ignore task completion failures
        }
    }

    /**
     * No action needed on failure - fire and forget.
     */
    public function onFailure(CompletionResult $result): void
    {
        try {
            $this->taskService->delete($this->task->short_id);
        } catch (\RuntimeException) {
            // Fire-and-forget: ignore task failure handling errors
        }
    }
}
