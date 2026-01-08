<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\Agent;
use App\Services\RunService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class ResumeSessionCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'resume
        {id : The task ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--run= : Specific run ID to resume (defaults to latest run)}
        {--p|prompt= : Resume with a prompt (headless mode)}';

    protected $description = 'Resume a task session interactively or with a prompt';

    public function handle(TaskService $taskService, RunService $runService): int
    {
        $this->configureCwd($taskService);

        try {
            // Find the task
            $task = $taskService->find($this->argument('id'));

            if ($task === null) {
                return $this->outputError("Task '{$this->argument('id')}' not found");
            }

            $taskId = $task['id'];

            // Get the run (latest or by run-id)
            $run = null;
            if ($this->option('run')) {
                $runs = $runService->getRuns($taskId);
                $runId = $this->option('run');

                // Find run by run_id (supports partial matching)
                $matches = array_filter($runs, function (array $r) use ($runId): bool {
                    $rId = $r['run_id'] ?? '';

                    return $rId === $runId || str_starts_with($rId, $runId);
                });

                if (empty($matches)) {
                    return $this->outputError("Run '{$runId}' not found for task '{$taskId}'");
                }

                if (count($matches) > 1) {
                    $matchedIds = array_map(fn (array $r): string => $r['run_id'] ?? '', array_values($matches));

                    return $this->outputError(
                        "Ambiguous run ID '{$runId}'. Matches: ".implode(', ', $matchedIds)
                    );
                }

                $run = reset($matches);
            } else {
                $run = $runService->getLatestRun($taskId);
            }

            if ($run === null) {
                return $this->outputError("No runs found for task '{$taskId}'");
            }

            // Validate session_id exists
            $sessionId = $run['session_id'] ?? null;
            if ($sessionId === null || $sessionId === '') {
                return $this->outputError(
                    "Run '{$run['run_id']}' for task '{$taskId}' has no session_id"
                );
            }

            // Validate agent exists and parse it
            $agentName = $run['agent'] ?? null;
            if ($agentName === null || $agentName === '') {
                return $this->outputError(
                    "Run '{$run['run_id']}' for task '{$taskId}' has no agent"
                );
            }

            $agent = Agent::fromString($agentName);
            if ($agent === null) {
                return $this->outputError(
                    "Unknown agent '{$agentName}' for run '{$run['run_id']}'"
                );
            }

            // Build the command
            $prompt = $this->option('prompt');
            if ($prompt !== null && $prompt !== '') {
                // Headless mode with prompt
                $command = $agent->resumeWithPromptCommand($sessionId, $prompt);
            } else {
                // Interactive mode
                $command = $agent->resumeCommand($sessionId);
            }

            // Output info before executing (for non-JSON mode)
            if (! $this->option('json')) {
                $this->info("Resuming session for task {$taskId}, run {$run['run_id']}");
                $this->line("Agent: {$agent->label()}");
                $this->line("Session: {$sessionId}");
                if ($prompt !== null && $prompt !== '') {
                    $this->line("Prompt: {$prompt}");
                }
                $this->newLine();
                $this->line("Executing: {$command}");
                $this->newLine();
            }

            // Execute the command (replaces current process)
            // Use exec() which replaces the current process and doesn't return
            exec($command);

            // If exec() returns, it means the command failed
            return $this->outputError("Failed to execute command: {$command}");
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        }
    }
}
