<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Commands\Concerns\RendersBoardColumns;
use App\Contracts\AgentHealthTrackerInterface;
use App\Models\Task;
use App\Services\ConfigService;
use App\Services\TaskService;
use Illuminate\Support\Collection;
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

    public function handle(
        TaskService $taskService,
        ?ConfigService $configService = null,
        ?AgentHealthTrackerInterface $healthTracker = null
    ): int {
        $this->taskService = $taskService;
        $this->configureCwd($taskService);

        // Live mode by default, unless --once is passed or --json is used
        if (! $this->option('once') && ! $this->option('json')) {
            return $this->watchMode($taskService, $configService, $healthTracker);
        }

        return $this->renderBoard($taskService, $configService, $healthTracker);
    }

    private function renderBoard(
        TaskService $taskService,
        ?ConfigService $configService = null,
        ?AgentHealthTrackerInterface $healthTracker = null
    ): int {
        $boardData = $this->getBoardData($taskService);
        $readyTasks = $boardData['ready'];
        $inProgressTasks = $boardData['in_progress'];
        $reviewTasks = $boardData['review'];
        $blockedTasks = $boardData['blocked'];
        $humanTasks = $boardData['human'];
        $doneTasks = $boardData['done'];

        if ($this->option('json')) {
            $this->outputJson([
                'ready' => $readyTasks->values()->map(fn (Task $task): array => $task->toArray())->toArray(),
                'in_progress' => $inProgressTasks->map(fn (Task $task): array => $task->toArray())->toArray(),
                'review' => $reviewTasks->map(fn (Task $task): array => $task->toArray())->toArray(),
                'blocked' => $blockedTasks->map(fn (Task $task): array => $task->toArray())->toArray(),
                'human' => $humanTasks->map(fn (Task $task): array => $task->toArray())->toArray(),
                'done' => $doneTasks->map(fn (Task $task): array => $task->toArray())->toArray(),
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

        // Middle row: Review (show up to 10, but header shows total count)
        $reviewColumn = $this->buildColumn('Review', $reviewTasks->take(10)->all(), $this->topColumnWidth * 2 + 2, $reviewTasks->count(), 'review');
        foreach ($reviewColumn as $line) {
            $this->line($line);
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

        // Show agent health summary
        $this->renderHealthSummary($configService, $healthTracker);

        return self::SUCCESS;
    }

    private function watchMode(
        TaskService $taskService,
        ?ConfigService $configService = null,
        ?AgentHealthTrackerInterface $healthTracker = null
    ): int {
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
            pcntl_signal(SIGINT, function () use (&$exiting): void {
                $exiting = true;
            });
            pcntl_signal(SIGTERM, function () use (&$exiting): void {
                $exiting = true;
            });
            // Handle window resize (SIGWINCH)
            if (defined('SIGWINCH')) {
                pcntl_signal(SIGWINCH, function () use (&$shouldRefresh): void {
                    $shouldRefresh = true;
                });
            }
        }

        $lastRender = 0;
        $lastContentHash = null;

        try {
            // Initial render
            $lastContentHash = $this->getBoardContentHash($taskService);
            $this->refreshBoard($taskService, $configService, $healthTracker);
            $lastRender = time();

            while (true) {
                // Check if we should exit
                if ($exiting) {
                    break;
                }

                // Handle signals if pcntl is available
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                $needsRefresh = false;

                if ($shouldRefresh) {
                    // SIGWINCH received - force refresh (terminal resize)
                    $needsRefresh = true;
                    $shouldRefresh = false;
                } elseif (time() - $lastRender >= $interval) {
                    // Periodic check - refresh if content changed
                    $currentContentHash = $this->getBoardContentHash($taskService);
                    if ($currentContentHash !== $lastContentHash) {
                        $lastContentHash = $currentContentHash;
                        $needsRefresh = true;
                    }
                }

                if ($needsRefresh) {
                    $this->refreshBoard($taskService, $configService, $healthTracker);
                    $lastContentHash = $this->getBoardContentHash($taskService);
                    $lastRender = time();
                }

                // Sleep briefly to avoid CPU spinning (100ms)
                usleep(100000);
            }
        } catch (\Exception) {
            // Fall through to cleanup
        } finally {
            if (stream_isatty(STDOUT)) {
                $this->exitWatchMode();
            }
        }

        return self::SUCCESS;
    }

    /**
     * Get all board data from a single snapshot (prevents race conditions).
     *
     * @return array{ready: Collection, in_progress: Collection, review: Collection, blocked: Collection, human: Collection, done: Collection}
     */
    private function getBoardData(TaskService $taskService): array
    {
        // Single read - all computations use this snapshot
        $allTasks = $taskService->all();

        $readyTasks = $taskService->readyFrom($allTasks);
        $readyIds = $readyTasks->pluck('id')->toArray();

        $inProgressTasks = $allTasks
            ->filter(fn (Task $t): bool => $t->status === 'in_progress')
            ->sortByDesc('updated_at')
            ->values();

        $reviewTasks = $allTasks
            ->filter(fn (Task $t): bool => $t->status === 'review')
            ->sortByDesc('updated_at')
            ->values();

        // Blocked tasks exclude needs-human (those are shown in a separate line)
        $blockedTasks = $allTasks
            ->filter(fn (Task $t): bool => $t->status === 'open' && ! in_array($t->id, $readyIds, true))
            ->filter(function (Task $t): bool {
                $labels = $t->labels ?? [];
                if (! is_array($labels)) {
                    return true;
                }

                return ! in_array('needs-human', $labels, true);
            })
            ->values();

        // Needs-human tasks (open tasks with needs-human label)
        $humanTasks = $allTasks
            ->filter(fn (Task $t): bool => $t->status === 'open')
            ->filter(function (Task $t): bool {
                $labels = $t->labels ?? [];

                return is_array($labels) && in_array('needs-human', $labels, true);
            })
            ->values();

        $doneTasks = $allTasks
            ->filter(fn (Task $t): bool => $t->status === 'closed')
            ->sortByDesc('updated_at')
            ->values();

        return [
            'ready' => $readyTasks,
            'in_progress' => $inProgressTasks,
            'review' => $reviewTasks,
            'blocked' => $blockedTasks,
            'human' => $humanTasks,
            'done' => $doneTasks,
        ];
    }

    /**
     * Generate a hash representing the current board content.
     */
    private function getBoardContentHash(TaskService $taskService): string
    {
        $boardData = $this->getBoardData($taskService);

        return $this->hashBoardContent([
            'ready' => $boardData['ready']->pluck('id')->toArray(),
            'in_progress' => $boardData['in_progress']->pluck('id')->toArray(),
            'review' => $boardData['review']->pluck('id')->toArray(),
            'blocked' => $boardData['blocked']->pluck('id')->toArray(),
            'human' => $boardData['human']->pluck('id')->toArray(),
            'done' => $boardData['done']->pluck('id')->toArray(),
        ]);
    }

    private function refreshBoard(
        TaskService $taskService,
        ?ConfigService $configService = null,
        ?AgentHealthTrackerInterface $healthTracker = null
    ): void {
        // Move cursor to home without clearing - overwrites in place to avoid flicker
        if (stream_isatty(STDOUT)) {
            $this->getOutput()->write("\033[H");
        } else {
            // In non-TTY, just output newlines to separate refreshes
            $this->newLine();
            $this->line('<fg=yellow>--- Refresh ---</>');
            $this->newLine();
        }

        // Get all board data from a single snapshot
        $boardData = $this->getBoardData($taskService);
        $readyTasks = $boardData['ready'];
        $inProgressTasks = $boardData['in_progress'];
        $reviewTasks = $boardData['review'];
        $blockedTasks = $boardData['blocked'];
        $humanTasks = $boardData['human'];
        $doneTasks = $boardData['done'];

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

        // Middle row: Review (show up to 10, but header shows total count)
        $reviewColumn = $this->buildColumn('Review', $reviewTasks->take(10)->all(), $this->topColumnWidth * 2 + 2, $reviewTasks->count(), 'review');
        foreach ($reviewColumn as $line) {
            $this->line($line);
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

        // Show agent health summary
        $this->renderHealthSummary($configService, $healthTracker);

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
     * @param  array<int, Task>  $tasks
     * @param  string  $style  Column style: 'normal', 'blocked', or 'done'
     * @return array<int, string>
     */
    private function buildColumn(string $title, array $tasks, int $width, int $totalCount, string $style = 'normal'): array
    {
        $lines = [];

        $lines[] = $this->padLine(sprintf('<fg=white;options=bold>%s</> (%d)', $title, $totalCount), $width);
        $lines[] = str_repeat('─', $width);

        if ($tasks === []) {
            $lines[] = $this->padLine('<fg=gray>No tasks</>', $width);
        } else {
            foreach ($tasks as $task) {
                $id = (string) $task->id;
                $taskTitle = (string) $task->title;
                $shortId = substr($id, 2, 6); // Skip 'f-' prefix
                $complexityChar = $this->getComplexityChar($task);

                // Show icon for tasks being consumed by fuel consume
                $consumeIcon = empty($task->consumed) ? '' : '⚡';

                // Show icon for failed tasks
                $failedIcon = $this->taskService->isFailed($task) ? '🪫' : '';

                // Show icon for auto-closed tasks (only in done column)
                $autoClosedIcon = '';
                if ($style === 'done') {
                    $labels = $task->labels ?? [];
                    $autoClosedIcon = is_array($labels) && in_array('auto-closed', $labels, true) ? '🤖' : '';
                }

                // Build icon string (all icons if present)
                $icons = array_filter([$consumeIcon, $failedIcon, $autoClosedIcon]);
                $iconString = implode(' ', $icons);
                // Each emoji displays as 2 chars wide + 1 space after = 3 per icon
                $iconWidth = count($icons) * 3;
                // Account for complexity char (2 chars: middle dot + char)
                $truncatedTitle = $this->truncate($taskTitle, $width - 11 - $iconWidth);

                // Apply style-specific colors
                $idColor = match ($style) {
                    'blocked' => 'fg=#b36666',  // Dimmer reddish for blocked
                    'done' => 'fg=#888888',     // Gray for done
                    'review' => 'fg=yellow',    // Yellow/warning for review
                    default => 'fg=cyan',
                };
                $titleColor = match ($style) {
                    'done' => '<fg=#888888>',
                    'review' => '<fg=yellow>',
                    default => '',
                };
                $titleEnd = ($style === 'done' || $style === 'review') ? '</>' : '';

                if ($iconString !== '') {
                    $lines[] = $this->padLine(sprintf('<%s>[%s ·%s]</> %s %s%s%s', $idColor, $shortId, $complexityChar, $iconString, $titleColor, $truncatedTitle, $titleEnd), $width);
                } else {
                    $lines[] = $this->padLine(sprintf('<%s>[%s ·%s]</> %s%s%s', $idColor, $shortId, $complexityChar, $titleColor, $truncatedTitle, $titleEnd), $width);
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
     * @param  array<int, Task>  $humanTasks
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
            $id = (string) $task->id;
            $title = (string) $task->title;
            $shortId = substr($id, 2, 6); // Skip 'f-' prefix

            // Calculate separator length if not first item
            $separatorLength = $items !== [] ? $this->visibleLength($separator) : 0;

            // Build ID part to calculate its visible length (use yellow for human tasks)
            $idPart = sprintf('<fg=yellow>[%s]</> ', $shortId);
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

        if ($items === []) {
            return;
        }

        $line = $prefix.implode($separator, $items);
        $this->line($line);
    }

    private function truncateHumanTitle(string $title, int $maxLength): string
    {
        // Truncate to first 3 words for human tasks
        $words = preg_split('/\s+/', trim($title));
        $truncated = count($words) <= 3 ? $title : implode(' ', array_slice($words, 0, 3)).'...';

        // If the 3-word version is still too long, truncate further
        if (mb_strlen($truncated) > $maxLength) {
            return mb_substr($truncated, 0, $maxLength - 3).'...';
        }

        return $truncated;
    }

    /**
     * Get a single character representing task complexity.
     */
    private function getComplexityChar(Task $task): string
    {
        $complexity = $task->complexity ?? 'simple';

        return match ($complexity) {
            'trivial' => 't',
            'simple' => 's',
            'moderate' => 'm',
            'complex' => 'c',
            default => 's',
        };
    }

    /**
     * Render agent health summary below the board.
     */
    private function renderHealthSummary(
        ?ConfigService $configService = null,
        ?AgentHealthTrackerInterface $healthTracker = null
    ): void {
        if (! $configService instanceof ConfigService || ! $healthTracker instanceof AgentHealthTrackerInterface) {
            return;
        }

        $agentNames = $configService->getAgentNames();
        if ($agentNames === []) {
            return;
        }

        $unhealthyAgents = [];
        $degradedAgents = [];
        $warningAgents = [];

        foreach ($agentNames as $agentName) {
            $health = $healthTracker->getHealthStatus($agentName);
            $status = $health->getStatus();

            if ($status === 'unhealthy') {
                $backoffSeconds = $health->getBackoffSeconds();
                $formatted = $backoffSeconds < 60
                    ? $backoffSeconds.'s'
                    : sprintf('%dm %ds', (int) ($backoffSeconds / 60), $backoffSeconds % 60);
                $unhealthyAgents[] = sprintf(
                    '<fg=red>%s</> (%d failures, backoff: %s)',
                    $agentName,
                    $health->consecutiveFailures,
                    $formatted
                );
            } elseif ($status === 'degraded') {
                $backoffSeconds = $health->getBackoffSeconds();
                if ($backoffSeconds > 0) {
                    $formatted = $backoffSeconds < 60
                        ? $backoffSeconds.'s'
                        : sprintf('%dm %ds', (int) ($backoffSeconds / 60), $backoffSeconds % 60);
                    $degradedAgents[] = sprintf(
                        '<fg=yellow>%s</> (%d failures, backoff: %s)',
                        $agentName,
                        $health->consecutiveFailures,
                        $formatted
                    );
                } else {
                    $degradedAgents[] = sprintf(
                        '<fg=yellow>%s</> (%d failures)',
                        $agentName,
                        $health->consecutiveFailures
                    );
                }
            } elseif ($status === 'warning') {
                $warningAgents[] = sprintf(
                    '<fg=yellow>%s</> (%d failure%s)',
                    $agentName,
                    $health->consecutiveFailures,
                    $health->consecutiveFailures === 1 ? '' : 's'
                );
            }
        }

        if ($unhealthyAgents === [] && $degradedAgents === [] && $warningAgents === []) {
            return; // All agents healthy, don't show anything
        }

        $this->newLine();
        $lines = [];

        if ($unhealthyAgents !== []) {
            $lines[] = '<fg=red>⚠ Unhealthy:</> '.implode(' | ', $unhealthyAgents);
        }

        if ($degradedAgents !== []) {
            $lines[] = '<fg=yellow>⚠ Degraded:</> '.implode(' | ', $degradedAgents);
        }

        if ($warningAgents !== []) {
            $lines[] = '<fg=yellow>⚠ Warning:</> '.implode(' | ', $warningAgents);
        }

        foreach ($lines as $line) {
            $this->line($line);
        }
    }
}
