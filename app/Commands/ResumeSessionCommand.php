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
            $task = $taskService->find($this->argument('id'));

            if ($task === null) {
                return $this->outputError("Task '{$this->argument('id')}' not found");
            }

            $taskId = $task['id'];

            $run = null;
            if ($this->option('run')) {
                $runs = $runService->getRuns($taskId);
                $runId = $this->option('run');

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

            $sessionId = $run['session_id'] ?? null;
            if ($sessionId === null || $sessionId === '') {
                return $this->outputError(
                    "Run '{$run['run_id']}' for task '{$taskId}' has no session_id"
                );
            }

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

            $prompt = $this->option('prompt');
            $command = ($prompt !== null && $prompt !== '')
                ? $agent->resumeWithPromptCommand($sessionId, $prompt)
                : $agent->resumeCommand($sessionId);

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

            // In testing, skip actual execution since agents won't exist in CI
            if (app()->environment('testing')) {
                return 1;
            }

            // For interactive resume (no prompt), use pcntl_exec to replace this process
            // This ensures the agent inherits the TTY properly
            if ($prompt === null || $prompt === '') {
                $binary = $agent->binary();
                $binaryPath = trim((string) shell_exec("which {$binary}"));

                if ($binaryPath === '') {
                    return $this->outputError("Could not find '{$binary}' in PATH");
                }

                $args = $agent->resumeArgs($sessionId);

                // pcntl_exec replaces this process entirely - never returns on success
                pcntl_exec($binaryPath, $args);

                // Only reached if pcntl_exec fails
                return $this->outputError("Failed to execute '{$binary}'");
            }

            passthru($command, $exitCode);

            return $exitCode;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        }
    }
}
