<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\PromptService;
use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Process\CompletionType;
use App\Services\ConfigService;
use App\Services\FuelContext;
use App\Services\OutputParser;
use App\Services\ProcessManager;
use App\Services\RunService;
use App\Services\TaskPromptBuilder;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

class RunCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'run
        {id : Task ID to run (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--agent= : Agent name to use (overrides config-based routing)}
        {--prompt= : Custom prompt (defaults to standard consume prompt)}
        {--no-start : Don\'t mark task as in_progress before running}
        {--no-done : Don\'t auto-complete task on success}
        {--raw : Show raw JSON output instead of formatted}
        {--json : Output as JSON}';

    protected $description = 'Run a single task with an agent, streaming output to terminal';

    public function __construct(
        private TaskService $taskService,
        private ConfigService $configService,
        private RunService $runService,
        private ProcessManager $processManager,
        private OutputParser $outputParser,
        private TaskPromptBuilder $promptBuilder,
        private FuelContext $fuelContext,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $cwd = $this->fuelContext->getProjectPath();

        // Ensure processes directory exists
        $processesDir = $this->fuelContext->getProcessesPath();
        if (! is_dir($processesDir)) {
            mkdir($processesDir, 0755, true);
        }

        // Find the task
        $taskId = $this->argument('id');
        $task = $this->taskService->find($taskId);

        if (! $task instanceof Task) {
            return $this->outputError('Task not found: '.$taskId);
        }

        $taskId = $task->short_id; // Use full ID after partial match
        $taskTitle = $task->title;
        $status = $task->status;
        $blockedBy = $task->blocked_by ?? [];

        $this->info('Task: '.$taskId);
        $this->line('Title: '.$taskTitle);

        // Show warnings for blocked/in_progress/done tasks but continue anyway
        if ($blockedBy !== []) {
            $this->warn('⚠ Task is blocked by: '.implode(', ', $blockedBy).' (ignoring for debug run)');
        }

        if ($status === TaskStatus::InProgress) {
            $this->warn('⚠ Task is already in_progress (ignoring for debug run)');
        } elseif ($status === TaskStatus::Done) {
            $this->warn('⚠ Task is already done (ignoring for debug run)');
        } elseif ($status === TaskStatus::Cancelled) {
            $this->warn('⚠ Task is cancelled (ignoring for debug run)');
        }

        $this->newLine();

        // Determine agent and build prompt
        $agentName = $this->option('agent');
        $fullPrompt = $this->option('prompt');

        if ($agentName === null || $fullPrompt === null) {
            // Check if this is a reality task
            if ($task->type === 'reality') {
                if ($agentName === null) {
                    $agentName = $this->configService->getRealityAgent();
                    if ($agentName === null) {
                        return $this->outputError('No reality agent configured');
                    }
                }

                if ($fullPrompt === null) {
                    $promptService = app(PromptService::class);
                    $fullPrompt = $promptService->loadTemplate('reality');
                }
            } else {
                // Standard complexity-based routing
                if ($agentName === null) {
                    $complexity = $task->complexity ?? 'simple';
                    try {
                        $agentName = $this->configService->getAgentForComplexity($complexity);
                    } catch (\RuntimeException $e) {
                        return $this->outputError('Failed to get agent: '.$e->getMessage());
                    }
                }

                if ($fullPrompt === null) {
                    $fullPrompt = $this->promptBuilder->build($task, $cwd);
                }
            }
        }

        $this->info('Agent: '.$agentName);

        // Mark task as in_progress unless --no-start
        if (! $this->option('no-start') && $task->status !== TaskStatus::InProgress) {
            $this->taskService->start($taskId);
            $this->line('<fg=yellow>→ Marked task as in_progress</>');
        }

        // Create run entry before spawning
        $runId = $this->runService->createRun($taskId, [
            'agent' => $agentName,
            'model' => null, // Will be updated after spawn
            'started_at' => date('c'),
        ]);

        // Spawn the agent
        $this->newLine();
        $this->info('Spawning agent...');
        $this->line('<fg=gray>─────────────────────────────────────────</>');
        $this->newLine();

        $result = $this->processManager->spawnForTask($task, $fullPrompt, $cwd, $this->option('agent'), $runId);

        if (! $result->success) {
            $this->error('Failed to spawn agent: '.($result->error ?? 'Unknown error'));

            // Revert task state if we started it
            if (! $this->option('no-start')) {
                $this->taskService->reopen($taskId);
            }

            return self::FAILURE;
        }

        $process = $result->process;
        $pid = $process->getPid();

        $this->line('<fg=gray>PID: '.$pid.'</>');

        // Store the process PID in the run and mark task as consumed
        $this->runService->updateRun($runId, ['pid' => $pid]);
        $this->taskService->update($taskId, [
            'consumed' => true,
        ]);

        // Stream output while process runs
        $startTime = time();
        $stdoutPath = $cwd.'/.fuel/processes/'.$runId.'/stdout.log';
        $stderrPath = $cwd.'/.fuel/processes/'.$runId.'/stderr.log';
        $lastStdoutPos = 0;
        $lastStderrPos = 0;
        $rawMode = $this->option('raw');

        while ($this->processManager->isRunning($taskId)) {
            // Read and output any new stdout
            if (file_exists($stdoutPath)) {
                $stdout = file_get_contents($stdoutPath);
                if (strlen($stdout) > $lastStdoutPos) {
                    $newOutput = substr($stdout, $lastStdoutPos);
                    $this->outputChunk($newOutput, $rawMode);
                    $lastStdoutPos = strlen($stdout);
                }
            }

            // Read and output any new stderr
            if (file_exists($stderrPath)) {
                $stderr = file_get_contents($stderrPath);
                if (strlen($stderr) > $lastStderrPos) {
                    $newOutput = substr($stderr, $lastStderrPos);
                    $this->getOutput()->write('<fg=red>'.OutputFormatter::escape($newOutput).'</>');
                    $lastStderrPos = strlen($stderr);
                }
            }

            usleep(100000); // 100ms
        }

        // Final read to catch any remaining output
        if (file_exists($stdoutPath)) {
            $stdout = file_get_contents($stdoutPath);
            if (strlen($stdout) > $lastStdoutPos) {
                $this->outputChunk(substr($stdout, $lastStdoutPos), $rawMode);
            }
        }

        if (file_exists($stderrPath)) {
            $stderr = file_get_contents($stderrPath);
            if (strlen($stderr) > $lastStderrPos) {
                $this->getOutput()->write('<fg=red>'.OutputFormatter::escape(substr($stderr, $lastStderrPos)).'</>');
            }
        }

        // Poll to get completion result
        $completions = $this->processManager->poll();
        $completion = $completions[0] ?? null;

        $duration = time() - $startTime;
        $durationStr = $this->formatDuration($duration);

        $this->newLine();
        $this->line('<fg=gray>─────────────────────────────────────────</>');
        $this->newLine();

        // Get exit code
        $exitCode = $completion?->exitCode ?? -1;

        // Update run entry
        $runData = [
            'ended_at' => date('c'),
            'exit_code' => $exitCode,
            'output' => $completion?->output ?? '',
        ];

        if ($completion?->sessionId !== null) {
            $runData['session_id'] = $completion->sessionId;
            $this->line('<fg=gray>Session ID: '.$completion->sessionId.'</>');
        }

        if ($completion?->costUsd !== null) {
            $runData['cost_usd'] = $completion->costUsd;
            $this->line(sprintf('<fg=gray>Cost: $%.4f</>', $completion->costUsd));
        }

        if ($completion?->model !== null) {
            $runData['model'] = $completion->model;
        }

        $this->runService->updateLatestRun($taskId, $runData);

        // Handle completion
        if ($exitCode === 0) {
            $this->info(sprintf('✓ Agent completed successfully (%s)', $durationStr));

            // Auto-complete task unless --no-done
            if (! $this->option('no-done')) {
                $task = $this->taskService->find($taskId);
                if ($task && $task->status === TaskStatus::InProgress) {
                    $this->taskService->update($taskId, [
                        'add_labels' => ['auto-closed'],
                    ]);

                    Artisan::call('done', [
                        'ids' => [$taskId],
                        '--reason' => 'Auto-completed by run command (agent exit 0)',
                    ]);

                    $this->line('<fg=green>→ Task auto-completed</>');
                }
            }

            return self::SUCCESS;
        }

        // Handle failure
        $this->error(sprintf('✗ Agent failed with exit code %s (%s)', $exitCode, $durationStr));

        // Check for specific failure types
        if ($completion !== null) {
            match ($completion->type) {
                CompletionType::NetworkError => $this->warn('Network error detected - task left in_progress for retry'),
                CompletionType::PermissionBlocked => $this->warn('Permission blocked - agent needs autonomous permissions'),
                default => null,
            };
        }

        return self::FAILURE;
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = (int) ($seconds / 60);
        $secs = $seconds % 60;

        return sprintf('%dm %ds', $minutes, $secs);
    }

    /**
     * Output a chunk of agent output, either raw or parsed.
     */
    private function outputChunk(string $chunk, bool $raw): void
    {
        if ($raw) {
            $this->getOutput()->write(OutputFormatter::escape($chunk));

            return;
        }

        // Parse and format JSONL events
        $events = $this->outputParser->parseChunk($chunk);
        foreach ($events as $event) {
            $formatted = $this->outputParser->format($event);
            if ($formatted !== null) {
                $this->line($formatted);
            }
        }
    }
}
