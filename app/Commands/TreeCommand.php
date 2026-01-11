<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Support\Collection;
use LaravelZero\Framework\Commands\Command;

class TreeCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'tree
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Show pending tasks as a dependency tree';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        $tasks = $taskService->all()
            ->filter(fn (Task $t): bool => ($t->status ?? '') !== 'closed')
            ->sortBy([
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

        $taskMap = $tasks->keyBy('id');
        $treeData = $this->buildTreeData($tasks, $taskMap);

        if ($this->option('json')) {
            $this->outputJson(array_map(fn(array $item): array => [
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
                if ($blocker !== null && ($blocker->status ?? '') !== 'closed') {
                    $blocksMap[$blockerId][] = $task;
                }
            }
        }

        $treeData = [];
        foreach ($tasks as $task) {
            $taskId = $task->id;
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
                $task->id,
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
                    $blocked->id,
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
        $status = $task->status ?? 'open';

        // Check for needs-human label first
        if ($this->hasNeedsHumanLabel($task)) {
            return '👤 needs human';
        }

        // Check if blocked
        if (! empty($blockedBy)) {
            return 'blocked';
        }

        return $status;
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
