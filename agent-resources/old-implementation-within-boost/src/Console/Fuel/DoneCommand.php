<?php

declare(strict_types=1);

namespace Laravel\Boost\Console\Fuel;

use Illuminate\Console\Command;
use Laravel\Boost\Fuel\TaskService;
use RuntimeException;

class DoneCommand extends Command
{
    protected $signature = 'fuel:done
        {id : The task ID (supports partial matching)}
        {--reason= : Reason for closing the task}
        {--json : Output JSON instead of human-readable}';

    protected $description = 'Mark a fuel task as done/closed';

    public function handle(TaskService $service): int
    {
        try {
            /** @var string $id */
            $id = $this->argument('id');

            /** @var string|null $reason */
            $reason = $this->option('reason');

            $task = $service->find($id);

            if ($task === null) {
                throw new RuntimeException("Task '{$id}' not found");
            }

            // Remember blocked tasks before closing so we can detect newly unblocked
            /** @var array<int, string> $blockedBefore */
            $blockedBefore = $service->blocked()->pluck('id')->all();
            $taskId = $task['id'];
            if (! is_string($taskId)) {
                throw new RuntimeException('Task ID must be a string');
            }

            $closedTask = $service->close($taskId, $reason);

            // Find tasks that are now unblocked
            $readyNow = $service->ready();
            $newlyUnblocked = $readyNow->filter(
                fn (array $t): bool => in_array($t['id'], $blockedBefore, true)
            );

            if ($this->option('json')) {
                $output = [
                    'closed' => $closedTask,
                    'unblocked' => $newlyUnblocked->values()->all(),
                ];
                $this->line((string) json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $closedId = (string) $closedTask['id']; // @phpstan-ignore cast.string
                $closedTitle = (string) $closedTask['title']; // @phpstan-ignore cast.string
                $this->info("Closed task: {$closedId}");
                $this->line("  Title: {$closedTitle}");

                if ($newlyUnblocked->isNotEmpty()) {
                    $this->newLine();
                    $this->info('Now unblocked:');
                    foreach ($newlyUnblocked as $unblocked) {
                        $unblockedId = (string) $unblocked['id']; // @phpstan-ignore cast.string
                        $unblockedTitle = (string) $unblocked['title']; // @phpstan-ignore cast.string
                        $this->line("  - {$unblockedId}: {$unblockedTitle}");
                    }
                }
            }

            return self::SUCCESS;
        } catch (RuntimeException $runtimeException) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $runtimeException->getMessage()]));
            } else {
                $this->error($runtimeException->getMessage());
            }

            return self::FAILURE;
        }
    }
}
