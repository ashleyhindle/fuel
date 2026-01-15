<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\ConsumeIpcClient;
use App\Services\FuelContext;
use App\Services\TaskService;
use App\TUI\Table;
use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'status
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Show task statistics overview';

    public function handle(
        TaskService $taskService,
        FuelContext $fuelContext
    ): int {
        $tasks = $taskService->all();

        // Calculate board state matching consume --status format
        $taskMap = $tasks->keyBy('short_id');

        // Helper: check if task has needs-human label
        $hasNeedsHuman = fn (Task $t): bool => in_array('needs-human', $t->labels ?? [], true);

        // Helper: check if task is blocked
        $isBlocked = function (Task $task) use ($taskMap): bool {
            $blockedBy = $task->blocked_by ?? [];
            foreach ($blockedBy as $blockerId) {
                if (is_string($blockerId)) {
                    $blocker = $taskMap->get($blockerId);
                    if ($blocker !== null && $blocker->status !== TaskStatus::Done) {
                        return true;
                    }
                }
            }

            return false;
        };

        // Group tasks by board columns (matching consume --status logic)
        $inProgress = $tasks->filter(fn (Task $t): bool => $t->status === TaskStatus::InProgress);
        $review = $tasks->filter(fn (Task $t): bool => $t->status === TaskStatus::Review);
        $done = $tasks->filter(fn (Task $t): bool => $t->status === TaskStatus::Done);

        // Open tasks are split into: ready, blocked, or human
        $open = $tasks->filter(fn (Task $t): bool => $t->status === TaskStatus::Open);
        $human = $open->filter($hasNeedsHuman);
        $openNonHuman = $open->reject($hasNeedsHuman);
        $blocked = $openNonHuman->filter($isBlocked);
        $ready = $openNonHuman->reject($isBlocked);

        $boardState = [
            'ready' => $ready->count(),
            'in_progress' => $inProgress->count(),
            'review' => $review->count(),
            'blocked' => $blocked->count(),
            'human' => $human->count(),
            'done' => $done->count(),
        ];

        // Try to get runner status
        $runnerStatus = $this->getRunnerStatus($fuelContext);

        if ($this->option('json')) {
            $output = ['board' => $boardState];
            if ($runnerStatus !== null) {
                $output['runner'] = $runnerStatus;
            }

            $this->outputJson($output);
        } else {
            // Display runner status if available
            if ($runnerStatus !== null) {
                $this->line('<fg=white;options=bold>Runner Status</>');

                $runnerTable = new Table;
                $runnerTable->render(
                    ['Metric', 'Value'],
                    [
                        ['PID', (string) $runnerStatus['pid']],
                        ['State', $runnerStatus['state']],
                        ['Active processes', (string) $runnerStatus['active_processes']],
                    ],
                    $this->output
                );
                $this->newLine();
            }

            $this->line('<fg=white;options=bold>Board Summary</>');

            $table = new Table;
            $table->render(
                ['Status', 'Count'],
                [
                    ['Ready', (string) $boardState['ready']],
                    ['In_progress', (string) $boardState['in_progress']],
                    ['Review', (string) $boardState['review']],
                    ['Blocked', (string) $boardState['blocked']],
                    ['Human', (string) $boardState['human']],
                    ['Done', (string) $boardState['done']],
                ],
                $this->output
            );
        }

        return self::SUCCESS;
    }

    /**
     * Get runner status if daemon is running.
     *
     * @return array{state: string, active_processes: int}|null
     */
    private function getRunnerStatus(FuelContext $fuelContext): ?array
    {
        return ConsumeIpcClient::getStatus($fuelContext->getPidFilePath());
    }
}
