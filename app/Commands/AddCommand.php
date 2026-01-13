<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\TaskStatus;
use App\Models\Epic;
use App\Services\EpicService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class AddCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'add
        {title? : The task title}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--d|description= : Task description}
        {--type= : Task type (bug|fix|feature|task|epic|chore|docs|test|refactor)}
        {--priority= : Task priority (0-4)}
        {--labels= : Comma-separated list of labels}
        {--complexity= : Task complexity (trivial|simple|moderate|complex)}
        {--blocked-by= : Comma-separated task IDs this is blocked by}
        {--e|epic= : Epic ID to associate this task with}
        {--someday : Add to backlog instead of tasks}
        {--backlog : Add to backlog (alias for --someday)}';

    protected $description = 'Add a new task';

    /**
     * Ask for multi-line input from the user.
     * User can paste multiple lines and end input by typing "END" on its own line or pressing Ctrl+D.
     */
    private function askMultiline(string $question): string
    {
        $this->line($question);
        $this->line('<fg=gray>You can paste multiple lines. Type "END" on its own line or press Ctrl+D when done.</>');
        $this->line('<fg=gray>Press Enter on an empty line to skip.</>');

        $lines = [];
        $firstLine = true;

        while (true) {
            $line = fgets(STDIN);

            // Handle EOF (Ctrl+D)
            if ($line === false) {
                break;
            }

            $line = rtrim($line, "\r\n");

            // If first line is empty, skip description entirely
            if ($firstLine && $line === '') {
                return '';
            }

            $firstLine = false;

            // Check for terminator
            if ($line === 'END') {
                break;
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Read piped input from stdin if available.
     * Returns null if stdin is a terminal (no piped input).
     */
    private function readPipedInput(): ?string
    {
        // Check if stdin is a terminal - if so, no piped input
        if (posix_isatty(STDIN)) {
            return null;
        }

        // Read all available input from stdin
        $content = '';
        stream_set_blocking(STDIN, false);

        while (($chunk = fread(STDIN, 8192)) !== false && $chunk !== '') {
            $content .= $chunk;
        }

        stream_set_blocking(STDIN, true);

        // Return null if no content was piped
        $trimmed = trim($content);

        return $trimmed !== '' ? $trimmed : null;
    }

    public function handle(TaskService $taskService, EpicService $epicService): int
    {
        $title = $this->argument('title');
        $pipedContent = $this->readPipedInput();

        // Handle piped input
        if ($pipedContent !== null) {
            if (empty($title)) {
                // No title argument - extract from piped content
                $lines = explode("\n", $pipedContent, 2);
                $title = trim($lines[0]);
                $description = isset($lines[1]) ? trim($lines[1]) : null;

                if (empty($title)) {
                    return $this->outputError('Piped content must have at least one non-empty line for title');
                }
            } else {
                // Title provided as argument - entire piped content becomes description
                $description = $pipedContent;
            }
        }

        // Interactive mode if no title provided and no piped input
        if (empty($title)) {
            $title = $this->ask('Title');
            if (empty($title)) {
                return $this->outputError('Title is required');
            }

            $description = $this->askMultiline('Description (optional)');

            $complexity = $this->askComplexityWithImmediateSelection();
        }

        $data = [
            'title' => $title,
        ];

        // Add interactive values if set
        if (isset($description) && $description !== null && $description !== '') {
            $data['description'] = $description;
        }

        if (isset($complexity)) {
            $data['complexity'] = $complexity;
        }

        // Set status to someday if --someday or --backlog flag is present
        if ($this->option('backlog') || $this->option('someday')) {
            $data['status'] = TaskStatus::Someday->value;
        }

        // Add description (support both --description and -d)
        if ($description = $this->option('description')) {
            $data['description'] = $description;
        }

        // Add type
        if ($type = $this->option('type')) {
            $data['type'] = $type;
        }

        // Add priority (use !== null to allow 0)
        if (($priority = $this->option('priority')) !== null) {
            // Validate priority is numeric before casting
            if (! is_numeric($priority)) {
                return $this->outputError(sprintf("Invalid priority '%s'. Must be an integer between 0 and 4.", $priority));
            }

            $data['priority'] = (int) $priority;
        }

        // Add labels (comma-separated)
        if ($labels = $this->option('labels')) {
            $data['labels'] = array_map(trim(...), explode(',', $labels));
        }

        // Add complexity
        if ($complexity = $this->option('complexity')) {
            $data['complexity'] = $complexity;
        }

        // Add blocked-by dependencies (comma-separated task IDs)
        if ($blockedBy = $this->option('blocked-by')) {
            $data['blocked_by'] = array_map(trim(...), explode(',', $blockedBy));
        }

        // Add epic_id if provided
        if ($epic = $this->option('epic')) {
            // Validate epic exists
            $epicRecord = $epicService->getEpic($epic);
            if (! $epicRecord instanceof Epic) {
                return $this->outputError(sprintf("Epic '%s' not found", $epic));
            }

            $data['epic_id'] = $epicRecord->short_id;
        }

        try {
            $task = $taskService->create($data);
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }

        if ($this->option('json')) {
            $this->outputJson($task->toArray());
        } else {
            $this->info('Created task: '.$task->short_id);
            $this->line('  Title: '.$task->title);
            $this->line('  Status: '.$task->status->value);

            if (! empty($task->blocked_by)) {
                $blockerIds = is_array($task->blocked_by) ? implode(', ', $task->blocked_by) : '';
                if ($blockerIds !== '') {
                    $this->line('  Blocked by: '.$blockerIds);
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * Ask for complexity with immediate selection (no Enter required).
     * User can press 1-4 to select immediately, or use arrow keys + Enter for traditional selection.
     * Falls back to simple input if not in a terminal (e.g., piped input).
     */
    private function askComplexityWithImmediateSelection(): string
    {
        $options = ['trivial', 'simple', 'moderate', 'complex'];
        $default = 1; // 'simple'

        $this->line('Complexity:');
        foreach ($options as $index => $option) {
            $number = $index + 1;
            $marker = $index === $default ? ' <fg=green>(default)</>' : '';
            $this->line(sprintf('  [%s] %s%s', $number, $option, $marker));
        }

        // Check if stdin is a terminal - if not, use simple input mode
        if (! posix_isatty(STDIN)) {
            $this->line('<fg=gray>Enter 1-4, or press Enter for default</>');
            $input = trim(fgets(STDIN) ?: '');

            if ($input === '') {
                return $options[$default];
            }

            if (ctype_digit($input) && (int) $input >= 1 && (int) $input <= 4) {
                return $options[(int) $input - 1];
            }

            // Invalid input, use default
            return $options[$default];
        }

        // Save current terminal settings
        $sttySettings = shell_exec('stty -g');

        try {
            // Enable raw mode for immediate key capture
            shell_exec('stty -icanon -echo');

            $this->line('<fg=gray>Press 1-4 to select immediately, or Enter for default</>');

            while (true) {
                $char = fgetc(STDIN);

                if ($char === false) {
                    // EOF or error, use default
                    break;
                }

                // Check for number keys 1-4
                if ($char >= '1' && $char <= '4') {
                    $selected = (int) $char - 1;
                    $this->line(sprintf('<info>Selected: %s</info>', $options[$selected]));

                    return $options[$selected];
                }

                // Check for Enter key (newline)
                if ($char === "\n" || $char === "\r") {
                    $this->line(sprintf('<info>Selected: %s (default)</info>', $options[$default]));

                    return $options[$default];
                }

                // Ignore other keys (including arrow keys which send escape sequences)
            }

            // If we break out of the loop (EOF), use default
            return $options[$default];
        } finally {
            // Restore terminal settings
            if ($sttySettings !== null) {
                shell_exec('stty '.$sttySettings);
            }
        }
    }
}
