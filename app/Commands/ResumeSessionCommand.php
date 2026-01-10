<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\Agent;
use App\Services\ConfigService;
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

    public function handle(TaskService $taskService, RunService $runService, ConfigService $configService): int
    {
        $this->configureCwd($taskService, $configService);

        try {
            $task = $taskService->find($this->argument('id'));

            if ($task === null) {
                return $this->outputError(sprintf("Task '%s' not found", $this->argument('id')));
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

                if ($matches === []) {
                    return $this->outputError(sprintf("Run '%s' not found for task '%s'", $runId, $taskId));
                }

                if (count($matches) > 1) {
                    $matchedIds = array_map(fn (array $r): string => $r['run_id'] ?? '', array_values($matches));

                    return $this->outputError(
                        sprintf("Ambiguous run ID '%s'. Matches: ", $runId).implode(', ', $matchedIds)
                    );
                }

                $run = reset($matches);
            } else {
                $run = $runService->getLatestRun($taskId);
            }

            if ($run === null) {
                return $this->outputError(sprintf("No runs found for task '%s'", $taskId));
            }

            $sessionId = $run['session_id'] ?? null;
            if ($sessionId === null || $sessionId === '') {
                return $this->outputError(
                    sprintf("Run '%s' for task '%s' has no session_id", $run['run_id'], $taskId)
                );
            }

            $agentName = $run['agent'] ?? null;
            if ($agentName === null || $agentName === '') {
                return $this->outputError(
                    sprintf("Run '%s' for task '%s' has no agent", $run['run_id'], $taskId)
                );
            }

            // Try to get agent command from config (for custom agent names like 'claude-sonnet')
            $command = null;
            if ($configService->hasAgent($agentName)) {
                $agentDef = $configService->getAgentDefinition($agentName);
                $command = $agentDef['command'];
            }

            // Try to determine the agent type for resume
            $agent = Agent::fromAgentName($agentName, $command);
            if (!$agent instanceof Agent) {
                return $this->outputError(
                    sprintf("Unknown agent '%s' for run '%s'. ", $agentName, $run['run_id']).
                    'Cannot determine resume command format.'
                );
            }

            $prompt = $this->option('prompt');
            $resumeCommand = ($prompt !== null && $prompt !== '')
                ? $agent->resumeWithPromptCommand($sessionId, $prompt)
                : $agent->resumeCommand($sessionId);

            if (! $this->option('json')) {
                $this->info(sprintf('Resuming session for task %s, run %s', $taskId, $run['run_id']));
                $this->line(sprintf('Agent: %s (%s)', $agent->label(), $agentName));
                $this->line('Session: ' . $sessionId);
                if ($prompt !== null && $prompt !== '') {
                    $this->line('Prompt: ' . $prompt);
                }

                $this->newLine();
                $this->line('Executing: ' . $resumeCommand);
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
                $binaryPath = trim((string) shell_exec('which ' . $binary));

                if ($binaryPath === '') {
                    return $this->outputError(sprintf("Could not find '%s' in PATH", $binary));
                }

                $args = $agent->resumeArgs($sessionId);

                // pcntl_exec replaces this process entirely - never returns on success
                pcntl_exec($binaryPath, $args);

                // Only reached if pcntl_exec fails
                return $this->outputError(sprintf("Failed to execute '%s'", $binary));
            }

            passthru($resumeCommand, $exitCode);

            return $exitCode;
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }
    }
}
