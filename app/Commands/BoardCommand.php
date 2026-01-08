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

    private int $topColumnWidth;

    private int $bottomColumnWidth;

    private TaskService $taskService;

    public function handle(TaskService $taskService): int
    {
        $this->taskService = $taskService;
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

        // Blocked tasks exclude needs-human (those are shown in a separate line)
        $blockedTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'open' && ! in_array($t['id'], $readyIds))
            ->filter(fn (array $t) => ! is_array($t['labels'] ?? null) || ! in_array('needs-human', $t['labels'], true))
            ->values();

        // Needs-human tasks (open tasks with needs-human label)
        $humanTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'open')
            ->filter(fn (array $t) => is_array($t['labels'] ?? null) && in_array('needs-human', $t['labels'], true))
            ->values();

        $doneTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'closed')
            ->sortByDesc('updated_at')
            ->values();

        if ($this->option('json')) {
            $this->outputJson([
                'ready' => $readyTasks->values()->toArray(),
                'in_progress' => $inProgressTasks->toArray(),
                'blocked' => $blockedTasks->toArray(),
                'human' => $humanTasks->toArray(),
                'done' => $doneTasks->toArray(),
            ]);

            return self::SUCCESS;
        }

        $this->calculateColumnWidths();

        // Top row: Ready and In Progress (show up to 10 each, but header shows total count)
        $readyColumn = $this->buildColumn('Ready', $readyTasks->take(10)->all(), $this->topColumnWidth, $readyTasks->count());
        $inProgressColumn = $this->buildColumn('In Progress', $inProgressTasks->take(10)->all(), $this->topColumnWidth, $inProgressTasks->count());

        $topMaxHeight = max(count($readyColumn), count($inProgressColumn));
        $readyColumn = $this->padColumn($readyColumn, $topMaxHeight, $this->topColumnWidth);
        $inProgressColumn = $this->padColumn($inProgressColumn, $topMaxHeight, $this->topColumnWidth);

        $topRows = array_map(null, $readyColumn, $inProgressColumn);
        foreach ($topRows as $row) {
            $this->line(implode('  ', $row));
        }

        $this->newLine();

        // Bottom row: Blocked and Done (show up to 3 each, but header shows total count)
        $blockedColumn = $this->buildColumn('Blocked', $blockedTasks->take(3)->all(), $this->bottomColumnWidth, $blockedTasks->count(), 'blocked');
        $doneColumn = $this->buildColumn('Done', $doneTasks->take(3)->all(), $this->bottomColumnWidth, $doneTasks->count(), 'done');

        $bottomMaxHeight = max(count($blockedColumn), count($doneColumn));
        $blockedColumn = $this->padColumn($blockedColumn, $bottomMaxHeight, $this->bottomColumnWidth);
        $doneColumn = $this->padColumn($doneColumn, $bottomMaxHeight, $this->bottomColumnWidth);

        $bottomRows = array_map(null, $blockedColumn, $doneColumn);
        foreach ($bottomRows as $row) {
            $this->line(implode('  ', $row));
        }

        // Show needs-human tasks as a single line below the board
        if ($humanTasks->isNotEmpty()) {
            $this->newLine();
            $this->renderHumanLine($humanTasks->all());
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
            ->filter(fn (array $t) => ! is_array($t['labels'] ?? null) || ! in_array('needs-human', $t['labels'], true))
            ->values();

        $humanTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'open')
            ->filter(fn (array $t) => is_array($t['labels'] ?? null) && in_array('needs-human', $t['labels'], true))
            ->values();

        $doneTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'closed')
            ->sortByDesc('updated_at')
            ->values();

        return $this->hashBoardContent([
            'ready' => $readyTasks->pluck('id')->toArray(),
            'in_progress' => $inProgressTasks->pluck('id')->toArray(),
            'blocked' => $blockedTasks->pluck('id')->toArray(),
            'human' => $humanTasks->pluck('id')->toArray(),
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

        // Blocked tasks exclude needs-human (those are shown in a separate line)
        $blockedTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'open' && ! in_array($t['id'], $readyIds))
            ->filter(fn (array $t) => ! is_array($t['labels'] ?? null) || ! in_array('needs-human', $t['labels'], true))
            ->values();

        // Needs-human tasks (open tasks with needs-human label)
        $humanTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'open')
            ->filter(fn (array $t) => is_array($t['labels'] ?? null) && in_array('needs-human', $t['labels'], true))
            ->values();

        $doneTasks = $allTasks
            ->filter(fn (array $t) => $t['status'] === 'closed')
            ->sortByDesc('updated_at')
            ->values();

        $this->calculateColumnWidths();

        // Top row: Ready and In Progress (show up to 10 each)
        $readyColumn = $this->buildColumn('Ready', $readyTasks->take(10)->all(), $this->topColumnWidth, $readyTasks->count());
        $inProgressColumn = $this->buildColumn('In Progress', $inProgressTasks->take(10)->all(), $this->topColumnWidth, $inProgressTasks->count());

        $topMaxHeight = max(count($readyColumn), count($inProgressColumn));
        $readyColumn = $this->padColumn($readyColumn, $topMaxHeight, $this->topColumnWidth);
        $inProgressColumn = $this->padColumn($inProgressColumn, $topMaxHeight, $this->topColumnWidth);

        $topRows = array_map(null, $readyColumn, $inProgressColumn);
        foreach ($topRows as $row) {
            $this->line(implode('  ', $row));
        }

        $this->newLine();

        // Bottom row: Blocked and Done (show up to 3 each)
        $blockedColumn = $this->buildColumn('Blocked', $blockedTasks->take(3)->all(), $this->bottomColumnWidth, $blockedTasks->count(), 'blocked');
        $doneColumn = $this->buildColumn('Done', $doneTasks->take(3)->all(), $this->bottomColumnWidth, $doneTasks->count(), 'done');

        $bottomMaxHeight = max(count($blockedColumn), count($doneColumn));
        $blockedColumn = $this->padColumn($blockedColumn, $bottomMaxHeight, $this->bottomColumnWidth);
        $doneColumn = $this->padColumn($doneColumn, $bottomMaxHeight, $this->bottomColumnWidth);

        $bottomRows = array_map(null, $blockedColumn, $doneColumn);
        foreach ($bottomRows as $row) {
            $this->line(implode('  ', $row));
        }

        // Show needs-human tasks as a single line below the board
        if ($humanTasks->isNotEmpty()) {
            $this->newLine();
            $this->renderHumanLine($humanTasks->all());
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
     * @param  string  $style  Column style: 'normal', 'blocked', or 'done'
     * @return array<int, string>
     */
    private function buildColumn(string $title, array $tasks, int $width, int $totalCount, string $style = 'normal'): array
    {
        $lines = [];

        $lines[] = $this->padLine("<fg=white;options=bold>{$title}</> ({$totalCount})", $width);
        $lines[] = str_repeat('─', $width);

        if (empty($tasks)) {
            $lines[] = $this->padLine('<fg=gray>No tasks</>', $width);
        } else {
            foreach ($tasks as $task) {
                $id = (string) $task['id'];
                $taskTitle = (string) $task['title'];
                $shortId = substr($id, 2, 6); // Skip 'f-' prefix
                $complexityChar = $this->getComplexityChar($task);

                // Show icon for tasks being consumed by fuel consume
                $consumeIcon = ! empty($task['consumed']) ? '⚡' : '';

                // Show icon for failed tasks
                $isPidDead = fn (int $pid): bool => ! $this->isPidRunning($pid);
                $failedIcon = $this->taskService->isFailed($task, $isPidDead) ? '🪫' : '';

                // Build icon string (all icons if present)
                $icons = array_filter([$consumeIcon, $failedIcon]);
                $iconString = implode(' ', $icons);
                // Each emoji displays as 2 chars wide + 1 space after = 3 per icon
                $iconWidth = count($icons) * 3;
                // Account for complexity char (2 chars: middle dot + char)
                $truncatedTitle = $this->truncate($taskTitle, $width - 11 - $iconWidth);

                // Apply style-specific colors
                $idColor = match ($style) {
                    'blocked' => 'fg=#b36666',  // Dimmer reddish for blocked
                    'done' => 'fg=#888888',     // Gray for done
                    default => 'fg=cyan',
                };
                $titleColor = $style === 'done' ? '<fg=#888888>' : '';
                $titleEnd = $style === 'done' ? '</>' : '';

                if ($iconString !== '') {
                    $lines[] = $this->padLine("<{$idColor}>[{$shortId}·{$complexityChar}]</> {$iconString} {$titleColor}{$truncatedTitle}{$titleEnd}", $width);
                } else {
                    $lines[] = $this->padLine("<{$idColor}>[{$shortId}·{$complexityChar}]</> {$titleColor}{$truncatedTitle}{$titleEnd}", $width);
                }
            }
        }

        return $lines;
    }

    private function calculateColumnWidths(): void
    {
        $terminalWidth = $this->getTerminalWidth();

        // Top row: 2 columns with 2 spaces gap
        $this->topColumnWidth = (int) (($terminalWidth - 2) / 2);

        // Bottom row: 2 columns with 2 spaces gap (same width as top)
        $this->bottomColumnWidth = $this->topColumnWidth;
    }

    /**
     * @param  array<int, array<string, mixed>>  $humanTasks
     */
    private function renderHumanLine(array $humanTasks): void
    {
        $terminalWidth = $this->getTerminalWidth();
        $prefix = '<fg=yellow>👤 Needs human:</> ';
        $prefixLength = $this->visibleLength($prefix);

        // Available width for task items
        $availableWidth = $terminalWidth - $prefixLength;

        $items = [];
        $currentLength = 0;
        $separator = '<fg=gray> | </>';

        foreach ($humanTasks as $task) {
            $id = (string) $task['id'];
            $title = (string) $task['title'];
            $shortId = substr($id, 2, 6); // Skip 'f-' prefix

            // Calculate separator length if not first item
            $separatorLength = count($items) > 0 ? $this->visibleLength($separator) : 0;

            // Build ID part to calculate its visible length (use yellow for human tasks)
            $idPart = "<fg=yellow>[{$shortId}]</> ";
            $idPartLength = $this->visibleLength($idPart);

            // Calculate how much space we have for the title
            $titleMaxLength = $availableWidth - $currentLength - $separatorLength - $idPartLength;

            if ($titleMaxLength < 5) {
                // Not enough space for another item, stop
                break;
            }

            $truncatedTitle = $this->truncateHumanTitle($title, $titleMaxLength);
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

    private function truncateHumanTitle(string $title, int $maxLength): string
    {
        // Truncate to first 3 words for human tasks
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

    /**
     * Get a single character representing task complexity.
     *
     * @param  array<string, mixed>  $task
     */
    private function getComplexityChar(array $task): string
    {
        $complexity = $task['complexity'] ?? 'simple';

        return match ($complexity) {
            'trivial' => 't',
            'simple' => 's',
            'moderate' => 'm',
            'complex' => 'c',
            default => 's',
        };
    }
}
