<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Services\EpicService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicApproveCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:approve|approve
        {ids* : The epic ID(s) (supports partial matching, accepts multiple IDs)}
        {--cwd= : Working directory (defaults to current directory)}
        {--by= : Who approved it (defaults to "human")}
        {--json : Output as JSON}';

    protected $description = 'Approve one or more epics (mark as approved)';

    public function handle(EpicService $epicService, TaskService $taskService): int
    {
        $ids = $this->argument('ids');
        $approvedBy = $this->option('by');
        $epics = [];
        $errors = [];
        $commitTasks = [];

        foreach ($ids as $id) {
            try {
                $epic = $epicService->approveEpic($id, $approvedBy);
                $epics[] = $epic;

                // Create commit task for the approved epic
                $commitTask = $this->createCommitTask($taskService, $epicService, $epic);
                if ($commitTask instanceof Task) {
                    $commitTasks[$epic->short_id] = $commitTask;
                }
            } catch (RuntimeException $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        if ($epics === [] && $errors !== []) {
            // All failed
            return $this->outputError($errors[0]['error']);
        }

        if ($this->option('json')) {
            // Include commit task info in JSON output
            $output = array_map(function (Epic $epic) use ($commitTasks): array {
                $data = $epic->toArray();
                if (isset($commitTasks[$epic->short_id])) {
                    $data['commit_task'] = [
                        'short_id' => $commitTasks[$epic->short_id]->short_id,
                        'title' => $commitTasks[$epic->short_id]->title,
                    ];
                }

                return $data;
            }, $epics);

            if (count($output) === 1) {
                // Single epic - return object for backward compatibility
                $this->outputJson($output[0]);
            } else {
                // Multiple epics - return array
                $this->outputJson($output);
            }
        } else {
            foreach ($epics as $epic) {
                $this->info(sprintf('Epic %s approved', $epic->short_id));
                if (isset($epic->approved_by)) {
                    $this->line(sprintf('  Approved by: %s', $epic->approved_by));
                }

                if (isset($epic->approved_at)) {
                    $this->line(sprintf('  Approved at: %s', $epic->approved_at));
                }

                // Show commit task info
                if (isset($commitTasks[$epic->short_id])) {
                    $commitTask = $commitTasks[$epic->short_id];
                    $this->line(sprintf('  Commit task: %s', $commitTask->short_id));
                }
            }
        }

        // If there were any errors, return failure even if some succeeded
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->outputError(sprintf("Epic '%s': %s", $error['id'], $error['error']));
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Create a commit task for an approved epic.
     */
    private function createCommitTask(
        TaskService $taskService,
        EpicService $epicService,
        Epic $epic
    ): ?Task {
        // Get all tasks for this epic
        $tasks = $epicService->getTasksForEpic($epic->short_id);

        // Check if there are any completed tasks
        $completedTasks = array_filter(
            $tasks,
            fn (Task $t): bool => $t->status === TaskStatus::Done
        );

        if ($completedTasks === []) {
            return null; // No work was done, no commit needed
        }

        // Build description with epic context
        $description = $this->buildCommitTaskDescription($epic, $completedTasks);

        return $taskService->create([
            'title' => 'Commit: '.$epic->title,
            'description' => $description,
            'type' => 'chore',
            'priority' => 0,
            'complexity' => 'moderate',
            'labels' => ['epic-commit'],
            'epic_id' => $epic->short_id,
        ]);
    }

    /**
     * Build the description for a commit task.
     *
     * @param  array<int, Task>  $tasks
     */
    private function buildCommitTaskDescription(Epic $epic, array $tasks): string
    {
        $taskList = array_map(
            fn (Task $t): string => sprintf('- %s: %s', $t->short_id, $t->title),
            $tasks
        );
        $taskListStr = implode("\n", $taskList);
        $epicDescription = $epic->description ?? '(no description)';

        return <<<DESC
Organize and commit staged changes for epic {$epic->short_id}.

## Epic
Title: {$epic->title}
Description: {$epicDescription}

## Completed Tasks
{$taskListStr}

## Instructions
1. Review staged changes with `git status` and `git diff --cached`
2. If no staged changes, check for unstaged changes and stage them
3. If no changes at all, mark done with reason "No changes to commit"
4. Organize changes into meaningful conventional commits
5. Run tests and linter to verify
6. Mark this task done with the last commit hash
DESC;
    }
}
