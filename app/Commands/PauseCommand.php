<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\EpicService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class PauseCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'pause
        {id : The task (f-xxx) or epic (e-xxx) ID (supports partial matching)}
        {--json : Output as JSON}';

    protected $description = 'Pause a task or epic (set status to paused)';

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
                $epic = $epicService->pause($id);

                if ($this->option('json')) {
                    $this->outputJson($epic->toArray());
                } else {
                    $this->info('Paused epic: '.$epic->short_id);
                    $this->line('  Title: '.$epic->title);
                }
            } else {
                $task = $taskService->pause($id);

                if ($this->option('json')) {
                    $this->outputJson($task->toArray());
                } else {
                    $this->info('Paused task: '.$task->short_id);
                    $this->line('  Title: '.$task->title);
                }
            }
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }

        return self::SUCCESS;
    }
}
