<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Epic;
use App\Services\EpicService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicApproveCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:approve|approve
        {ids* : The epic ID(s) (supports partial matching, accepts multiple IDs)}
        {--by= : Who approved it (defaults to "human")}
        {--json : Output as JSON}';

    protected $description = 'Approve one or more epics (mark as approved)';

    public function handle(EpicService $epicService): int
    {
        $ids = $this->argument('ids');
        $approvedBy = $this->option('by');
        $epics = [];
        $errors = [];

        foreach ($ids as $id) {
            try {
                $epic = $epicService->approveEpic($id, $approvedBy);
                $epics[] = $epic;
            } catch (RuntimeException $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        if ($epics === [] && $errors !== []) {
            // All failed
            return $this->outputError($errors[0]['error']);
        }

        if ($this->option('json')) {
            $output = array_map(fn (Epic $epic): array => $epic->toArray(), $epics);

            if (count($output) === 1) {
                $this->outputJson($output[0]);
            } else {
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

    // NOTE: Commit task creation removed - each task now commits individually.
    // Keeping code commented for reference in case we want to bring it back.
    //
    // private function createCommitTask(
    //     TaskService $taskService,
    //     EpicService $epicService,
    //     Epic $epic
    // ): ?Task {
    //     $tasks = $epicService->getTasksForEpic($epic->short_id);
    //     $completedTasks = array_filter(
    //         $tasks,
    //         fn (Task $t): bool => $t->status === TaskStatus::Done
    //     );
    //
    //     if ($completedTasks === []) {
    //         return null;
    //     }
    //
    //     $description = $this->buildCommitTaskDescription($epic, $completedTasks);
    //
    //     return $taskService->create([
    //         'title' => 'Commit: '.$epic->title,
    //         'description' => $description,
    //         'type' => 'chore',
    //         'priority' => 0,
    //         'complexity' => 'moderate',
    //         'labels' => ['epic-commit'],
    //         'epic_id' => $epic->short_id,
    //     ]);
    // }
    //
    // private function buildCommitTaskDescription(Epic $epic, array $tasks): string
    // {
    //     $taskList = array_map(
    //         fn (Task $t): string => sprintf('- %s: %s', $t->short_id, $t->title),
    //         $tasks
    //     );
    //     $taskListStr = implode("\n", $taskList);
    //     $epicDescription = $epic->description ?? '(no description)';
    //
    //     return <<<DESC
    // Organize and commit staged changes for epic {$epic->short_id}.
    //
    // ## Epic
    // Title: {$epic->title}
    // Description: {$epicDescription}
    //
    // ## Completed Tasks
    // {$taskListStr}
    //
    // ## Instructions
    // 1. Review staged changes with `git status` and `git diff --cached`
    // 2. If no staged changes, check for unstaged changes and stage them
    // 3. If no changes at all, mark done with reason "No changes to commit"
    // 4. Organize changes into meaningful conventional commits
    // 5. Run tests and linter to verify
    // 6. Mark this task done with the last commit hash
    // DESC;
    // }
}
