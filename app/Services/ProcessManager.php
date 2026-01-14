<?php

declare(strict_types=1);

namespace App\Services;

use App\Agents\Tasks\AgentTaskInterface;
use App\Contracts\AgentHealthTrackerInterface;
use App\Contracts\ProcessManagerInterface;
use App\Models\Task;
use App\Process\AgentProcess;
use App\Process\CompletionResult;
use App\Process\CompletionType;
use App\Process\Process;
use App\Process\ProcessOutput;
use App\Process\ProcessResult;
use App\Process\ProcessStatus;
use App\Process\ProcessType;
use App\Process\SpawnResult;
use DateTimeImmutable;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Manages process lifecycle: spawning, monitoring, and handling their lifecycle.
 */
class ProcessManager implements ProcessManagerInterface
{
    /** Poll interval in microseconds (default 100ms) */
    private const POLL_INTERVAL_US = 100000;

    /** @var array<string, AgentProcess> Active agent processes indexed by taskId */
    private array $activeAgentProcesses = [];

    /** @var array<string, int> Agent process counts indexed by agent name */
    private array $agentCounts = [];

    /** @var array<string, AgentTaskInterface> Agent tasks indexed by taskId for lifecycle hooks */
    private array $agentTasks = [];

    /** Flag indicating if the process manager is shutting down */
    private bool $shuttingDown = false;

    /** Number of times SIGTERM/SIGINT has been received */
    private int $shutdownSignalCount = 0;

    /** Optional callback for output chunks: (taskId, stream, chunk) => void */
    private $outputCallback;

    public function __construct(
        private readonly ConfigService $configService,
        private readonly FuelContext $fuelContext,
        private readonly ?AgentHealthTrackerInterface $healthTracker = null,
        /** Flag indicating if this is a runner process (vs client) */
        private readonly bool $isRunner = true
    ) {}

    /**
     * Set callback for output chunks (used by IPC runner to broadcast output).
     */
    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    /**
     * Check if a process for the given task is currently running.
     *
     * @param  string  $taskId  The task ID to check
     * @return bool True if the process is running, false otherwise
     */
    public function isRunning(string $taskId): bool
    {
        if (! isset($this->activeAgentProcesses[$taskId])) {
            return false;
        }

        return $this->activeAgentProcesses[$taskId]->isRunning();
    }

    /**
     * Kill the process associated with the given task.
     *
     * @param  string  $taskId  The task ID whose process should be killed
     */
    public function kill(string $taskId): void
    {
        if (! isset($this->activeAgentProcesses[$taskId])) {
            return;
        }

        $agentProcess = $this->activeAgentProcesses[$taskId];
        $symfonyProcess = $agentProcess->getProcess();

        // Try SIGTERM first
        if ($symfonyProcess->isRunning()) {
            $symfonyProcess->stop(5); // 5 second timeout, then SIGKILL
        }

        // Clean up tracking
        unset($this->activeAgentProcesses[$taskId]);
        $agentName = $agentProcess->getAgentName();
        if (isset($this->agentCounts[$agentName])) {
            $this->agentCounts[$agentName]--;
            if ($this->agentCounts[$agentName] <= 0) {
                unset($this->agentCounts[$agentName]);
            }
        }
    }

    /**
     * Get the output (stdout and stderr) for a given task's process.
     *
     * @param  string  $taskId  The task ID whose output to retrieve
     * @return ProcessOutput The process output
     */
    public function getOutput(string $taskId): ProcessOutput
    {
        // Try to get run ID from active process if available
        $runId = null;
        if (isset($this->activeAgentProcesses[$taskId])) {
            $runId = $this->activeAgentProcesses[$taskId]->getRunId();
        }

        // Use run ID if available, otherwise fall back to task ID
        $outputDir = $this->fuelContext->getProcessesPath().'/'.($runId ?? $taskId);
        $stdoutPath = $outputDir.'/stdout.log';
        $stderrPath = $outputDir.'/stderr.log';

        $stdout = '';
        $stderr = '';

        // Read from files
        if (File::exists($stdoutPath)) {
            $stdout = File::get($stdoutPath);
        }

        if (File::exists($stderrPath)) {
            $stderr = File::get($stderrPath);
        }

        return new ProcessOutput(
            stdout: $stdout,
            stderr: $stderr,
            stdoutPath: $stdoutPath,
            stderrPath: $stderrPath
        );
    }

