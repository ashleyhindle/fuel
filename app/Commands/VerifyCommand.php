<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\Task;
use App\Services\ConfigService;
use App\Services\FuelContext;
use App\Services\OutputParser;
use App\Services\ProcessManager;
use App\Services\PromptService;
use App\Services\RunService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

class VerifyCommand extends Command
{
    protected $signature = 'verify
        {id : Task ID to verify (supports partial matching)}
        {--agent= : Agent name to use (defaults to review agent)}
        {--raw : Show raw output instead of formatted}';

    protected $description = 'Run behavioral verification on a completed task';

    public function __construct(
        private readonly TaskService $taskService,
        private readonly ConfigService $configService,
        private readonly RunService $runService,
        private readonly ProcessManager $processManager,
        private readonly OutputParser $outputParser,
        private readonly PromptService $promptService,
        private readonly FuelContext $fuelContext,
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
            $this->error('Task not found: '.$taskId);

            return self::FAILURE;
        }

        $taskId = $task->short_id;
        $this->info('Verifying task: '.$taskId);
        $this->line('Title: '.$task->title);
        $this->newLine();

        // Determine agent (use review agent by default)
        $agentName = $this->option('agent') ?? $this->configService->getReviewAgent();
        if ($agentName === null) {
            $this->error('No review agent configured. Set review agent in .fuel/config.yaml or use --agent option.');

            return self::FAILURE;
        }

        $this->info('Agent: '.$agentName);

        // Build verify prompt
        $template = $this->promptService->loadTemplate('verify');
        $variables = [
            'task' => [
                'id' => $task->short_id,
                'title' => $task->title ?? 'Untitled task',
                'description' => $task->description ?? 'No description provided',
            ],
        ];
        $prompt = $this->promptService->render($template, $variables);

        // Create run entry
        $runId = $this->runService->createRun($taskId, [
            'agent' => $agentName,
            'started_at' => date('c'),
        ]);

        // Spawn the agent
        $this->newLine();
        $this->info('Spawning verification agent...');
        $this->line('<fg=gray>─────────────────────────────────────────</>');
        $this->newLine();

        $result = $this->processManager->spawnForTask($task, $prompt, $cwd, $agentName, $runId);

        if (! $result->success) {
            $this->error('Failed to spawn agent: '.($result->error ?? 'Unknown error'));

            return self::FAILURE;
        }

        $process = $result->process;
        $pid = $process->getPid();
        $this->line('<fg=gray>PID: '.$pid.'</>');

        // Stream output while process runs
        $startTime = time();
        $stdoutPath = $cwd.'/.fuel/processes/'.$runId.'/stdout.log';
        $stderrPath = $cwd.'/.fuel/processes/'.$runId.'/stderr.log';
        $lastStdoutPos = 0;
        $lastStderrPos = 0;
        $rawMode = $this->option('raw');

        while ($this->processManager->isRunning($taskId)) {
            if (file_exists($stdoutPath)) {
                $stdout = file_get_contents($stdoutPath);
                if (strlen($stdout) > $lastStdoutPos) {
                    $newOutput = substr($stdout, $lastStdoutPos);
                    $this->outputChunk($newOutput, $rawMode);
                    $lastStdoutPos = strlen($stdout);
                }
            }

            if (file_exists($stderrPath)) {
                $stderr = file_get_contents($stderrPath);
                if (strlen($stderr) > $lastStderrPos) {
                    $newOutput = substr($stderr, $lastStderrPos);
                    $this->getOutput()->write('<fg=red>'.OutputFormatter::escape($newOutput).'</>');
                    $lastStderrPos = strlen($stderr);
                }
            }

            usleep(100000);
        }

        // Final read
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

        // Get completion result
        $completions = $this->processManager->poll();
        $completion = $completions[0] ?? null;

        $duration = time() - $startTime;
        $exitCode = $completion?->exitCode ?? -1;

        // Update run entry
        $this->runService->updateLatestRun($taskId, [
            'ended_at' => date('c'),
            'exit_code' => $exitCode,
            'output' => $completion?->output ?? '',
        ]);

        $this->newLine();
        $this->line('<fg=gray>─────────────────────────────────────────</>');
        $this->newLine();

        if ($exitCode === 0) {
            $this->info(sprintf('✓ Verification completed (%ds)', $duration));

            return self::SUCCESS;
        }

        $this->error(sprintf('✗ Verification failed with exit code %s (%ds)', $exitCode, $duration));

        return self::FAILURE;
    }

    private function outputChunk(string $chunk, bool $raw): void
    {
        if ($raw) {
            $this->getOutput()->write(OutputFormatter::escape($chunk));

            return;
        }

        $events = $this->outputParser->parseChunk($chunk);
        foreach ($events as $event) {
            $formatted = $this->outputParser->format($event);
            if ($formatted !== null) {
                $this->line($formatted);
            }
        }
    }
}
