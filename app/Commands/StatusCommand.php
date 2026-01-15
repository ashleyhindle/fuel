<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Commands\Concerns\RendersBoardColumns;
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
    use RendersBoardColumns;

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

            return self::SUCCESS;
        }

        // Build tables as string arrays
        $boardTable = new Table;
        $boardLines = $boardTable->buildTable(
            ['Status', 'Count'],
            [
                ['Ready', (string) $boardState['ready']],
                ['In_progress', (string) $boardState['in_progress']],
                ['Review', (string) $boardState['review']],
                ['Blocked', (string) $boardState['blocked']],
                ['Human', (string) $boardState['human']],
                ['Done', (string) $boardState['done']],
            ]
        );

        // If no runner status, just show board table stacked
        if ($runnerStatus === null) {
            $this->line('<fg=white;options=bold>Board Summary</>');
            foreach ($boardLines as $line) {
                $this->output->writeln($line);
            }

            return self::SUCCESS;
        }

        // Build runner table
        $runnerTable = new Table;
        $runnerLines = $runnerTable->buildTable(
            ['Metric', 'Value'],
            [
                ['PID', (string) $runnerStatus['pid']],
                ['State', $runnerStatus['state']],
                ['Active processes', (string) $runnerStatus['active_processes']],
            ]
        );

        // Calculate table widths (using first line which is the full width)
        $runnerWidth = $this->visibleLength($runnerLines[0]);
        $boardWidth = $this->visibleLength($boardLines[0]);
        $terminalWidth = $this->getTerminalWidth();

        // Add spacing between tables (3 spaces)
        $spacing = 3;
        $totalWidth = $runnerWidth + $spacing + $boardWidth;

        // Check if tables fit side by side
        if ($totalWidth <= $terminalWidth) {
            $this->renderSideBySide($runnerLines, $boardLines, 'Runner Status', 'Board Summary', $spacing);
        } else {
            // Stack vertically (original behavior)
            $this->line('<fg=white;options=bold>Runner Status</>');
            foreach ($runnerLines as $line) {
                $this->output->writeln($line);
            }
            $this->newLine();

            $this->line('<fg=white;options=bold>Board Summary</>');
            foreach ($boardLines as $line) {
                $this->output->writeln($line);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Render two tables side by side with headers.
     *
     * @param  array<string>  $leftLines  Left table lines
     * @param  array<string>  $rightLines  Right table lines
     * @param  string  $leftHeader  Left table header
     * @param  string  $rightHeader  Right table header
     * @param  int  $spacing  Space between tables
     */
    private function renderSideBySide(
        array $leftLines,
        array $rightLines,
        string $leftHeader,
        string $rightHeader,
        int $spacing
    ): void {
        // Calculate widths
        $leftWidth = $this->visibleLength($leftLines[0]);
        $rightWidth = $this->visibleLength($rightLines[0]);

        // Render headers (left-aligned with their respective tables)
        $leftHeaderLine = '<fg=white;options=bold>'.$leftHeader.'</>';
        $rightHeaderLine = '<fg=white;options=bold>'.$rightHeader.'</>';
        $leftHeaderPadded = $this->padLine($leftHeaderLine, $leftWidth);
        $this->line($leftHeaderPadded.str_repeat(' ', $spacing).$rightHeaderLine);

        // Pad arrays to same height
        $maxHeight = max(count($leftLines), count($rightLines));
        $leftLines = $this->padColumn($leftLines, $maxHeight, $leftWidth);
        $rightLines = $this->padColumn($rightLines, $maxHeight, $rightWidth);

        // Render each row side by side
        for ($i = 0; $i < $maxHeight; $i++) {
            $leftLine = $this->padLine($leftLines[$i], $leftWidth);
            $rightLine = $rightLines[$i];
            $this->output->writeln($leftLine.str_repeat(' ', $spacing).$rightLine);
        }
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
