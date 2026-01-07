<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Commands\Concerns\RendersBoardColumns;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class BoardCommand extends Command
{
    use HandlesJsonOutput;
    use RendersBoardColumns;

    protected $signature = 'board
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--once : Show board once and exit (disables live mode)}
        {--interval=2 : Refresh interval in seconds when watching}';

    protected $description = 'Display tasks in a kanban board layout (live by default)';

    private int $readyWidth;

    private int $inProgressWidth;

    private int $blockedWidth;

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        // Live mode by default, unless --once is passed or --json is used
        if (! $this->option('once') && ! $this->option('json')) {
            return $this->watchMode($taskService);
        }

        return $this->renderBoard($taskService);
    }

    private function renderBoard(TaskService $taskService): int
    {
        $readyTasks = $taskService->ready();
        $readyIds = $readyTasks->pluck('id')->toArray();

        $allTasks = $taskService->all();

        $inProgressTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'in_progress')
            ->sortByDesc('updated_at')
            ->values();

        $blockedTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'open' && ! in_array($t['id'], $readyIds))
            ->values();

        $doneTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'closed')
            ->sortByDesc('updated_at')
            ->take(10)
            ->values();

        if ($this->option('json')) {
            $this->outputJson([
                'ready' => $readyTasks->values()->toArray(),
                'in_progress' => $inProgressTasks->toArray(),
                'blocked' => $blockedTasks->toArray(),
                'done' => $doneTasks->toArray(),
            ]);

            return self::SUCCESS;
        }

        $this->calculateColumnWidths($readyTasks->count(), $inProgressTasks->count(), $blockedTasks->count());

        $readyColumn = $this->buildColumn('Ready', $readyTasks->all(), $this->readyWidth);
        $inProgressColumn = $this->buildColumn('In Progress', $inProgressTasks->all(), $this->inProgressWidth);
        $blockedColumn = $this->buildColumn('Blocked', $blockedTasks->all(), $this->blockedWidth);

        $maxHeight = max(count($readyColumn), count($inProgressColumn), count($blockedColumn));

        $readyColumn = $this->padColumn($readyColumn, $maxHeight, $this->readyWidth);
        $inProgressColumn = $this->padColumn($inProgressColumn, $maxHeight, $this->inProgressWidth);
        $blockedColumn = $this->padColumn($blockedColumn, $maxHeight, $this->blockedWidth);

        $rows = array_map(null, $readyColumn, $inProgressColumn, $blockedColumn);

        foreach ($rows as $row) {
            $this->line(implode('  ', $row));
        }

        // Show recently done tasks as a single line below the board
        if ($doneTasks->isNotEmpty()) {
            $this->newLine();
            $this->renderDoneLine($doneTasks->all());
        }

        return self::SUCCESS;
    }

    private function watchMode(TaskService $taskService): int
    {
        $storagePath = $taskService->getStoragePath();
        $interval = max(1, (int) $this->option('interval'));

        // Check if we're in a TTY (interactive terminal)
        $isTty = stream_isatty(STDOUT);

        if ($isTty) {
            // Enter alternate buffer
            $this->getOutput()->write("\033[?1049h");
            $this->getOutput()->write("\033[H\033[2J");
        }

        // Set up signal handlers
        $exiting = false;
        $shouldRefresh = false;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$exiting) {
                $exiting = true;
            });
            pcntl_signal(SIGTERM, function () use (&$exiting) {
                $exiting = true;
            });
            // Handle window resize (SIGWINCH)
            if (defined('SIGWINCH')) {
                pcntl_signal(SIGWINCH, function () use (&$shouldRefresh) {
                    $shouldRefresh = true;
                });
            }
        }

        $lastModified = file_exists($storagePath) ? @filemtime($storagePath) : false;
        $lastRender = 0;
        $lastContentHash = null;

        try {
            // Initial render
            $lastContentHash = $this->getBoardContentHash($taskService);
            $this->refreshBoard($taskService);
            $lastRender = time();

            while (true) {
                // Check if we should exit
                if ($exiting) {
                    break;
                }

                // Handle signals if pcntl is available (check before file check to catch SIGWINCH quickly)
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                // Check for file changes (lightweight - filemtime() only reads inode metadata, not file contents)
                $currentModified = file_exists($storagePath) ? @filemtime($storagePath) : false;
                $needsRefresh = false;

                // Refresh if file changed AND content actually changed
                if ($currentModified !== false && $currentModified !== $lastModified) {
                    $lastModified = $currentModified;
                    $currentContentHash = $this->getBoardContentHash($taskService);
                    if ($currentContentHash !== $lastContentHash) {
                        $lastContentHash = $currentContentHash;
                        $needsRefresh = true;
                    }
                } elseif (time() - $lastRender >= $interval) {
                    // Periodic check - verify content changed before refreshing
                    $currentContentHash = $this->getBoardContentHash($taskService);
                    if ($currentContentHash !== $lastContentHash) {
                        $lastContentHash = $currentContentHash;
                        $needsRefresh = true;
                    }
                } elseif ($shouldRefresh) {
                    // SIGWINCH received - force refresh and reset flag (terminal resize)
                    $needsRefresh = true;
                    $shouldRefresh = false;
                }

                if ($needsRefresh) {
                    $this->refreshBoard($taskService);
                    $lastContentHash = $this->getBoardContentHash($taskService);
                    $lastRender = time();
                }

                // Sleep briefly to avoid CPU spinning (100ms)
                usleep(100000);
            }
        } catch (\Exception $e) {
            // Fall through to cleanup
        } finally {
            if (stream_isatty(STDOUT)) {
                $this->exitWatchMode();
            }
        }

        return self::SUCCESS;
    }

    /**
     * Generate a hash representing the current board content.
     */
    private function getBoardContentHash(TaskService $taskService): string
    {
        $readyTasks = $taskService->ready();
        $readyIds = $readyTasks->pluck('id')->toArray();

        $allTasks = $taskService->all();

        $inProgressTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'in_progress')
            ->sortByDesc('updated_at')
            ->values();

        $blockedTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'open' && ! in_array($t['id'], $readyIds))
            ->values();

        $doneTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'closed')
            ->sortByDesc('updated_at')
            ->take(10)
            ->values();

        return $this->hashBoardContent([
            'ready' => $readyTasks->pluck('id')->toArray(),
            'in_progress' => $inProgressTasks->pluck('id')->toArray(),
            'blocked' => $blockedTasks->pluck('id')->toArray(),
            'done' => $doneTasks->pluck('id')->toArray(),
        ]);
    }

    private function refreshBoard(TaskService $taskService): void
    {
        // Move cursor to home without clearing - overwrites in place to avoid flicker
        if (stream_isatty(STDOUT)) {
            $this->getOutput()->write("\033[H");
        } else {
            // In non-TTY, just output newlines to separate refreshes
            $this->newLine();
            $this->line('<fg=yellow>--- Refresh ---</>');
            $this->newLine();
        }

        // Render the board
        $readyTasks = $taskService->ready();
        $readyIds = $readyTasks->pluck('id')->toArray();

        $allTasks = $taskService->all();

        $inProgressTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'in_progress')
            ->sortByDesc('updated_at')
            ->values();

        $blockedTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'open' && ! in_array($t['id'], $readyIds))
            ->values();

        $doneTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'closed')
            ->sortByDesc('updated_at')
            ->take(10)
            ->values();

        $this->calculateColumnWidths($readyTasks->count(), $inProgressTasks->count(), $blockedTasks->count());

        $readyColumn = $this->buildColumn('Ready', $readyTasks->all(), $this->readyWidth);
        $inProgressColumn = $this->buildColumn('In Progress', $inProgressTasks->all(), $this->inProgressWidth);
        $blockedColumn = $this->buildColumn('Blocked', $blockedTasks->all(), $this->blockedWidth);

        $maxHeight = max(count($readyColumn), count($inProgressColumn), count($blockedColumn));

        $readyColumn = $this->padColumn($readyColumn, $maxHeight, $this->readyWidth);
        $inProgressColumn = $this->padColumn($inProgressColumn, $maxHeight, $this->inProgressWidth);
        $blockedColumn = $this->padColumn($blockedColumn, $maxHeight, $this->blockedWidth);

        $rows = array_map(null, $readyColumn, $inProgressColumn, $blockedColumn);

        foreach ($rows as $row) {
            $this->line(implode('  ', $row));
        }

        // Show recently done tasks as a single line below the board
        if ($doneTasks->isNotEmpty()) {
            $this->newLine();
            $this->renderDoneLine($doneTasks->all());
        }

        // Show footer with refresh info
        $this->newLine();
        $this->line('<fg=gray>Press Ctrl+C to exit | Watching for changes...</>');
    }

    private function exitWatchMode(): void
    {
        // Exit alternate buffer
        $this->getOutput()->write("\033[?1049l");
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<int, string>
     */
    private function buildColumn(string $title, array $tasks, int $width): array
    {
        $lines = [];

        $lines[] = $this->padLine("<fg=white;options=bold>{$title}</> (".count($tasks).')', $width);
        $lines[] = str_repeat('─', $width);

        if (empty($tasks)) {
            $lines[] = $this->padLine('<fg=gray>No tasks</>', $width);
        } else {
            foreach ($tasks as $task) {
                $id = (string) $task['id'];
                $taskTitle = (string) $task['title'];
                $shortId = substr($id, 5, 4); // Skip 'fuel-' prefix

                // Show icon for tasks being consumed by fuel consume
                $consumeIcon = ! empty($task['consumed']) ? '⚡' : '';
                
                // Show icon for tasks with needs-human label
                $labels = $task['labels'] ?? [];
                $needsHumanIcon = is_array($labels) && in_array('needs-human', $labels, true) ? '👤' : '';
                
                // Show icon for stuck tasks (consumed=true && consumed_exit_code != 0)
                $exitCode = $task['consumed_exit_code'] ?? null;
                $stuckIcon = (! empty($task['consumed']) && $exitCode !== null && $exitCode !== 0) ? '❌' : '';
                
                // Show icon for tasks that are in_progress but PID is not running
                $pidStuckIcon = '';
                if ($task['status'] === 'in_progress' && isset($task['consume_pid']) && $task['consume_pid'] !== null) {
                    $pid = (int) $task['consume_pid'];
                    if (! $this->isPidRunning($pid)) {
                        $pidStuckIcon = '🪫'; // Low battery emoji for stuck/dead process
                    }
                }
                
                // Build icon string (all icons if present)
                $icons = array_filter([$consumeIcon, $needsHumanIcon, $stuckIcon, $pidStuckIcon]);
                $iconString = implode(' ', $icons);
                $iconWidth = $iconString !== '' ? mb_strlen($iconString) + 1 : 0; // emoji(s) + space
                $truncatedTitle = $this->truncate($taskTitle, $width - 7 - $iconWidth);

                if ($iconString !== '') {
                    $lines[] = $this->padLine("<fg=cyan>[{$shortId}]</> {$iconString} {$truncatedTitle}", $width);
                } else {
                    $lines[] = $this->padLine("<fg=cyan>[{$shortId}]</> {$truncatedTitle}", $width);
                }
            }
        }

        return $lines;
    }

    private function calculateColumnWidths(int $readyCount, int $inProgressCount, int $blockedCount): void
    {
        $terminalWidth = $this->getTerminalWidth();

        // Available width minus spacing between columns (2 spaces × 2 gaps for 3 columns)
        $availableWidth = $terminalWidth - 4;

        // Minimum width for header (enough for "Ready (0)" or "In Progress (0)")
        $minWidth = 15;

        // Count how many columns have content
        $columnsWithContent = 0;
        if ($readyCount > 0) {
            $columnsWithContent++;
        }
        if ($inProgressCount > 0) {
            $columnsWithContent++;
        }
        if ($blockedCount > 0) {
            $columnsWithContent++;
        }

        // If all columns have content or no columns have content, distribute evenly
        if ($columnsWithContent === 3 || $columnsWithContent === 0) {
            $widthPerColumn = (int) ($availableWidth / 3);
            $this->readyWidth = $widthPerColumn;
            $this->inProgressWidth = $widthPerColumn;
            $this->blockedWidth = $widthPerColumn;

            return;
        }

        // Some columns are empty: empty columns get minimum width, others share remaining space
        $emptyColumns = 3 - $columnsWithContent;
        $totalMinWidth = $minWidth * $emptyColumns;
        $remainingWidth = max(0, $availableWidth - $totalMinWidth);
        $widthPerContentColumn = (int) ($remainingWidth / $columnsWithContent);

        // Distribute width - columns with content get more space
        $this->readyWidth = $readyCount > 0
            ? $widthPerContentColumn
            : $minWidth;

        $this->inProgressWidth = $inProgressCount > 0
            ? $widthPerContentColumn
            : $minWidth;

        $this->blockedWidth = $blockedCount > 0
            ? $widthPerContentColumn
            : $minWidth;
    }

    /**
     * @param  array<int, array<string, mixed>>  $doneTasks
     */
    private function renderDoneLine(array $doneTasks): void
    {
        $terminalWidth = $this->getTerminalWidth();
        $prefix = '<fg=gray>Recently done:</> ';
        $prefixLength = $this->visibleLength($prefix);

        // Available width for task items
        $availableWidth = $terminalWidth - $prefixLength;

        $items = [];
        $currentLength = 0;
        $separator = '<fg=gray> | </>';

        foreach ($doneTasks as $task) {
            $id = (string) $task['id'];
            $title = (string) $task['title'];
            $shortId = substr($id, 5, 4); // Skip 'fuel-' prefix

            // Calculate separator length if not first item
            $separatorLength = count($items) > 0 ? $this->visibleLength($separator) : 0;

            // Build ID part to calculate its visible length
            $idPart = "<fg=cyan>[{$shortId}]</> ";
            $idPartLength = $this->visibleLength($idPart);

            // Calculate how much space we have for the title
            $titleMaxLength = $availableWidth - $currentLength - $separatorLength - $idPartLength;

            if ($titleMaxLength < 5) {
                // Not enough space for another item, stop
                break;
            }

            $truncatedTitle = $this->truncateTitle($title, $titleMaxLength);
            $item = $idPart.$truncatedTitle;
            $itemLength = $this->visibleLength($item);

            // Check if this item fits
            if ($currentLength + $separatorLength + $itemLength > $availableWidth) {
                break;
            }

            $items[] = $item;
            $currentLength += $separatorLength + $itemLength;
        }

        if (empty($items)) {
            return;
        }

        $line = $prefix.implode($separator, $items);
        $this->line($line);
    }

    private function truncateTitle(string $title, int $maxLength): string
    {
        // Truncate to first 3 words for done tasks
        $words = preg_split('/\s+/', trim($title));
        if (count($words) <= 3) {
            $truncated = $title;
        } else {
            $truncated = implode(' ', array_slice($words, 0, 3)).'...';
        }

        // If the 3-word version is still too long, truncate further
        if (mb_strlen($truncated) > $maxLength) {
            return mb_substr($truncated, 0, $maxLength - 3).'...';
        }

        return $truncated;
    }

    /**
     * Check if a process with the given PID is running.
     *
     * @param  int  $pid
     * @return bool
     */
    private function isPidRunning(int $pid): bool
    {
        // Use posix_kill with signal 0 to check if process exists
        // Returns true if process exists, false otherwise
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        // Fallback: check /proc on Linux
        if (PHP_OS_FAMILY === 'Linux' && is_dir('/proc')) {
            return is_dir("/proc/{$pid}");
        }

        // Fallback: use ps command on Unix-like systems
        if (PHP_OS_FAMILY !== 'Windows') {
            $output = @shell_exec("ps -p {$pid} -o pid= 2>/dev/null");
            return ! empty(trim($output ?? ''));
        }

        // Windows fallback: use tasklist
        if (PHP_OS_FAMILY === 'Windows') {
            $output = @shell_exec("tasklist /FI \"PID eq {$pid}\" 2>NUL");
            return str_contains($output ?? '', (string) $pid);
        }

        // If we can't check, assume it's running (conservative approach)
        return true;
    }
}
