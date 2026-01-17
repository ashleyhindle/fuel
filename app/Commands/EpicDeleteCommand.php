<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Epic;
use App\Services\EpicService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicDeleteCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:delete
        {id : The epic ID (supports partial matching)}
        {--json : Output as JSON}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Delete an epic and unlink its tasks';

    public function handle(TaskService $taskService, EpicService $epicService): int
    {
        try {
            $epic = $epicService->getEpic($this->argument('id'));

            if (! $epic instanceof Epic) {
                return $this->outputError(sprintf("Epic '%s' not found", $this->argument('id')));
            }

            $epicId = $epic->short_id;
            $title = $epic->title ?? '';

            $linkedTasks = $epicService->getTasksForEpic($epicId);

            $unlinkedTaskIds = [];
            foreach ($linkedTasks as $task) {
                $taskService->update($task->short_id, ['epic_id' => null]);
                $unlinkedTaskIds[] = $task->short_id;
            }

            $deletedEpic = $epicService->deleteEpic($epicId);

            if ($this->option('json')) {
                $this->outputJson([
                    'short_id' => $epicId,
                    'deleted' => $deletedEpic->toArray(),
                    'unlinked_tasks' => $unlinkedTaskIds,
                ]);

                return self::SUCCESS;
            }

            $this->info('Deleted epic: '.$epicId);
            $this->line('  Title: '.$title);
            if ($unlinkedTaskIds !== []) {
                $this->line('  Unlinked tasks: '.implode(', ', $unlinkedTaskIds));
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        } catch (\Exception $e) {
            return $this->outputError('Failed to delete epic: '.$e->getMessage());
        }
    }
}
