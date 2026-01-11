<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Task;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\TaskService;
use Carbon\Carbon;
use LaravelZero\Framework\Commands\Command;

class HumanCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'human
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'List all items needing human attention';

    public function handle(FuelContext $context, DatabaseService $dbService, TaskService $taskService): int
    {
        $this->configureCwd($context);

        if ($this->option('cwd')) {
            $dbService->setDatabasePath($context->getDatabasePath());
        }

        $epicService = new EpicService($dbService, $taskService);

        // Get tasks with needs-human label (excluding epic-review tasks)
        $tasks = $taskService->all();
        $humanTasks = $tasks
            ->filter(fn (Task $t): bool => ($t->status ?? '') === 'open')
            ->filter(function (Task $t): bool {
                $labels = $t->labels ?? [];
                if (! is_array($labels)) {
                    return false;
                }

                // Exclude epic-review tasks - we show epics directly now
                if (in_array('epic-review', $labels, true)) {
                    return false;
                }

                return in_array('needs-human', $labels, true);
            })
            ->sortBy('created_at')
            ->values();

        // Get epics with status review_pending
        $allEpics = $epicService->getAllEpics();
        $pendingEpics = array_values(array_filter($allEpics, function ($epic): bool {
            return ($epic->status ?? '') === 'review_pending';
        }));

        if ($this->option('json')) {
            $this->outputJson([
                'tasks' => $humanTasks->map(fn (Task $task): array => $task->toArray())->toArray(),
                'epics' => array_map(fn ($epic) => $epic->toArray(), $pendingEpics),
            ]);

            return self::SUCCESS;
        }

        $totalCount = $humanTasks->count() + count($pendingEpics);

        if ($totalCount === 0) {
            $this->info('No items need human attention.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Items needing human attention (%d):', $totalCount));
        $this->newLine();

        // Show epics pending review first
        foreach ($pendingEpics as $epic) {
            $age = $this->formatAge($epic->created_at ?? null);
            $this->line(sprintf('<info>%s</info> - %s <comment>(%s)</comment>', $epic->id, $epic->title, $age));
            if (! empty($epic->description ?? null)) {
                $this->line('  '.$epic->description);
            }
            $this->line(sprintf('  Review: <comment>fuel epic:review %s</comment>', $epic->id));
            $this->newLine();
        }

        // Show tasks needing human attention
        foreach ($humanTasks as $task) {
            $age = $this->formatAge($task->created_at ?? null);
            $this->line(sprintf('<info>%s</info> - %s <comment>(%s)</comment>', $task->id, $task->title, $age));
            if (! empty($task->description ?? null)) {
                $this->line('  '.$task->description);
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function formatAge(?string $createdAt): string
    {
        if (! $createdAt) {
            return 'unknown';
        }

        return Carbon::parse($createdAt)->diffForHumans();
    }
}
