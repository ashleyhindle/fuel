<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\DatabaseService;
use App\Services\EpicService;
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

    public function handle(): int
    {
        $dbService = new DatabaseService;
        $taskService = new TaskService;

        if ($cwd = $this->option('cwd')) {
            $dbService->setDatabasePath($cwd.'/.fuel/agent.db');
            $taskService->setStoragePath($cwd.'/.fuel/tasks.jsonl');
        }

        $epicService = new EpicService($dbService, $taskService);

        try {
            $epic = $epicService->getEpic($this->argument('id'));

            if ($epic === null) {
                return $this->outputError(sprintf("Epic '%s' not found", $this->argument('id')));
            }

            $epicId = $epic['id'];
            $title = $epic['title'] ?? '';

            $linkedTasks = $epicService->getTasksForEpic($epicId);

            if (! $this->option('force') && ! $this->option('json')) {
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

            $taskService->initialize();
            $unlinkedTaskIds = [];
            foreach ($linkedTasks as $task) {
                $taskService->update($task['id'], ['epic_id' => null]);
                $unlinkedTaskIds[] = $task['id'];
            }

            $deletedEpic = $epicService->deleteEpic($epicId);

            if ($this->option('json')) {
                $this->outputJson([
                    'id' => $epicId,
                    'deleted' => $deletedEpic,
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
