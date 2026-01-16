<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\EpicService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class UnpauseCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'unpause
        {id : The task (f-xxx) or epic (e-xxx) ID (supports partial matching)}
        {--json : Output as JSON}';

    protected $description = 'Unpause a task or epic (restore from paused status)';

    public function handle(TaskService $taskService, EpicService $epicService): int
    {
        $id = $this->argument('id');

        // Detect type by prefix
        $isEpic = str_starts_with($id, 'e-');
        $isTask = str_starts_with($id, 'f-');

        // If no prefix, default to task
        if (! $isEpic && ! $isTask) {
            $isTask = true;
        }

        try {
            if ($isEpic) {
                $epic = $epicService->unpause($id);

                if ($this->option('json')) {
                    $this->outputJson($epic->toArray());
                } else {
                    $this->info('Unpaused epic: '.$epic->short_id);
                    $this->line('  Title: '.$epic->title);
                }
            } else {
                $task = $taskService->unpause($id);

                if ($this->option('json')) {
                    $this->outputJson($task->toArray());
                } else {
                    $this->info('Unpaused task: '.$task->short_id);
                    $this->line('  Title: '.$task->title);
                }
            }
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }

        return self::SUCCESS;
    }
}
