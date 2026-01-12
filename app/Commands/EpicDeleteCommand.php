<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Epic;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;

class EpicDeleteCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:delete
        {id : The epic ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Delete an epic and unlink its tasks';

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    public function handle(FuelContext $context, DatabaseService $dbService, TaskService $taskService, EpicService $epicService): int
    {
        // Configure context with --cwd if provided
        $this->configureCwd($context, $dbService);

        try {
            $epic = $epicService->getEpic($this->argument('id'));

            if (! $epic instanceof Epic) {
                return $this->outputError(sprintf("Epic '%s' not found", $this->argument('id')));
            }

            $epicId = $epic->short_id;
            $title = $epic->title ?? '';

            $linkedTasks = $epicService->getTasksForEpic($epicId);

            if (! $this->option('force') && ! $this->option('json') && $this->input->isInteractive()) {
                $taskCount = count($linkedTasks);
                $confirmMessage = sprintf(
                    "Are you sure you want to delete epic '%s' (%s)?",
                    $epicId,
                    $title
                );
                if ($taskCount > 0) {
                    $confirmMessage .= sprintf(' This will unlink %d task(s).', $taskCount);
                }

                if (! $this->confirm($confirmMessage)) {
                    $this->line('Deletion cancelled.');

                    return self::SUCCESS;
                }
            }

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