    /**
     * Get the count of currently running processes.
     *
     * @return int The number of running processes
     */
    public function getRunningCount(): int
    {
        $count = 0;
        foreach ($this->activeAgentProcesses as $agentProcess) {
            if ($agentProcess->isRunning()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get all currently running processes.
     *
     * @return array<Process> Array of running processes
     */
    public function getRunningProcesses(): array
    {
        $runningProcesses = [];

        foreach ($this->activeAgentProcesses as $taskId => $agentProcess) {
            if ($agentProcess->isRunning()) {
                // Create Process object from AgentProcess data
                $runningProcesses[] = new Process(
                    id: 'p-'.substr(bin2hex(random_bytes(3)), 0, 6),
                    taskId: $taskId,
                    agent: $agentProcess->getAgentName(),
                    command: '', // Command not stored in AgentProcess
                    cwd: '', // CWD not stored in AgentProcess
                    pid: $agentProcess->getPid() ?? 0,
                    status: ProcessStatus::Running,
                    exitCode: null,
                    startedAt: new DateTimeImmutable('@'.$agentProcess->getStartTime())
                );
            }
        }

        return $runningProcesses;
    }

    /**
     * Wait for any process to complete, up to the given timeout.
     *
     * @param  int  $timeoutMs  Timeout in milliseconds
     * @return ProcessResult|null The result of the first completed process, or null if timeout
     */
    public function waitForAny(int $timeoutMs): ?ProcessResult
    {
        $startTime = microtime(true) * 1000;

        while ((microtime(true) * 1000 - $startTime) < $timeoutMs) {
            foreach ($this->activeAgentProcesses as $taskId => $agentProcess) {
                // Check if this process has completed
                if (! $agentProcess->isRunning()) {
                    $output = $this->getOutput($taskId);
                    $exitCode = $agentProcess->getExitCode() ?? -1;

                    // Create Process metadata for compatibility
                    $process = $this->createProcessFromAgentProcess($taskId, $agentProcess, $exitCode);

                    // Clean up tracking
                    unset($this->activeAgentProcesses[$taskId]);
                    $agentName = $agentProcess->getAgentName();
                    if (isset($this->agentCounts[$agentName])) {
                        $this->agentCounts[$agentName]--;
                        if ($this->agentCounts[$agentName] <= 0) {
                            unset($this->agentCounts[$agentName]);
                        }
                    }

                    return new ProcessResult(
                        process: $process,
                        output: $output,
                        success: $exitCode === 0
                    );
                }
            }

            usleep(self::POLL_INTERVAL_US);
        }

        return null;
    }

    /**
     * Wait for all processes to complete, up to the given timeout.
     *
     * @param  int  $timeoutMs  Timeout in milliseconds
     * @return array<ProcessResult> Array of process results for all completed processes
     */
    public function waitForAll(int $timeoutMs): array
    {
        $results = [];
        $startTime = microtime(true) * 1000;

        while ((microtime(true) * 1000 - $startTime) < $timeoutMs) {
            $allCompleted = true;

            foreach ($this->activeAgentProcesses as $taskId => $agentProcess) {
                if ($agentProcess->isRunning()) {
                    $allCompleted = false;
                } elseif (! isset($results[$taskId])) {
                    // Process completed but not yet in results
                    $output = $this->getOutput($taskId);
                    $exitCode = $agentProcess->getExitCode() ?? -1;

                    // Create Process metadata for compatibility
                    $process = $this->createProcessFromAgentProcess($taskId, $agentProcess, $exitCode);

                    $results[$taskId] = new ProcessResult(
                        process: $process,
                        output: $output,
                        success: $exitCode === 0
                    );
                }
            }

            if ($allCompleted) {
                break;
            }

            usleep(self::POLL_INTERVAL_US);
        }

        return array_values($results);
    }

    /**
     * Register signal handlers for graceful shutdown.
     * Should be called at the start of consume command.
     * No-op when isRunner=false (client mode doesn't own processes).
     */
    public function registerSignalHandlers(): void
    {
        // Client mode doesn't own processes, so don't register signal handlers
        if (! $this->isRunner) {
            return;
        }

        if (! extension_loaded('pcntl')) {
            throw new \RuntimeException('PCNTL extension is required for signal handling');
        }

        // Register handlers for SIGTERM and SIGINT
        pcntl_signal(SIGTERM, $this->handleShutdownSignal(...));
        pcntl_signal(SIGINT, $this->handleShutdownSignal(...));
    }

    /**
     * Handle shutdown signals (SIGTERM/SIGINT).
     *
     * @param  int  $signal  The signal number received
     */
    public function handleShutdownSignal(int $signal): void
    {
        $this->shutdownSignalCount++;

        if ($this->shutdownSignalCount === 1) {
            // First signal: graceful shutdown
            $this->shuttingDown = true;

            // Start graceful shutdown process
            $this->shutdown();
        } else {
            // Second signal: force immediate exit
            fwrite(STDERR, "\n\033[31m✗ Forcing immediate shutdown...\033[0m\n");

            // Force kill all processes immediately
            foreach ($this->activeAgentProcesses as $agentProcess) {
                $symfonyProcess = $agentProcess->getProcess();
                if ($symfonyProcess->isRunning()) {
                    $symfonyProcess->stop(0); // Immediate SIGKILL
                }
            }

            exit(130); // Standard exit code for SIGINT
        }
    }

    /**
     * Check if the process manager is shutting down.
     *
     * @return bool True if shutting down, false otherwise
     */
    public function isShuttingDown(): bool
    {
        return $this->shuttingDown;
    }

    /**
     * Gracefully shutdown the process manager, terminating all running processes.
     */
    public function shutdown(): void
    {
        $this->shuttingDown = true;

        // Step 1: Send SIGTERM to all running processes
        foreach ($this->activeAgentProcesses as $taskId => $agentProcess) {
            $symfonyProcess = $agentProcess->getProcess();
            if ($symfonyProcess->isRunning()) {
                $symfonyProcess->stop(30); // 30 second timeout before SIGKILL
            }
        }

        // Step 2: Wait for graceful exit (max 30 seconds)
        $timeout = 30;
        $start = time();

        while (time() - $start < $timeout) {
            $stillRunning = [];
            foreach ($this->activeAgentProcesses as $taskId => $agentProcess) {
                if ($agentProcess->isRunning()) {
                    $stillRunning[] = $taskId;
                }
            }

            if ($stillRunning === []) {
                break;
            }

            usleep(100000); // 100ms
        }

        // Step 3: Force kill any remaining processes
        foreach ($this->activeAgentProcesses as $agentProcess) {
            $symfonyProcess = $agentProcess->getProcess();
            if ($symfonyProcess->isRunning()) {
                $symfonyProcess->stop(0); // Immediate SIGKILL
            }
        }

        // Clear process tracking
        $this->activeAgentProcesses = [];
        $this->agentCounts = [];
    }

    /**
     * Check if any processes are active.
     *
     * @return bool True if there are active processes, false otherwise
     */
    public function hasActiveProcesses(): bool
    {
        return $this->activeAgentProcesses !== [];
    }

    /**
     * Get count of active processes.
     *
     * @return int The number of active processes
     */
    public function getActiveCount(): int
    {
        return count($this->activeAgentProcesses);
    }

    /**
     * Check if can spawn a new process for the given agent.
     *
     * Checks both per-agent limits and global max concurrent limit.
     *
     * @param  string  $agentName  The agent name to check capacity for
     * @return bool True if can spawn, false if at capacity
     */
    public function canSpawn(string $agentName): bool
    {
        // Check global limit first
        $globalLimit = $this->configService->getGlobalMaxConcurrent();
        if ($this->getActiveCount() >= $globalLimit) {
            return false;
        }

        // Check per-agent limit
        $limit = $this->configService->getAgentLimit($agentName);
        $current = $this->agentCounts[$agentName] ?? 0;

        return $current < $limit;
    }

    /**
     * Create a Process object from an AgentProcess.
     *
     * @param  string  $taskId  The task ID
     * @param  AgentProcess  $agentProcess  The agent process
     * @param  int  $exitCode  The exit code
     * @return Process The created process object
     */
    private function createProcessFromAgentProcess(string $taskId, AgentProcess $agentProcess, int $exitCode): Process
    {
        return new Process(
            id: 'p-'.substr(bin2hex(random_bytes(3)), 0, 6),
            taskId: $taskId,
            agent: $agentProcess->getAgentName(),
            command: '', // Not available
            cwd: '', // Not available
            pid: $agentProcess->getPid() ?? 0,
            status: $exitCode === 0 ? ProcessStatus::Completed : ProcessStatus::Failed,
            exitCode: $exitCode,
            startedAt: new DateTimeImmutable('@'.$agentProcess->getStartTime()),
            completedAt: new DateTimeImmutable
        );
    }

    /**
     * Create output directory and files for a run.
     *
     * @param  string  $runId  The run ID to create output for
     * @return array{outputDir: string, stdoutPath: string, stderrPath: string}
     */
    private function createOutputDirectory(string $runId): array
    {
        $outputDir = $this->fuelContext->getProcessesPath().'/'.$runId;

        if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true)) {
            throw new \RuntimeException('Failed to create output directory: '.$outputDir);
        }

        $stdoutPath = $outputDir.'/stdout.log';
        $stderrPath = $outputDir.'/stderr.log';

        if (file_put_contents($stdoutPath, '') === false) {
            throw new \RuntimeException('Failed to create stdout file: '.$stdoutPath);
        }

        if (file_put_contents($stderrPath, '') === false) {
            throw new \RuntimeException('Failed to create stderr file: '.$stderrPath);
        }

        if (! file_exists($stdoutPath) || ! file_exists($stderrPath)) {
            throw new \RuntimeException('Output files not found after creation: '.$outputDir);
        }

        return [
            'outputDir' => $outputDir,
            'stdoutPath' => $stdoutPath,
            'stderrPath' => $stderrPath,
        ];
    }

    /**
     * Create and configure a Symfony Process.
     *
     * @param  array  $commandArray  The command array to execute
     * @param  string  $cwd  The current working directory
     * @param  array|null  $env  Environment variables
     */
    private function createSymfonyProcess(array $commandArray, string $cwd, ?array $env = null): SymfonyProcess
    {
        $symfonyProcess = new SymfonyProcess(
            $commandArray,
            $cwd,
            $env,
            null,  // no stdin
            null   // no timeout
        );

        $symfonyProcess->setTimeout(null);
        $symfonyProcess->setIdleTimeout(null);

        return $symfonyProcess;
    }

    /**
     * Start a process with output capture to files.
     *
     * @param  SymfonyProcess  $process  The process to start
     * @param  string  $stdoutPath  Path to stdout log file
     * @param  string  $stderrPath  Path to stderr log file
     */
    private function startWithOutputCapture(SymfonyProcess $process, string $stdoutPath, string $stderrPath): void
    {
        $process->start(function (string $type, string $buffer) use ($stdoutPath, $stderrPath): void {
            $path = $type === SymfonyProcess::ERR ? $stderrPath : $stdoutPath;

            // Recreate directory if it was deleted (e.g., by git clean -X)
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            // Silently ignore write failures - output capture is best-effort
            @file_put_contents($path, $buffer, FILE_APPEND);
        });
    }

    /**
     * Spawn a new process for a given task and agent (interface method).
     *
     * This is a simple interface for tests and basic usage. It takes a command
     * string directly and doesn't require agent configuration from ConfigService.
     *
     * @param  string  $taskId  The ID of the task to process
     * @param  string  $agent  The agent name (for tracking purposes only)
     * @param  string  $command  The command to execute
     * @param  string  $cwd  The current working directory for the process
     * @param  ProcessType  $processType  The type of process (task or review)
     * @param  string|null  $runId  The run ID for directory organization (optional)
     * @return Process The spawned process
     */
    public function spawn(string $taskId, string $agent, string $command, string $cwd, ProcessType $processType = ProcessType::Task, ?string $runId = null): Process
    {
        // Create output directory and files
        $outputPaths = $this->createOutputDirectory($runId ?? $taskId);
        $stdoutPath = $outputPaths['stdoutPath'];
        $stderrPath = $outputPaths['stderrPath'];

        // Use shell to handle the command string properly
        $commandArray = ['sh', '-c', $command];

        // Create and configure the process
        $symfonyProcess = $this->createSymfonyProcess($commandArray, $cwd);

        // Start process with output capture
        $this->startWithOutputCapture($symfonyProcess, $stdoutPath, $stderrPath);

        // Create AgentProcess for unified tracking with file paths
        $agentProcess = new AgentProcess(
            $symfonyProcess,
            $taskId,
            $agent,
            time(),
            $stdoutPath,
            $stderrPath,
            $processType,
            null, // model not available in simple spawn
            $runId
        );

        // Track the process
        $this->activeAgentProcesses[$taskId] = $agentProcess;
        $this->agentCounts[$agent] = ($this->agentCounts[$agent] ?? 0) + 1;

        // Return Process metadata
        return new Process(
            id: 'p-'.substr(bin2hex(random_bytes(3)), 0, 6),
            taskId: $taskId,
            agent: $agent,
            command: $command,
            cwd: $cwd,
            pid: $symfonyProcess->getPid() ?? 0,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable
        );
    }

    /**
     * Spawn a new process for a task with ConsumeCommand-compatible signature.
     *
     * This is the method that ConsumeCommand should call.
     *
     * @deprecated Use spawnAgentTask() with WorkAgentTask instead
     *
     * @param  Task  $task  The task model
     * @param  string  $fullPrompt  The prompt to send to the agent
     * @param  string  $cwd  The current working directory
     * @param  ?string  $agentOverride  Optional agent override name
     * @param  ?string  $runId  Optional run ID for directory organization
     * @return SpawnResult The spawn result with success status and process or error
     */
    public function spawnForTask(Task $task, string $fullPrompt, string $cwd, ?string $agentOverride = null, ?string $runId = null): SpawnResult
    {
        $taskId = $task->short_id;
        $agentName = $agentOverride;

        if ($agentName === null) {
            $complexity = $task->complexity ?? 'simple';
            try {
                $agentName = $this->configService->getAgentForComplexity($complexity);
            } catch (\RuntimeException $e) {
                return SpawnResult::configError($e->getMessage());
            }
        }

        // Check agent health / backoff status before spawning
        if ($this->healthTracker instanceof AgentHealthTrackerInterface && ! $this->healthTracker->isAvailable($agentName)) {
            $backoffSeconds = $this->healthTracker->getBackoffSeconds($agentName);

            return SpawnResult::agentInBackoff($agentName, $backoffSeconds);
        }

        // Check capacity
        if (! $this->canSpawn($agentName)) {
            return SpawnResult::atCapacity($agentName);
        }

        try {
            // Get agent definition
            $agentDef = $this->configService->getAgentDefinition($agentName);

            // Build command array
            $commandArray = [$agentDef['command']];
            foreach ($agentDef['prompt_args'] as $promptArg) {
                $commandArray[] = $promptArg;
            }

            $commandArray[] = $fullPrompt;

            // Add model if specified
            if (! empty($agentDef['model'])) {
                $commandArray[] = $agentDef['model_arg'];
                $commandArray[] = $agentDef['model'];
            }

            // Add additional args
            foreach ($agentDef['args'] as $arg) {
                $commandArray[] = $arg;
            }

            // Create output directory and files
            $outputPaths = $this->createOutputDirectory($runId ?? $taskId);
            $stdoutPath = $outputPaths['stdoutPath'];
            $stderrPath = $outputPaths['stderrPath'];

            // Create and configure the process with env vars
            $env = array_merge($_ENV, $agentDef['env']);
            $symfonyProcess = $this->createSymfonyProcess($commandArray, $cwd, $env);

            // Start process with output capture
            $this->startWithOutputCapture($symfonyProcess, $stdoutPath, $stderrPath);

            // Check if process started successfully
            if (! $symfonyProcess->isRunning()) {
                return SpawnResult::spawnFailed($taskId);
            }

            // Create AgentProcess instance with file paths
            $agentProcess = new AgentProcess(
                $symfonyProcess,
                $taskId,
                $agentName,
                time(),
                $stdoutPath,
                $stderrPath,
                ProcessType::Task,
                $agentDef['model'] ?? null,
                $runId
            );

            // Track the process
            $this->activeAgentProcesses[$taskId] = $agentProcess;
            $this->agentCounts[$agentName] = ($this->agentCounts[$agentName] ?? 0) + 1;

            return SpawnResult::success($agentProcess);

        } catch (\Exception $exception) {
            return SpawnResult::configError($exception->getMessage());
        }
    }

    /**
     * Spawn a new process using the AgentTask abstraction.
     *
     * This method encapsulates agent selection, prompt building, and lifecycle hooks.
     * Use this for new code; prefer over spawnForTask() when using AgentTask implementations.
     *
     * @param  AgentTaskInterface  $agentTask  The agent task abstraction
     * @param  string  $cwd  The current working directory
     * @param  string|null  $runId  Optional run ID for directory organization
     * @return SpawnResult The spawn result with success status and process or error
     */
    public function spawnAgentTask(AgentTaskInterface $agentTask, string $cwd, ?string $runId = null): SpawnResult
    {
        $taskId = $agentTask->getTaskId();

        // Get agent name from the task abstraction
        $agentName = $agentTask->getAgentName($this->configService);
        if ($agentName === null) {
            return SpawnResult::configError('No agent configured for task');
        }

        // Check agent health / backoff status before spawning
        if ($this->healthTracker instanceof AgentHealthTrackerInterface && ! $this->healthTracker->isAvailable($agentName)) {
            $backoffSeconds = $this->healthTracker->getBackoffSeconds($agentName);

            return SpawnResult::agentInBackoff($agentName, $backoffSeconds);
        }

        // Check capacity
        if (! $this->canSpawn($agentName)) {
            return SpawnResult::atCapacity($agentName);
        }

        try {
            // Get agent definition
            $agentDef = $this->configService->getAgentDefinition($agentName);

            // Build prompt from the task abstraction
            $fullPrompt = $agentTask->buildPrompt($cwd);

            // Build command array
            $commandArray = [$agentDef['command']];
            foreach ($agentDef['prompt_args'] as $promptArg) {
                $commandArray[] = $promptArg;
            }

            $commandArray[] = $fullPrompt;

            // Add model if specified
            if (! empty($agentDef['model'])) {
                $commandArray[] = '--model';
                $commandArray[] = $agentDef['model'];
            }

            // Add additional args
            foreach ($agentDef['args'] as $arg) {
                $commandArray[] = $arg;
            }

            // Create output directory and files
            $outputPaths = $this->createOutputDirectory($runId ?? $taskId);
            $stdoutPath = $outputPaths['stdoutPath'];
            $stderrPath = $outputPaths['stderrPath'];

            // Create and configure the process with env vars
            $env = array_merge($_ENV, $agentDef['env']);
            $symfonyProcess = $this->createSymfonyProcess($commandArray, $cwd, $env);

            // Start process with output capture
            $this->startWithOutputCapture($symfonyProcess, $stdoutPath, $stderrPath);

            // Check if process started successfully
            if (! $symfonyProcess->isRunning()) {
                return SpawnResult::spawnFailed($taskId);
            }

            // Get process type from the task abstraction
            $processType = $agentTask->getProcessType();

            // Create AgentProcess instance with file paths
            $agentProcess = new AgentProcess(
                $symfonyProcess,
                $taskId,
                $agentName,
                time(),
                $stdoutPath,
                $stderrPath,
                $processType,
                $agentDef['model'] ?? null,
                $runId
            );

            // Track the process
            $this->activeAgentProcesses[$taskId] = $agentProcess;
            $this->agentCounts[$agentName] = ($this->agentCounts[$agentName] ?? 0) + 1;

            // Store agentTask for lifecycle hooks in poll()
            $this->agentTasks[$taskId] = $agentTask;

            return SpawnResult::success($agentProcess);

        } catch (\Exception $exception) {
            return SpawnResult::configError($exception->getMessage());
        }
    }

    /**
     * Get array of active AgentProcess objects.
     *
     * @return array<AgentProcess>
     */
    public function getActiveProcesses(): array
    {
        return array_values($this->activeAgentProcesses);
    }

    /**
     * Poll processes and return completions.
     *
     * @return array<CompletionResult>
     */
    public function poll(): array
    {
        $completions = [];

        foreach ($this->activeAgentProcesses as $taskId => $agentProcess) {
            // Read incremental output for session ID extraction
            $incrementalOutput = $agentProcess->getIncrementalOutput();
            if (! empty($incrementalOutput)) {
                $agentProcess->appendToOutputBuffer($incrementalOutput);

                // Invoke output callback if set (for IPC broadcasting)
                if ($this->outputCallback !== null) {
                    ($this->outputCallback)($taskId, 'stdout', $incrementalOutput);
                }

                // Try to extract session ID if not already captured
                if (! $agentProcess->isSessionIdCaptured()) {
                    // Pattern for Claude session ID
                    if (preg_match('/Session ID:\s*([a-f0-9-]{36})/i', $agentProcess->getOutputBuffer(), $matches)) {
                        $agentProcess->setSessionId($matches[1]);
                    }
                    // Pattern for cursor-agent session ID
                    elseif (preg_match('/session_id["\']?\s*[:=]\s*["\']?([a-f0-9-]{36})/i', $agentProcess->getOutputBuffer(), $matches)) {
                        $agentProcess->setSessionId($matches[1]);
                    }
                }
            }

            // Check if process completed
            if (! $agentProcess->isRunning()) {
                $exitCode = $agentProcess->getExitCode() ?? -1;
                $duration = $agentProcess->getDuration();
                $output = $agentProcess->getOutput();

                // Determine completion type based on exit code and output
                $type = CompletionType::Failed;
                $message = null;

                if ($exitCode === 0) {
                    $type = CompletionType::Success;
                } elseif ($exitCode === 1) {
                    // Check for network errors
                    if (preg_match('/(network|connection|timeout|api.*error)/i', $output)) {
                        $type = CompletionType::NetworkError;
                        $message = 'Network error detected';
                    }
                    // Check for permission blocks
                    elseif (preg_match('/(permission.*denied|blocked.*tool|require.*approval)/i', $output)) {
                        $type = CompletionType::PermissionBlocked;
                        $message = 'Agent blocked by permissions';
                    }
                }

                // Extract cost and model from output
                $extracted = $this->extractCostAndModelFromOutput($output);

                // Create completion result
                $completion = new CompletionResult(
                    taskId: $taskId,
                    agentName: $agentProcess->getAgentName(),
                    exitCode: $exitCode,
                    duration: $duration,
                    sessionId: $agentProcess->getSessionId(),
                    costUsd: $extracted['cost_usd'],
                    output: $output,
                    type: $type,
                    message: $message,
                    processType: $agentProcess->getProcessType(),
                    model: $extracted['model'] ?? $agentProcess->getModel()
                );

                $completions[] = $completion;

                // Call lifecycle hooks if AgentTask was registered
                if (isset($this->agentTasks[$taskId])) {
                    $agentTask = $this->agentTasks[$taskId];

                    // Always call onComplete first
                    $agentTask->onComplete($completion);

                    // Then call success/failure hook based on result
                    if ($completion->isSuccess()) {
                        $agentTask->onSuccess($completion);
                    } else {
                        $agentTask->onFailure($completion);
                    }

                    // Clean up agentTask tracking
                    unset($this->agentTasks[$taskId]);
                }

                // Clean up tracking
                unset($this->activeAgentProcesses[$taskId]);
                $agentName = $agentProcess->getAgentName();
                if (isset($this->agentCounts[$agentName])) {
                    $this->agentCounts[$agentName]--;
                    if ($this->agentCounts[$agentName] <= 0) {
                        unset($this->agentCounts[$agentName]);
                    }
                }
            }
        }

        return $completions;
    }

    /**
     * Get array of tracked process PIDs.
     *
     * @return array<int>
     */
    public function getTrackedPids(): array
    {
        $pids = [];
        foreach ($this->activeAgentProcesses as $agentProcess) {
            $pid = $agentProcess->getPid();
            if ($pid !== null) {
                $pids[] = $pid;
            }
        }

        return $pids;
    }

    /**
     * Extract cost and model from agent output.
     *
     * Looks for the final JSON result line which contains total_cost_usd and model info.
     *
     * @param  string  $output  The agent output
     * @return array{cost_usd: ?float, model: ?string}
     */
    private function extractCostAndModelFromOutput(string $output): array
    {
        $result = ['cost_usd' => null, 'model' => null];

        // Split output by newlines
        $lines = explode("\n", $output);

        // Track step costs for agents that report per-step (e.g., opencode)
        $stepCostSum = 0.0;
        $hasStepCosts = false;

        // Search through all lines
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $data = json_decode($line, true);
            if (! is_array($data)) {
                continue;
            }

            $type = $data['type'] ?? '';

            // Claude Code format: total_cost_usd in result line
            if ($type === 'result') {
                if (isset($data['total_cost_usd'])) {
                    $result['cost_usd'] = (float) $data['total_cost_usd'];
                }

                // Extract model from modelUsage if available
                if (isset($data['modelUsage']) && is_array($data['modelUsage'])) {
                    $primaryModel = null;
                    $maxOutput = 0;
                    foreach ($data['modelUsage'] as $model => $usage) {
                        $outputTokens = $usage['outputTokens'] ?? 0;
                        if ($outputTokens > $maxOutput) {
                            $maxOutput = $outputTokens;
                            $primaryModel = $model;
                        }
                    }

                    $result['model'] = $primaryModel;
                }
            }

            // Opencode format: cost in step_finish events (sum them up)
            if ($type === 'step_finish') {
                $partData = $data['part'] ?? $data;
                if (isset($partData['cost']) && is_numeric($partData['cost'])) {
                    $stepCostSum += (float) $partData['cost'];
                    $hasStepCosts = true;
                }
            }

            // Check init line for model
            if ($type === 'system' && ($data['subtype'] ?? '') === 'init' && ($result['model'] === null && isset($data['model']))) {
                $result['model'] = $data['model'];
            }
        }

        // Use step costs if we didn't get a total from result line
        if ($result['cost_usd'] === null && $hasStepCosts) {
            $result['cost_usd'] = $stepCostSum;
        }

        // If we didn't find model in result, check the first line for init
        if ($result['model'] === null && $lines !== []) {
            $firstLine = trim($lines[0]);
            $data = json_decode($firstLine, true);
            if (is_array($data) && ($data['type'] ?? '') === 'system' && ($data['subtype'] ?? '') === 'init') {
                $result['model'] = $data['model'] ?? null;
            }
        }

        return $result;
    }

    /**
     * Check if a process with given PID is alive.
     *
     * @param  int  $pid  The process ID to check
     * @return bool True if process is alive, false otherwise
     */
    public static function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // On Unix-like systems, signal 0 checks if process exists
        // posix_kill returns true if the signal was sent (process exists)
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        // Fallback: check /proc on Linux
        if (PHP_OS_FAMILY === 'Linux') {
            return file_exists('/proc/'.$pid);
        }

        // Fallback: use ps command
        $output = shell_exec(sprintf('ps -p %d -o pid=', $pid));

        return ! in_array(trim($output ?? ''), ['', '0'], true);
    }
}
