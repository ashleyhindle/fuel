<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\EpicStatus;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Services\EpicService;
use App\Services\TaskService;
use App\TUI\ScreenBuffer;
use App\TUI\Toast;
use Carbon\Carbon;
use LaravelZero\Framework\Commands\Command;

class HumanCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'human
        {--json : Output as JSON}
        {--once : Show list once and exit (non-interactive mode)}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'List all items needing human attention';

    /** Original terminal state for restoration */
    private ?string $originalTty = null;

    /** Whether we've entered alternate screen mode */
    private bool $inAlternateScreen = false;

    /** Current terminal width */
    private int $terminalWidth = 120;

    /** Current terminal height */
    private int $terminalHeight = 40;

    /** Flag to force refresh on next loop (e.g., after SIGWINCH) */
    private bool $forceRefresh = false;

    /** Input buffer for batched reading */
    private string $inputBuffer = '';

    /** Screen buffer for differential rendering */
    private ?ScreenBuffer $screenBuffer = null;

    /** Previous screen buffer for comparison */
    private ?ScreenBuffer $previousBuffer = null;

    /** Toast notification manager */
    private ?Toast $toast = null;

    /** Cached epics data */
    private array $epics = [];

    /** Cached tasks data */
    private array $tasks = [];

    /** Last refresh timestamp */
    private float $lastRefresh = 0;

    /** Refresh interval in seconds */
    private const REFRESH_INTERVAL = 5.0;

    public function handle(TaskService $taskService, EpicService $epicService): int
    {
        // Fetch initial data
        $this->refreshData($taskService, $epicService);

        // Handle JSON output
        if ($this->option('json')) {
            return $this->handleJsonOutput();
        }

        // Handle non-interactive mode (--once or non-TTY)
        if ($this->option('once') || ! stream_isatty(STDIN)) {
            return $this->handleOnceMode();
        }

        // Enter TUI mode
        return $this->handleTuiMode($taskService, $epicService);
    }

    /**
     * Handle JSON output mode.
     */
    private function handleJsonOutput(): int
    {
        $this->outputJson([
            'tasks' => array_map(fn (Task $task): array => $task->toArray(), $this->tasks),
            'epics' => array_map(fn (Epic $epic): array => $epic->toArray(), $this->epics),
        ]);

        return self::SUCCESS;
    }

    /**
     * Handle non-interactive mode (--once).
     */
    private function handleOnceMode(): int
    {
        $totalCount = count($this->tasks) + count($this->epics);

        if ($totalCount === 0) {
            $this->info('No items need human attention.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Items needing human attention (%d):', $totalCount));
        $this->newLine();

        // Show epics pending review first
        foreach ($this->epics as $epic) {
            $age = $this->formatAge($epic->created_at ?? null);
            $this->line(sprintf('<info>%s</info> - %s <comment>(%s)</comment>', $epic->short_id, $epic->title, $age));
            if (! empty($epic->description ?? null)) {
                $this->line('  '.$epic->description);
            }

            $this->line(sprintf('  Plan: <comment>%s</comment>', $epic->getPlanPath()));
            $this->line(sprintf('  Review: <comment>fuel epic:review %s</comment>', $epic->short_id));
            $this->newLine();
        }

        // Show tasks needing human attention
        foreach ($this->tasks as $task) {
            $age = $this->formatAge($task->created_at ?? null);
            $this->line(sprintf('<info>%s</info> - %s <comment>(%s)</comment>', $task->short_id, $task->title, $age));
            if (! empty($task->description ?? null)) {
                $this->line('  '.$task->description);
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * Handle interactive TUI mode.
     */
    private function handleTuiMode(TaskService $taskService, EpicService $epicService): int
    {
        // Enter alternate screen mode
        $this->enterAlternateScreen();

        // Initialize screen buffers
        $this->screenBuffer = new ScreenBuffer($this->terminalWidth, $this->terminalHeight);
        $this->previousBuffer = new ScreenBuffer($this->terminalWidth, $this->terminalHeight);

        // Initialize toast notifications
        $this->toast = new Toast;

        // Register SIGWINCH handler for terminal resize
        pcntl_signal(SIGWINCH, function (): void {
            $this->forceRefresh = true;
            $this->updateTerminalSize();
            $this->screenBuffer?->resize($this->terminalWidth, $this->terminalHeight);
            $this->previousBuffer?->resize($this->terminalWidth, $this->terminalHeight);
        });

        // Enable mouse reporting (SGR extended mode for proper coordinate parsing)
        $this->getOutput()->write("\033[?1006h"); // Enable SGR extended mode
        $this->getOutput()->write("\033[?1003h"); // Enable any-event tracking

        try {
            // Main loop
            while (true) {
                pcntl_signal_dispatch();

                // Handle terminal resize
                if ($this->forceRefresh) {
                    $this->updateTerminalSize();
                    $this->forceRefresh = false;
                }

                // Refresh data periodically
                if (microtime(true) - $this->lastRefresh > self::REFRESH_INTERVAL) {
                    $this->refreshData($taskService, $epicService);
                    $this->lastRefresh = microtime(true);
                    $this->forceRefresh = true;
                }

                // Handle keyboard/mouse input
                if ($this->handleInput($taskService, $epicService)) {
                    break; // User pressed 'q' to quit
                }

                // Render the display
                $this->render();

                // Sleep for 60ms
                usleep(60000);
            }
        } finally {
            $this->restoreTerminal();
        }

        return self::SUCCESS;
    }

    /**
     * Enter alternate screen mode for TUI.
     */
    private function enterAlternateScreen(): void
    {
        $this->originalTty = shell_exec('stty -g');
        register_shutdown_function([$this, 'restoreTerminal']);

        // Disable echo BEFORE entering alt screen to prevent escape sequences from showing
        shell_exec('stty -icanon -echo');
        stream_set_blocking(STDIN, false);

        $this->updateTerminalSize();

        // Enter alternate screen and hide cursor
        $this->getOutput()->write("\033[?1049h"); // Enter alternate screen
        $this->inAlternateScreen = true;
        $this->getOutput()->write("\033[?25l"); // Hide cursor
        $this->getOutput()->write("\033[H\033[2J"); // Clear screen
    }

    /**
     * Restore terminal to its original state.
     */
    public function restoreTerminal(): void
    {
        // Only restore once - check and clear the flag atomically
        if (! $this->inAlternateScreen && $this->originalTty === null) {
            return;
        }

        // Restore stty settings
        if ($this->originalTty !== null) {
            shell_exec('stty '.trim($this->originalTty));
            $this->originalTty = null;
        }

        // Restore stream blocking
        stream_set_blocking(STDIN, true);

        // Exit alternate screen buffer and show cursor
        if ($this->inAlternateScreen) {
            // Use echo to ensure output even if Laravel output is unavailable
            echo "\033[?1003l";   // Disable any-event tracking
            echo "\033[?1006l";   // Disable SGR extended mode
            echo "\033[?25h";     // Show cursor
            echo "\033[?1049l";   // Exit alternate screen
            echo "\033]0;\007";   // Reset terminal title
            $this->inAlternateScreen = false;
        }
    }

    /**
     * Update terminal size from environment.
     */
    private function updateTerminalSize(): void
    {
        $size = shell_exec('stty size 2>/dev/null');
        if ($size !== null && preg_match('/(\d+)\s+(\d+)/', trim($size), $matches)) {
            $this->terminalHeight = (int) $matches[1];
            $this->terminalWidth = (int) $matches[2];
        } else {
            // Fallback to tput
            $cols = shell_exec('tput cols 2>/dev/null');
            $lines = shell_exec('tput lines 2>/dev/null');
            if ($cols !== null && $lines !== null) {
                $this->terminalWidth = (int) trim($cols);
                $this->terminalHeight = (int) trim($lines);
            }
        }
    }

    /**
     * Handle keyboard and mouse input.
     * Returns true if the user wants to quit.
     */
    private function handleInput(TaskService $taskService, EpicService $epicService): bool
    {
        // Read input without blocking
        $read = [STDIN];
        $write = null;
        $except = null;
        $timeout = 0;

        if (@stream_select($read, $write, $except, $timeout) === 0) {
            return false; // No input available
        }

        $chunk = fread(STDIN, 4096);
        if ($chunk === false) {
            return false;
        }

        $this->inputBuffer .= $chunk;

        // Process input buffer
        while ($this->inputBuffer !== '') {
            // Check for escape sequences FIRST (mouse events, etc.)
            if ($this->inputBuffer[0] === "\033") {
                // Check for mouse events (ESC[<...)
                if (str_starts_with($this->inputBuffer, "\033[<")) {
                    $endPos = strpos($this->inputBuffer, 'M');
                    $releaseEndPos = strpos($this->inputBuffer, 'm');

                    if ($endPos !== false || $releaseEndPos !== false) {
                        $actualEnd = ($releaseEndPos !== false && ($endPos === false || $releaseEndPos < $endPos))
                            ? $releaseEndPos
                            : $endPos;

                        $sequence = substr($this->inputBuffer, 0, $actualEnd + 1);
                        $this->inputBuffer = substr($this->inputBuffer, $actualEnd + 1);

                        $this->handleMouseEvent($sequence, $taskService, $epicService);

                        continue;
                    }

                    // Incomplete mouse sequence - wait for more data
                    break;
                }

                // Check if this might be an incomplete escape sequence
                // (starts with ESC but we don't have enough bytes yet)
                if (strlen($this->inputBuffer) < 3) {
                    // Wait for more data
                    break;
                }

                // Unrecognized escape sequence - skip the ESC and continue
                $this->inputBuffer = substr($this->inputBuffer, 1);

                continue;
            }

            // Now safe to check for single-character commands
            // Check for 'q' to quit
            if ($this->inputBuffer[0] === 'q' || $this->inputBuffer[0] === 'Q') {
                return true;
            }

            // Check for 'r' to refresh
            if ($this->inputBuffer[0] === 'r' || $this->inputBuffer[0] === 'R') {
                $this->inputBuffer = substr($this->inputBuffer, 1);
                $this->refreshData($taskService, $epicService);
                $this->forceRefresh = true;
                $this->toast?->show('Data refreshed', 'info');

                continue;
            }

            // Remove unrecognized character
            $this->inputBuffer = substr($this->inputBuffer, 1);
        }

        return false;
    }

    /**
     * Handle mouse events.
     */
    private function handleMouseEvent(string $sequence, TaskService $taskService, EpicService $epicService): void
    {
        // Parse mouse event: ESC[<button;col;row(M|m)
        if (! preg_match('/^\033\[<(\d+);(\d+);(\d+)([Mm])/', $sequence, $matches)) {
            return;
        }

        $button = (int) $matches[1];
        $col = (int) $matches[2];
        $row = (int) $matches[3];
        $release = $matches[4] === 'm';

        // Only handle left button release (button 0)
        if ($button !== 0 || ! $release) {
            return;
        }

        // Check if click is on a button region
        $region = $this->screenBuffer?->getRegionAt($row, $col);
        if ($region === null || ! str_starts_with($region['type'], 'button-')) {
            return;
        }

        // Handle button click based on type
        $this->handleButtonClick($region, $taskService, $epicService);
    }

    /**
     * Handle button click based on region type.
     */
    private function handleButtonClick(array $region, TaskService $taskService, EpicService $epicService): void
    {
        $type = $region['type'];
        $id = $region['data']['id'] ?? null;

        if ($id === null) {
            return;
        }

        switch ($type) {
            case 'button-copy-review':
                $command = 'fuel epic:review '.$id;
                $this->copyToClipboard($command);
                $this->toast?->show('Copied: '.$command, 'success');
                break;

            case 'button-reviewed':
                $this->callCommand('epic:reviewed', ['id' => $id]);
                $this->toast?->show('Marked as reviewed: '.$id, 'success');
                $this->refreshData($taskService, $epicService);
                $this->forceRefresh = true;
                break;

            case 'button-approved':
                $this->callCommand('epic:approve', ['ids' => [$id]]);
                $this->toast?->show('Approved: '.$id, 'success');
                $this->refreshData($taskService, $epicService);
                $this->forceRefresh = true;
                break;

            case 'button-delete':
                $this->callCommand('epic:delete', ['id' => $id, '--force' => true]);
                $this->toast?->show('Deleted: '.$id, 'warning');
                $this->refreshData($taskService, $epicService);
                $this->forceRefresh = true;
                break;

            case 'button-copy-cmd':
                $command = 'fuel show '.$id;
                $this->copyToClipboard($command);
                $this->toast?->show('Copied: '.$command, 'success');
                break;
        }
    }

    /**
     * Copy text to clipboard using OSC 52.
     */
    private function copyToClipboard(string $text): void
    {
        $encoded = base64_encode($text);
        echo "\033]52;c;{$encoded}\007";
    }

    /**
     * Run an artisan command with named arguments.
     */
    private function callCommand(string $command, array $arguments = []): void
    {
        try {
            $this->call($command, $arguments);
        } catch (\Exception $exception) {
            $this->toast?->show('Command failed: '.$exception->getMessage(), 'error');
        }
    }

    /**
     * Render the TUI display.
     */
    private function render(): void
    {
        if (! $this->screenBuffer instanceof ScreenBuffer) {
            return;
        }

        // Swap buffers
        $temp = $this->previousBuffer;
        $this->previousBuffer = $this->screenBuffer;
        $this->screenBuffer = $temp;
        $this->screenBuffer->clear();

        // Render header
        $this->renderHeader();

        // Render content
        $currentRow = 4;
        $currentRow = $this->renderEpics($currentRow);
        $currentRow = $this->renderDivider($currentRow);
        $this->renderTasks($currentRow);

        // Render toast if visible
        if ($this->toast?->isVisible()) {
            $this->toast->render($this->getOutput(), $this->terminalWidth, $this->terminalHeight);
        }

        // Differential rendering
        $this->performDifferentialRender();
    }

    /**
     * Render the header.
     */
    private function renderHeader(): void
    {
        $title = ' Fuel: Human Review ';
        $quitHint = ' q: quit ';
        $refreshHint = ' r: refresh ';
        $hints = $refreshHint.$quitHint;

        $padding = $this->terminalWidth - mb_strlen($title) - mb_strlen($hints);
        $header = "\033[1;97;44m".$title.str_repeat(' ', max(0, $padding)).$hints."\033[0m";

        $this->screenBuffer->setLine(1, $header);
        $this->screenBuffer->setLine(2, str_repeat('─', $this->terminalWidth));
    }

    /**
     * Render epics section.
     */
    private function renderEpics(int $startRow): int
    {
        $row = $startRow;

        if ($this->epics === [] && $this->tasks === []) {
            $this->screenBuffer->setLine($row++, '');
            $message = 'Nothing needs attention';
            $padding = ($this->terminalWidth - mb_strlen($message)) / 2;
            $this->screenBuffer->setLine($row++, str_repeat(' ', (int) $padding)."\033[1;32m".$message."\033[0m");

            return $row;
        }

        if ($this->epics !== []) {
            $this->screenBuffer->setLine($row++, '');
            $this->screenBuffer->setLine($row++, "\033[1;36mEPICS PENDING REVIEW (".count($this->epics).")\033[0m");
            $this->screenBuffer->setLine($row++, '');

            foreach ($this->epics as $epic) {
                $row = $this->renderEpicItem($epic, $row);
            }
        }

        return $row;
    }

    /**
     * Render a single epic item.
     */
    private function renderEpicItem(Epic $epic, int $row): int
    {
        $age = $this->formatAge($epic->created_at ?? null);
        $title = "\033[1;33m{$epic->short_id}\033[0m - {$epic->title} \033[90m({$age})\033[0m";
        $this->screenBuffer->setLine($row++, $title);

        if (! empty($epic->description)) {
            $wrappedDesc = wordwrap((string) $epic->description, $this->terminalWidth - 4, "\n", true);
            foreach (explode("\n", $wrappedDesc) as $line) {
                $this->screenBuffer->setLine($row++, '  '.$line);
            }
        }

        // Show plan path
        $planPath = $epic->getPlanPath();
        $this->screenBuffer->setLine($row++, "  \033[90mPlan: {$planPath}\033[0m");

        // Render buttons
        $buttonRow = $row;
        $buttons = '  ';
        $buttonStart = 3; // Starting column for buttons

        // Copy review button
        $copyText = '[ Copy review ]';
        $buttons .= "\033[1;34m{$copyText}\033[0m ";
        $this->screenBuffer->registerRegion(
            'copy-review-'.$epic->short_id,
            $buttonRow,
            $buttonRow,
            $buttonStart,
            $buttonStart + mb_strlen($copyText) - 1,
            'button-copy-review',
            ['id' => $epic->short_id]
        );
        $buttonStart += mb_strlen($copyText) + 1;

        // Reviewed button
        $reviewedText = '[ Reviewed ]';
        $buttons .= "\033[1;32m{$reviewedText}\033[0m ";
        $this->screenBuffer->registerRegion(
            'reviewed-'.$epic->short_id,
            $buttonRow,
            $buttonRow,
            $buttonStart,
            $buttonStart + mb_strlen($reviewedText) - 1,
            'button-reviewed',
            ['id' => $epic->short_id]
        );
        $buttonStart += mb_strlen($reviewedText) + 1;

        // Approved button
        $approvedText = '[ Approved ]';
        $buttons .= "\033[1;32m{$approvedText}\033[0m ";
        $this->screenBuffer->registerRegion(
            'approved-'.$epic->short_id,
            $buttonRow,
            $buttonRow,
            $buttonStart,
            $buttonStart + mb_strlen($approvedText) - 1,
            'button-approved',
            ['id' => $epic->short_id]
        );
        $buttonStart += mb_strlen($approvedText) + 1;

        // Delete button (right-aligned)
        $deleteText = '[ Del ]';
        $deleteStart = $this->terminalWidth - mb_strlen($deleteText) - 2;
        $padding = $deleteStart - $buttonStart;
        $buttons .= str_repeat(' ', max(0, $padding))."\033[1;31m{$deleteText}\033[0m";
        $this->screenBuffer->registerRegion(
            'delete-'.$epic->short_id,
            $buttonRow,
            $buttonRow,
            $deleteStart,
            $deleteStart + mb_strlen($deleteText) - 1,
            'button-delete',
            ['id' => $epic->short_id]
        );

        $this->screenBuffer->setLine($row++, $buttons);
        $this->screenBuffer->setLine($row++, '');

        return $row;
    }

    /**
     * Render divider between sections.
     */
    private function renderDivider(int $row): int
    {
        if ($this->epics !== [] && $this->tasks !== []) {
            $this->screenBuffer->setLine($row++, str_repeat('─', $this->terminalWidth));
        }

        return $row;
    }

    /**
     * Render tasks section.
     */
    private function renderTasks(int $startRow): int
    {
        $row = $startRow;

        if ($this->tasks !== []) {
            $this->screenBuffer->setLine($row++, '');
            $this->screenBuffer->setLine($row++, "\033[1;36mTASKS NEEDING HUMAN (".count($this->tasks).")\033[0m");
            $this->screenBuffer->setLine($row++, '');

            foreach ($this->tasks as $task) {
                $row = $this->renderTaskItem($task, $row);
            }
        }

        return $row;
    }

    /**
     * Render a single task item.
     */
    private function renderTaskItem(Task $task, int $row): int
    {
        $age = $this->formatAge($task->created_at ?? null);
        $title = "\033[1;33m{$task->short_id}\033[0m - {$task->title} \033[90m({$age})\033[0m";
        $this->screenBuffer->setLine($row++, $title);

        if (! empty($task->description)) {
            $wrappedDesc = wordwrap((string) $task->description, $this->terminalWidth - 4, "\n", true);
            foreach (explode("\n", $wrappedDesc) as $line) {
                $this->screenBuffer->setLine($row++, '  '.$line);
            }
        }

        // Render button
        $buttonRow = $row;
        $copyText = '[ Copy command ]';
        $buttons = "  \033[1;34m{$copyText}\033[0m";
        $this->screenBuffer->registerRegion(
            'copy-cmd-'.$task->short_id,
            $buttonRow,
            $buttonRow,
            3,
            3 + mb_strlen($copyText) - 1,
            'button-copy-cmd',
            ['id' => $task->short_id]
        );

        $this->screenBuffer->setLine($row++, $buttons);
        $this->screenBuffer->setLine($row++, '');

        return $row;
    }

    /**
     * Perform differential rendering between buffers.
     */
    private function performDifferentialRender(): void
    {
        if (! $this->screenBuffer instanceof ScreenBuffer || ! $this->previousBuffer instanceof ScreenBuffer) {
            return;
        }

        for ($row = 1; $row <= $this->terminalHeight; $row++) {
            $currentLine = $this->screenBuffer->getLine($row) ?? '';
            $previousLine = $this->previousBuffer->getLine($row) ?? '';

            if ($currentLine !== $previousLine || $this->forceRefresh) {
                echo "\033[{$row};1H"; // Move cursor to row
                echo "\033[2K"; // Clear line
                echo $currentLine;
            }
        }
    }

    /**
     * Refresh data from services.
     */
    private function refreshData(TaskService $taskService, EpicService $epicService): void
    {
        // Get tasks with needs-human label (excluding epic-review tasks)
        $tasks = $taskService->all();
        $this->tasks = $tasks
            ->filter(fn (Task $t): bool => $t->status === TaskStatus::Open)
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
            ->values()
            ->all();

        // Get epics with status review_pending
        $allEpics = $epicService->getAllEpics();
        $this->epics = array_values(array_filter($allEpics, fn (Epic $epic): bool => $epic->status === EpicStatus::ReviewPending));
    }

    /**
     * Format age for display.
     */
    private function formatAge(?\DateTimeInterface $createdAt): string
    {
        if (! $createdAt instanceof \DateTimeInterface) {
            return 'unknown';
        }

        return Carbon::instance($createdAt)->diffForHumans();
    }
}
