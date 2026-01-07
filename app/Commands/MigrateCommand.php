<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class MigrateCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'migrate
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Migrate tasks from old dependency schema ({dependencies: [{depends_on, type: blocks}]}) to new schema ({blocked_by: []})';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        // Check what needs migration before running
        $tasks = $taskService->all();
        $tasksNeedingMigration = $tasks->filter(function (array $task): bool {
            return isset($task['dependencies']) && is_array($task['dependencies']) && count($task['dependencies']) > 0;
        });

        if ($tasksNeedingMigration->isEmpty()) {
            if ($this->option('json')) {
                $this->outputJson([
                    'migrated_count' => 0,
                    'total_tasks' => $tasks->count(),
                    'message' => 'No tasks need migration. All tasks already use the new schema.',
                ]);
            } else {
                $this->info('No tasks need migration. All tasks already use the new schema.');
            }

            return self::SUCCESS;
        }

        // Perform the migration
        $result = $taskService->migrateDependencies();

        if ($this->option('json')) {
            $this->outputJson($result);
        } else {
            $this->info("Migration complete: Migrated {$result['migrated_count']} task(s) out of {$result['total_tasks']} total");
            $this->newLine();
            $this->line('All tasks now use the new {blocked_by} schema instead of {dependencies}.');
        }

        return self::SUCCESS;
    }
}
