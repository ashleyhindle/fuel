<?php

declare(strict_types=1);

namespace App\Agents\Tasks;

use App\Daemon\DaemonLogger;
use App\Enums\MirrorStatus;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Process\CompletionResult;
use App\Process\ProcessType;
use App\Services\ConfigService;
use App\Services\EpicService;
use App\Services\PromptService;
use App\Services\TaskService;
use Illuminate\Support\Str;

/**
 * Agent task for merging an epic's mirror branch back into main.
 *
 * Encapsulates:
 * - Merge agent from config (falls back to 'primary')
 * - Prompt construction for git merge operations
 * - Success: cleanup mirror and mark done
 * - Failure: pause epic, mark mirror as merge failed
 */
class MergeEpicAgentTask extends AbstractAgentTask
{
    public function __construct(
        Task $task,
        TaskService $taskService,
        private readonly Epic $epic,
        private readonly EpicService $epicService,
        private readonly PromptService $promptService,
    ) {
        parent::__construct($task, $taskService);
    }

    /**
     * Create merge task from an epic.
     *
     * Task is created with status=pending and agent=merge so it goes through
     * the normal queue. TaskSpawner recognizes agent=merge and creates a
     * MergeEpicAgentTask for proper lifecycle hook handling.
     */
    public static function fromEpic(Epic $epic): self
    {
        $taskService = app(TaskService::class);
        $mergeTask = $taskService->create([
            'title' => 'Merge epic/'.$epic->short_id.' into main',
            'description' => 'Merge epic "'.$epic->title.'" from mirror branch into main project',
            'type' => 'merge',
            'status' => TaskStatus::Open->value,
            'epic_id' => $epic->id,
        ]);

        return new self(
            $mergeTask,
            $taskService,
            $epic,
            app(EpicService::class),
            app(PromptService::class),
        );
    }

    /**
     * Create MergeEpicAgentTask from an existing task model.
     * Used by TaskSpawner when it picks up a merge task from the queue.
     */
    public static function fromTaskModel(Task $task): self
    {
        if ($task->epic_id === null) {
            throw new \RuntimeException('Merge task must have an epic_id');
        }

        $epic = Epic::find($task->epic_id);
        if ($epic === null) {
            throw new \RuntimeException('Epic not found for merge task');
        }

        return new self(
            $task,
            app(TaskService::class),
            $epic,
            app(EpicService::class),
            app(PromptService::class),
        );
    }

    /**
     * Get agent name for merge tasks.
     * Uses 'primary' agent as merge operations need reliable execution.
     */
    public function getAgentName(ConfigService $configService): ?string
    {
        // Use primary agent for merge operations
        return $configService->getPrimaryAgent();
    }

    /**
     * Build the merge prompt with all necessary variables.
     */
    public function buildPrompt(string $cwd): string
    {
        $template = $this->promptService->loadTemplate('merge');

        // Parse quality gates from reality.md
        $qualityGates = $this->getQualityGatesFromReality($cwd);

        // Build plan file name
        $epicTitleSlug = Str::slug($this->epic->title);
        $planFileName = "{$epicTitleSlug}-{$this->epic->short_id}.md";

        $variables = [
            'epic' => [
                'id' => $this->epic->short_id,
                'title' => $this->epic->title,
                'plan_file' => $planFileName,
            ],
            'mirror' => [
                'path' => $this->epic->mirror_path,
                'branch' => $this->epic->mirror_branch,
                'base_commit' => $this->epic->mirror_base_commit,
            ],
            'project' => [
                'path' => $cwd,
            ],
            'quality_gates' => $qualityGates,
        ];

        return $this->promptService->render($template, $variables);
    }

    /**
     * Parse quality gates from reality.md file.
     * Returns formatted commands or default if not found.
     */
    private function getQualityGatesFromReality(string $cwd): string
    {
        $realityPath = $cwd.'/.fuel/reality.md';

        if (! file_exists($realityPath)) {
            return $this->getDefaultQualityGates();
        }

        $content = file_get_contents($realityPath);
        if ($content === false) {
            return $this->getDefaultQualityGates();
        }

        // Look for the Quality Gates section
        if (preg_match('/## Quality Gates\s*\n\|.*?\|.*?\|.*?\|\s*\n\|[-\s|]+\|\s*\n((?:\|.*?\|.*?\|.*?\|\s*\n)+)/m', $content, $matches)) {
            $tableRows = trim($matches[1]);
            $lines = explode("\n", $tableRows);
            $commands = [];

            foreach ($lines as $line) {
                // Parse table row: | Tool | Command | Purpose |
                if (preg_match('/\|\s*([^|]+)\s*\|\s*([^|]+)\s*\|\s*([^|]+)\s*\|/', $line, $rowMatch)) {
                    $tool = trim($rowMatch[1]);
                    $command = trim($rowMatch[2]);
                    $purpose = trim($rowMatch[3]);

                    // Skip if command contains backticks (code formatting)
                    $cleanCommand = str_replace('`', '', $command);

                    $commands[] = sprintf(
                        "# %s - %s\n%s",
                        $tool,
                        $purpose,
                        $cleanCommand
                    );
                }
            }

            if (! empty($commands)) {
                return "```bash\n".implode("\n\n", $commands)."\n```";
            }
        }

        return $this->getDefaultQualityGates();
    }

    /**
     * Get default quality gates if reality.md doesn't have them.
     */
    private function getDefaultQualityGates(): string
    {
        return <<<'GATES'
```bash
# Pest - Test runner
./vendor/bin/pest --parallel --compact

# Pint - Code formatter
./vendor/bin/pint

# If any failures, fix them and re-run until all pass
```
GATES;
    }

    public function getProcessType(): ProcessType
    {
        return ProcessType::Task;
    }

    /**
     * On successful merge, cleanup mirror and mark task done.
     */
    public function onSuccess(CompletionResult $result): void
    {
        try {
            // Cleanup the mirror directory
            $this->epicService->cleanupMirror($this->epic);

            // Mark the task as done
            $this->taskService->done($this->task->short_id, 'Successfully merged epic');
        } catch (\RuntimeException $e) {
            // Log but don't fail - merge was successful
            DaemonLogger::getInstance()->warning('Failed to cleanup after merge', ['error' => $e->getMessage()]);
        }
    }

    /**
     * On merge failure, pause epic and update mirror status.
     */
    public function onFailure(CompletionResult $result): void
    {
        try {
            // Pause the epic
            $this->epicService->pause($this->epic->short_id, 'Merge failed - needs human attention');

            // Update mirror status to MergeFailed
            $this->epicService->updateMirrorStatus($this->epic, MirrorStatus::MergeFailed);

            // Delete the merge task (it failed)
            $this->taskService->delete($this->task->short_id);
        } catch (\RuntimeException $e) {
            // Log but don't fail catastrophically
            DaemonLogger::getInstance()->error('Failed to handle merge failure', ['error' => $e->getMessage()]);
        }
    }
}
