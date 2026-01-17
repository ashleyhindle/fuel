<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Commands\Concerns\RendersBoardColumns;
use App\Enums\TaskStatus;
use App\Models\Run;
use App\Models\Task;
use App\Services\ConsumeIpcClient;
use App\Services\FuelContext;
use App\Services\TaskService;
use App\TUI\Table;
use Illuminate\Support\Collection;
use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    use HandlesJsonOutput;
    use RendersBoardColumns;

    protected $signature = 'status
        {--json : Output as JSON}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Show task statistics overview';

    public function handle(
        TaskService $taskService,
        FuelContext $fuelContext
    ): int {
        $tasks = $taskService->all();

        // Calculate board state using TaskService methods for consistency
        $inProgress = $tasks->filter(fn (Task $t): bool => $t->status === TaskStatus::InProgress);
        $review = $tasks->filter(fn (Task $t): bool => $t->status === TaskStatus::Review);
        $done = $tasks->filter(fn (Task $t): bool => $t->status === TaskStatus::Done);

        // Use TaskService methods for ready/blocked - these handle all edge cases
        // (paused epics, needs-human labels, dependencies)
        $ready = $taskService->readyFrom($tasks);
        $blocked = $taskService->blockedFrom($tasks);

        // Human tasks are open tasks with needs-human label (not in ready or blocked)
        $human = $tasks
            ->filter(fn (Task $t): bool => $t->status === TaskStatus::Open)
            ->filter(fn (Task $t): bool => in_array('needs-human', $t->labels ?? [], true));

        $boardState = [
            'ready' => $ready->count(),
            'in_progress' => $inProgress->count(),
            'review' => $review->count(),
            'blocked' => $blocked->count(),
            'human' => $human->count(),
            'done' => $done->count(),
        ];

        // Try to get runner status (includes browser daemon status from IPC)
        $runnerStatus = $this->getRunnerStatus($fuelContext);

        // Browser daemon status comes from runner IPC, or not running if runner isn't running
        $browserStatus = $this->deriveBrowserStatus($runnerStatus);

        if ($this->option('json')) {
            $output = [
                'board' => $boardState,
                'runner' => $runnerStatus ?? ['state' => 'NOT_RUNNING', 'pid' => null, 'active_processes' => 0],
                'browser_daemon' => $browserStatus,
                'in_progress_tasks' => $this->getInProgressTasksData($inProgress),
            ];

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

        // If no runner status, show "Not running" with board table
        if ($runnerStatus === null) {
            $this->line('<fg=white;options=bold>Runner:</>  <fg=yellow>Not running</>');
            $this->line('<fg=white;options=bold>Browser:</>  <fg=yellow>'.($browserStatus['state'] ?? 'Not running').'</>');
            $this->newLine();
            $this->line('<fg=white;options=bold>Board Summary</>');
            foreach ($boardLines as $line) {
                $this->output->writeln($line);
            }

            // Show in-progress tasks even if runner is not active
            if ($inProgress->isNotEmpty()) {
                $this->newLine();
                $this->renderInProgressTasks($inProgress);
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
                ['Browser daemon', $browserStatus['state'] ?? 'Unknown'],
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

        // Show in-progress tasks
        if ($inProgress->isNotEmpty()) {
            $this->newLine();
            $this->renderInProgressTasks($inProgress);
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
     * Also cleans up stale PID files from dead processes.
     *
     * @return array{state: string, active_processes: int, pid: int}|null
     */
    private function getRunnerStatus(FuelContext $fuelContext): ?array
    {
        $pidFilePath = $fuelContext->getPidFilePath();
        $status = ConsumeIpcClient::getStatus($pidFilePath);

        // If no status but PID file exists, clean up stale file
        if ($status === null && file_exists($pidFilePath)) {
            @unlink($pidFilePath);
            @unlink($pidFilePath.'.lock');
        }

        return $status;
    }

    /**
     * Derive browser daemon status from runner status.
     *
     * Browser daemon is managed by the runner, so we get its status via IPC.
     * If runner isn't running, browser daemon isn't running either.
     *
     * @param  array{state: string, active_processes: int, pid: int, browser_daemon: array{running: bool, healthy: bool}}|null  $runnerStatus
     * @return array{state: string, healthy: bool}
     */
    private function deriveBrowserStatus(?array $runnerStatus): array
    {
        if ($runnerStatus === null) {
            return [
                'state' => 'Not running',
                'healthy' => false,
            ];
        }

        $browserDaemon = $runnerStatus['browser_daemon'] ?? ['running' => false, 'healthy' => false];

        if (! $browserDaemon['running']) {
            return [
                'state' => 'Not running',
                'healthy' => false,
            ];
        }

        if ($browserDaemon['healthy']) {
            return [
                'state' => 'Running',
                'healthy' => true,
            ];
        }

        return [
            'state' => 'Running (unresponsive)',
            'healthy' => false,
        ];
    }

    /**
     * Render in-progress tasks with details.
     */
    private function renderInProgressTasks(Collection $inProgress): void
    {
        $this->line('<fg=white;options=bold>In Progress Tasks ('.$inProgress->count().')</>');

        $headers = ['ID', 'Title', 'Agent', 'Running', 'Complexity', 'Epic'];
        $terminalWidth = $this->getTerminalWidth();

        // Calculate max widths: terminal - ID(10) - Agent(12) - Running(10) - Complexity(12) - Epic(15) - borders(20)
        $maxEpicWidth = 15;
        $fixedColumnsWidth = 10 + 12 + 10 + 12 + $maxEpicWidth + 20;
        $maxTitleWidth = max(20, $terminalWidth - $fixedColumnsWidth);

        $rows = $inProgress->map(function (Task $task) use ($maxTitleWidth, $maxEpicWidth): array {
            $activeRun = $this->getActiveRun($task);
            $agent = $activeRun?->agent ?? 'unknown';
            $runningTime = $this->getRunningTime($activeRun);
            $complexity = $this->getComplexityDisplay($task);
            $epicTitle = $task->epic?->title ?? '';

            // Add icons to title like in consume command
            $icons = $this->getTaskIcons($task);
            $titleWithIcons = $task->title.$icons;

            return [
                $task->short_id,
                $this->truncate($titleWithIcons, $maxTitleWidth),
                $agent,
                $runningTime,
                $complexity,
                $this->truncate($epicTitle, $maxEpicWidth),
            ];
        })->toArray();

        $table = new Table;
        $table->render($headers, $rows, $this->output);
    }

    /**
     * Get active run for a task.
     */
    private function getActiveRun(Task $task): ?Run
    {
        return Run::where('task_id', $task->id)
            ->where('status', 'running')
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * Get running time for a run.
     */
    private function getRunningTime(?Run $run): string
    {
        if (! $run instanceof Run || $run->started_at === null) {
            return '-';
        }

        $seconds = (int) now()->diffInSeconds($run->started_at, false);

        return $this->formatDuration(abs($seconds));
    }

    /**
     * Format duration in human-readable format.
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            $minutes = (int) ($seconds / 60);
            $secs = $seconds % 60;

            return sprintf('%dm %ds', $minutes, $secs);
        }

        $hours = (int) ($seconds / 3600);
        $minutes = (int) (($seconds % 3600) / 60);

        return sprintf('%dh %dm', $hours, $minutes);
    }

    /**
     * Get complexity display with icon.
     */
    private function getComplexityDisplay(Task $task): string
    {
        $complexity = $task->complexity ?? 'simple';
        $char = match ($complexity) {
            'trivial' => 't',
            'simple' => 's',
            'moderate' => 'm',
            'complex' => 'c',
            default => 's',
        };

        return $complexity.' ('.$char.')';
    }

    /**
     * Get task icons (consume, failed, selfguided).
     */
    private function getTaskIcons(Task $task): string
    {
        $icons = [];

        if (! empty($task->consumed)) {
            $icons[] = '⚡';
        }

        $taskService = app(TaskService::class);
        if ($taskService->isFailed($task)) {
            $icons[] = '🪫';
        }

        if ($task->type === 'selfguided') {
            $icons[] = '◉';
        }

        return $icons !== [] ? ' '.implode(' ', $icons) : '';
    }

    /**
     * Get in-progress tasks data for JSON output.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getInProgressTasksData(Collection $inProgress): array
    {
        return $inProgress->map(function (Task $task): array {
            $activeRun = $this->getActiveRun($task);
            $runningSeconds = null;

            if ($activeRun && $activeRun->started_at) {
                $runningSeconds = abs((int) now()->diffInSeconds($activeRun->started_at, false));
            }

            return [
                'id' => $task->short_id,
                'title' => $task->title,
                'agent' => $activeRun?->agent ?? 'unknown',
                'running_seconds' => $runningSeconds,
                'complexity' => $task->complexity ?? 'simple',
                'epic_id' => $task->epic_id,
                'epic_title' => $task->epic?->title,
                'consumed' => ! empty($task->consumed),
                'failed' => app(TaskService::class)->isFailed($task),
                'selfguided' => $task->type === 'selfguided',
            ];
        })->toArray();
    }
}
