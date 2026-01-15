<?php

declare(strict_types=1);

namespace App\Agents\Tasks;

use App\Models\Epic;
use App\Models\Task;
use App\Process\CompletionResult;
use App\Process\ProcessType;
use App\Services\ConfigService;
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
        $realityPath = $cwd.'/.fuel/reality.md';
        $context = $this->buildContextSection();

        return <<<PROMPT
== UPDATE REALITY INDEX ==

You are updating .fuel/reality.md - a lean architectural index of this codebase.
This file helps AI agents quickly understand the codebase structure.

== COMPLETED WORK ==
{$context}

== INSTRUCTIONS ==
1. Read {$realityPath} (create if missing using the structure below)
2. Update based on the completed work above
3. Keep it LEAN - this is an INDEX, not documentation
4. Focus on:
   - New modules/services/commands added → add to Modules table
   - New patterns discovered → add to Patterns section
   - Entry points for common tasks → update Entry Points
   - Append to Recent Changes (keep last 5-10 entries)
   - Remove stale/outdated content if the work changed existing modules

== REALITY.MD STRUCTURE ==
If the file doesn't exist, create it with this structure:

```markdown
# Reality

## Architecture
Brief 2-3 sentence overview of what this codebase is and how it's structured.

## Modules
| Module | Purpose | Entry Point |
|--------|---------|-------------|
| Example | What it does | app/path/File.php |

## Entry Points
**Add a command:** Copy `app/Commands/ExampleCommand.php`
**Add a service:** Create in `app/Services/`, inject via constructor or `app()`

## Patterns
- Pattern: Description

## Recent Changes
- YYYY-MM-DD: Brief description of change

_Last updated: YYYY-MM-DD by UpdateReality_
```

== RULES ==
- Be concise - one line per module/pattern
- Don't duplicate information already in CLAUDE.md
- Update the "Last updated" timestamp
- Only modify .fuel/reality.md - no other files
PROMPT;
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
