<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\TaskService;
use Illuminate\Support\Collection;
use LaravelZero\Framework\Commands\Command;

class TreeCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'tree
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--epic= : Filter tasks by epic ID}';

    protected $description = 'Show pending tasks as a dependency tree';

    public function handle(FuelContext $context, DatabaseService $databaseService, TaskService $taskService): int
    {
        $this->configureCwd($context, $databaseService);

        $tasks = $taskService->all()
            ->filter(fn (Task $t): bool => $t->status !== TaskStatus::Closed);

        $epicFilter = $this->option('epic');
        if ($epicFilter !== null) {
            $epic = Epic::findByPartialId($epicFilter);
            if (! $epic instanceof Epic) {
                if ($this->option('json')) {
                    $this->outputJson(['error' => sprintf("Epic '%s' not found", $epicFilter)]);
                } else {
                    $this->error(sprintf("Epic '%s' not found", $epicFilter));
                }

                return self::FAILURE;
            }

            $tasks = $tasks->filter(fn (Task $t): bool => $t->epic_id === $epic->id);
        }

        $tasks = $tasks->sortBy([
            ['priority', 'asc'],
            ['created_at', 'asc'],
        ])
            ->values();

        if ($tasks->isEmpty()) {
            if ($this->option('json')) {
                $this->outputJson([]);
            } else {
                $this->info('No pending tasks.');
            }

            return self::SUCCESS;
        }

        $taskMap = $tasks->keyBy('short_id');
        $treeData = $this->buildTreeData($tasks, $taskMap);

        if ($this->option('json')) {
            $this->outputJson(array_map(fn (array $item): array => [
                'task' => $item['task']->toArray(),
                'blocks' => array_map(
                    fn (Task $task): array => $task->toArray(),
                    $item['blocks']
                ),
            ], $treeData));
        } else {
            $this->renderTree($treeData);
        }

        return self::SUCCESS;
    }

    /**
     * Build tree data structure for output.
     * Each task shows what it blocks (children are tasks blocked by this task).
     *
     * @return array<int, array{task: Task, blocks: array<int, Task>}>
     */
    private function buildTreeData(Collection $tasks, Collection $taskMap): array
    {
        // Build reverse lookup: task ID -> tasks it blocks
        $blocksMap = [];
        foreach ($tasks as $task) {
            $blockedBy = $task->blocked_by ?? [];
            foreach ($blockedBy as $blockerId) {
                // Only include if blocker is not closed
                $blocker = $taskMap->get($blockerId);
                if ($blocker !== null && $blocker->status !== TaskStatus::Closed) {
                    $blocksMap[$blockerId][] = $task;
                }
            }
        }

        $treeData = [];
        foreach ($tasks as $task) {
            $taskId = $task->short_id;
            $blocks = $blocksMap[$taskId] ?? [];

            $treeData[] = [
                'task' => $task,
                'blocks' => $blocks,
            ];
        }

        return $treeData;
    }

    /**
     * Render the tree to console output.
     *
     * @param  array<int, array{task: Task, blocks: array<int, Task>}>  $treeData
     */
    private function renderTree(array $treeData): void
    {
        foreach ($treeData as $item) {
            $task = $item['task'];
            $blocks = $item['blocks'];

            $priority = $task->priority ?? 2;
            $complexity = $this->getComplexityChar($task);
            $displayStatus = $this->getDisplayStatus($task);
            $statusColor = $this->hasNeedsHumanLabel($task) ? 'magenta' : 'gray';

            $this->line(sprintf(
                '<fg=cyan>[P%d·%s]</> %s %s <fg=%s>(%s)</>',
                $priority,
                $complexity,
                $task->short_id,
                $task->title,
                $statusColor,
                $displayStatus
            ));

            // Render tasks that this task blocks as children
            $blocksCount = count($blocks);
            foreach ($blocks as $index => $blocked) {
                $isLast = ($index === $blocksCount - 1);
                $prefix = $isLast ? '  └─ ' : '  ├─ ';

                $blockedPriority = $blocked->priority ?? 2;
                $blockedComplexity = $this->getComplexityChar($blocked);

                $this->line(sprintf(
                    '%s<fg=yellow>[P%d·%s]</> %s %s <fg=gray>(blocked by this)</>',
                    $prefix,
                    $blockedPriority,
                    $blockedComplexity,
                    $blocked->short_id,
                    $blocked->title
                ));
            }
        }
    }

    /**
     * Get the display status for a task.
     */
    private function getDisplayStatus(Task $task): string
    {
        $blockedBy = $task->blocked_by ?? [];

        // Check for needs-human label first
        if ($this->hasNeedsHumanLabel($task)) {
            return '👤 needs human';
        }

        // Check if blocked
        if (! empty($blockedBy)) {
            return 'blocked';
        }

        return $task->status->value;
    }

    /**
     * Check if a task has the needs-human label.
     */
    private function hasNeedsHumanLabel(Task $task): bool
    {
        $labels = $task->labels ?? [];

        return in_array('needs-human', $labels, true);
    }

    /**
     * Get a single character representing task complexity.
     */
    private function getComplexityChar(Task $task): string
    {
        $complexity = $task->complexity ?? 'simple';

        return match ($complexity) {
            'trivial' => 't',
            'simple' => 's',
            'moderate' => 'm',
            'complex' => 'c',
            default => 's',
        };
    }
}
