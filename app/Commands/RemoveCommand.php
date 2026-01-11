<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\BacklogService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;

class RemoveCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'remove
        {id : The task or backlog ID (f-xxx or b-xxx, supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Delete a task or backlog item';

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    public function handle(FuelContext $context, TaskService $taskService, BacklogService $backlogService, DatabaseService $dbService): int
    {
        // Configure context with --cwd if provided
        $this->configureCwd($context);

        // Reconfigure DatabaseService if context path changed
        if ($this->option('cwd')) {
            $dbService->setDatabasePath($context->getDatabasePath());
        }

        $id = $this->argument('id');

        try {
            // Determine which service to use based on ID prefix
            $hasBacklogPrefix = str_starts_with($id, 'b-');
            $hasTaskPrefix = str_starts_with($id, 'f-');

            // Initialize both services
            $taskService->initialize();
            $backlogService->initialize();

            // If ID has explicit prefix, use that service
            if ($hasBacklogPrefix) {
                $item = $backlogService->find($id);

                if ($item === null) {
                    return $this->outputError(sprintf("Backlog item '%s' not found", $id));
                }

                $resolvedId = $item['id'];
                $title = $item['title'] ?? '';

                // Confirm deletion unless --force is set, --json is set, or non-interactive shell
                if (! $this->option('force') && ! $this->option('json') && $this->input->isInteractive() && ! $this->confirm(sprintf("Are you sure you want to delete backlog item '%s' (%s)?", $resolvedId, $title))) {
                    $this->line('Deletion cancelled.');

                    return self::SUCCESS;
                }

                // Delete from backlog
                $deletedItem = $backlogService->delete($resolvedId);

                if ($this->option('json')) {
                    $this->outputJson([
                        'id' => $resolvedId,
                        'type' => 'backlog',
                        'deleted' => $deletedItem,
                    ]);
                } else {
                    $this->info('Deleted backlog item: '.$resolvedId);
                    $this->line('  Title: '.$title);
                }

                return self::SUCCESS;
            }

            if ($hasTaskPrefix) {
                $task = $taskService->find($id);

                if (! $task instanceof Task) {
                    return $this->outputError(sprintf("Task '%s' not found", $id));
                }

                $resolvedId = $task->id;
                $title = $task->title ?? '';

                // Validate that the resolved task ID starts with 'f-' (is a task, not backlog item)
                if (! str_starts_with((string) $resolvedId, 'f-')) {
                    return $this->outputError(sprintf("ID '%s' is not a task (must have f- prefix)", $id));
                }

                // Confirm deletion unless --force is set, --json is set, or non-interactive shell
                if (! $this->option('force') && ! $this->option('json') && $this->input->isInteractive() && ! $this->confirm(sprintf("Are you sure you want to delete task '%s' (%s)?", $resolvedId, $title))) {
                    $this->line('Deletion cancelled.');

                    return self::SUCCESS;
                }

                // Delete from tasks
                $deletedTask = $taskService->delete($resolvedId);

                if ($this->option('json')) {
                    $this->outputJson([
                        'id' => $resolvedId,
                        'type' => 'task',
                        'deleted' => $deletedTask->toArray(),
                    ]);
                } else {
                    $this->info('Deleted task: '.$resolvedId);
                    $this->line('  Title: '.$title);
                }

                return self::SUCCESS;
            }

            // No explicit prefix - try both services (partial ID matching)
            $task = $taskService->find($id);
            $backlogItem = $backlogService->find($id);

            // Check for ambiguous matches
            if ($task instanceof Task && $backlogItem !== null) {
                return $this->outputError(sprintf("ID '%s' is ambiguous. Matches both task '%s' and backlog item '%s'. Use full ID with prefix.", $id, $task->id, $backlogItem['id']));
            }

            if ($backlogItem !== null) {
                $resolvedId = $backlogItem['id'];
                $title = $backlogItem['title'] ?? '';

                // Confirm deletion unless --force is set, --json is set, or non-interactive shell
                if (! $this->option('force') && ! $this->option('json') && $this->input->isInteractive() && ! $this->confirm(sprintf("Are you sure you want to delete backlog item '%s' (%s)?", $resolvedId, $title))) {
                    $this->line('Deletion cancelled.');

                    return self::SUCCESS;
                }

                // Delete from backlog
                $deletedItem = $backlogService->delete($resolvedId);

                if ($this->option('json')) {
                    $this->outputJson([
                        'id' => $resolvedId,
                        'type' => 'backlog',
                        'deleted' => $deletedItem,
                    ]);
                } else {
                    $this->info('Deleted backlog item: '.$resolvedId);
                    $this->line('  Title: '.$title);
                }

                return self::SUCCESS;
            }

            if ($task instanceof Task) {
                $resolvedId = $task->id;
                $title = $task->title ?? '';

                // Validate that the resolved task ID starts with 'f-' (is a task, not backlog item)
                if (! str_starts_with((string) $resolvedId, 'f-')) {
                    return $this->outputError(sprintf("ID '%s' is not a task (must have f- prefix)", $id));
                }

                // Confirm deletion unless --force is set, --json is set, or non-interactive shell
                if (! $this->option('force') && ! $this->option('json') && $this->input->isInteractive() && ! $this->confirm(sprintf("Are you sure you want to delete task '%s' (%s)?", $resolvedId, $title))) {
                    $this->line('Deletion cancelled.');

                    return self::SUCCESS;
                }

                // Delete from tasks
                $deletedTask = $taskService->delete($resolvedId);

                if ($this->option('json')) {
                    $this->outputJson([
                        'id' => $resolvedId,
                        'type' => 'task',
                        'deleted' => $deletedTask->toArray(),
                    ]);
                } else {
                    $this->info('Deleted task: '.$resolvedId);
                    $this->line('  Title: '.$title);
                }

                return self::SUCCESS;
            }

            // Not found in either service
            return $this->outputError(sprintf("Task or backlog item '%s' not found", $id));
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }
    }
}
