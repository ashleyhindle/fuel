<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class ShowCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'show
        {id : The task ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Show task details including all fields';

    public function handle(TaskService $taskService): int
    {
        $this->configureCwd($taskService);

        try {
            $task = $taskService->find($this->argument('id'));

            if ($task === null) {
                return $this->outputError("Task '{$this->argument('id')}' not found");
            }

            if ($this->option('json')) {
                $this->outputJson($task);
            } else {
                $this->info("Task: {$task['id']}");
                $this->line("  Title: {$task['title']}");
                $this->line("  Status: {$task['status']}");

                if (isset($task['description']) && $task['description'] !== null) {
                    $this->line("  Description: {$task['description']}");
                }

                if (isset($task['type'])) {
                    $this->line("  Type: {$task['type']}");
                }

                if (isset($task['priority'])) {
                    $this->line("  Priority: {$task['priority']}");
                }

                if (isset($task['size'])) {
                    $this->line("  Size: {$task['size']}");
                }

                if (isset($task['labels']) && ! empty($task['labels'])) {
                    $labels = implode(', ', $task['labels']);
                    $this->line("  Labels: {$labels}");
                }

                if (isset($task['blocked_by']) && ! empty($task['blocked_by'])) {
                    $blockerIds = is_array($task['blocked_by']) ? implode(', ', $task['blocked_by']) : '';
                    if ($blockerIds !== '') {
                        $this->line("  Blocked by: {$blockerIds}");
                    }
                }

                if (isset($task['reason'])) {
                    $this->line("  Reason: {$task['reason']}");
                }

                // Consume command fields
                if (! empty($task['consumed'])) {
                    $this->newLine();
                    $this->line('  <fg=cyan>── Consume Info ──</>');
                    $this->line('  Consumed: Yes');
                    if (isset($task['consumed_at'])) {
                        $this->line("  Consumed at: {$task['consumed_at']}");
                    }
                    if (isset($task['consumed_exit_code'])) {
                        $exitColor = $task['consumed_exit_code'] === 0 ? 'green' : 'red';
                        $this->line("  Exit code: <fg={$exitColor}>{$task['consumed_exit_code']}</>");
                    }
                    if (isset($task['consumed_output']) && $task['consumed_output'] !== '') {
                        $this->newLine();
                        $this->line('  <fg=cyan>── Agent Output ──</>');
                        // Indent each line of output
                        $outputLines = explode("\n", $task['consumed_output']);
                        foreach ($outputLines as $line) {
                            $this->line("  {$line}");
                        }
                    }
                }

                $this->newLine();
                $this->line("  Created: {$task['created_at']}");
                $this->line("  Updated: {$task['updated_at']}");
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        }
    }
}
