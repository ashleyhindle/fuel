<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AgentHealthTrackerInterface;
use App\Contracts\ProcessManagerInterface;
use App\Process\AgentProcess;
use App\Process\CompletionResult;
use App\Process\CompletionType;
use App\Process\Process;
use App\Process\ProcessOutput;
use App\Process\ProcessResult;
use App\Process\ProcessStatus;
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

    /** Flag indicating if the process manager is shutting down */
    private bool $shuttingDown = false;

    /** Number of times SIGTERM/SIGINT has been received */
    private int $shutdownSignalCount = 0;

    /** The working directory where .fuel is located */
    private ?string $cwd = null;

    public function __construct(
        private readonly ?ConfigService $configService = new ConfigService,
        private readonly ?AgentHealthTrackerInterface $healthTracker = null,
        ?string $cwd = null
    ) {
        $this->cwd = $cwd;
    }

    /**
     * Set the working directory for process output.
     */
    public function setCwd(string $cwd): void
    {
        $this->cwd = $cwd;
    }

    /**
     * Get the working directory, defaulting to getcwd() if not set.
     */
    public function getCwd(): string
    {
        return $this->cwd ?? getcwd();
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
        $outputDir = $this->getCwd().'/.fuel/processes/'.$taskId;
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
        $previouslyRunning = [];

        // Track initially running processes
        foreach ($this->activeAgentProcesses as $taskId => $agentProcess) {
            if ($agentProcess->isRunning()) {
                $previouslyRunning[$taskId] = true;
            }
        }

        while ((microtime(true) * 1000 - $startTime) < $timeoutMs) {
            foreach ($this->activeAgentProcesses as $taskId => $agentProcess) {
                // Check if this process was running before but is now completed
                if (isset($previouslyRunning[$taskId]) && ! $agentProcess->isRunning()) {
                    $output = $this->getOutput($taskId);
                    $exitCode = $agentProcess->getExitCode() ?? -1;

                    // Create Process metadata for compatibility
                    $process = new Process(
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
                    $process = new Process(
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
     */
    public function registerSignalHandlers(): void
    {
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

            // Output message to STDERR to ensure it's visible
            fwrite(STDERR, "\n\033[33m⚠ Shutting down gracefully... Press Ctrl+C again to force quit.\033[0m\n");

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

        // Log initial status
        $runningCount = $this->getRunningCount();
        if ($runningCount > 0) {
            fwrite(STDERR, "Stopping {$runningCount} running process(es)...\n");
        }

        // Step 1: Send SIGTERM to all running processes
        foreach ($this->activeAgentProcesses as $taskId => $agentProcess) {
            $symfonyProcess = $agentProcess->getProcess();
            if ($symfonyProcess->isRunning()) {
                $pid = $symfonyProcess->getPid();
                fwrite(STDERR, "  - Sending SIGTERM to {$taskId} (PID: {$pid})\n");
                $symfonyProcess->stop(30); // 30 second timeout before SIGKILL
            }
        }

        // Step 2: Wait for graceful exit (max 30 seconds)
        $timeout = 30;
        $start = time();
        $lastReport = 0;

        while (time() - $start < $timeout) {
            $stillRunning = [];
            foreach ($this->activeAgentProcesses as $taskId => $agentProcess) {
                if ($agentProcess->isRunning()) {
                    $stillRunning[] = $taskId;
                }
            }

            if ($stillRunning === []) {
                fwrite(STDERR, "\033[32m✓ All processes stopped gracefully.\033[0m\n");
                break;
            }

            // Report progress every 5 seconds
            $elapsed = time() - $start;
            if ($elapsed - $lastReport >= 5) {
                $remaining = $timeout - $elapsed;
                fwrite(STDERR, '  Still waiting for '.count($stillRunning)." process(es)... ({$remaining}s remaining)\n");
                $lastReport = $elapsed;
            }

            usleep(100000); // 100ms
        }

        // Step 3: Force kill any remaining processes
        $forceKilled = [];
        foreach ($this->activeAgentProcesses as $taskId => $agentProcess) {
            $symfonyProcess = $agentProcess->getProcess();
            if ($symfonyProcess->isRunning()) {
                $pid = $symfonyProcess->getPid();
                fwrite(STDERR, "\033[31m  - Force killing {$taskId} (PID: {$pid})\033[0m\n");
                $symfonyProcess->stop(0); // Immediate SIGKILL
                $forceKilled[] = $taskId;
            }
        }

        // Step 4: Log final status
        if ($forceKilled !== []) {
            fwrite(STDERR, "\033[31m✗ Had to force kill ".count($forceKilled)." process(es).\033[0m\n");
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
     * @param  string  $agentName  The agent name to check capacity for
     * @return bool True if can spawn, false if at capacity
     */
    public function canSpawn(string $agentName): bool
    {
        $limit = $this->configService->getAgentLimit($agentName);
        $current = $this->agentCounts[$agentName] ?? 0;

        return $current < $limit;
    }

    /**
     * Create output directory and files for a task.
     *
     * @param  string  $taskId  The task ID to create output for
     * @return array{outputDir: string, stdoutPath: string, stderrPath: string}
     */
    private function createOutputDirectory(string $taskId): array
    {
        $outputDir = $this->getCwd().'/.fuel/processes/'.$taskId;

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
        $process->start(function ($type, $buffer) use ($stdoutPath, $stderrPath): void {
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
     * @return Process The spawned process
     */
    public function spawn(string $taskId, string $agent, string $command, string $cwd): Process
    {
        // Create output directory and files
        $outputPaths = $this->createOutputDirectory($taskId);
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
            $stderrPath
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
     * @param  array  $task  The task data
     * @param  string  $fullPrompt  The prompt to send to the agent
     * @param  string  $cwd  The current working directory
     * @param  ?string  $agentOverride  Optional agent override name
     * @return SpawnResult The spawn result with success status and process or error
     */
    public function spawnForTask(array $task, string $fullPrompt, string $cwd, ?string $agentOverride = null): SpawnResult
    {
        $taskId = $task['id'];
        $agentName = $agentOverride;

        if ($agentName === null) {
            $complexity = $task['complexity'] ?? 'simple';
            try {
                $agentName = $this->configService->getAgentForComplexity($complexity);
            } catch (\RuntimeException $e) {
                return SpawnResult::configError($e->getMessage());
            }
        }

        // Check agent health / backoff status before spawning
        if ($this->healthTracker !== null && ! $this->healthTracker->isAvailable($agentName)) {
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
                $commandArray[] = '--model';
                $commandArray[] = $agentDef['model'];
            }

            // Add additional args
            foreach ($agentDef['args'] as $arg) {
                $commandArray[] = $arg;
            }

            // Create output directory and files
            $outputPaths = $this->createOutputDirectory($taskId);
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
                $stderrPath
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

                // Create completion result
                $completions[] = new CompletionResult(
                    taskId: $taskId,
                    agentName: $agentProcess->getAgentName(),
                    exitCode: $exitCode,
                    duration: $duration,
                    sessionId: $agentProcess->getSessionId(),
                    costUsd: null, // TODO: Extract cost from output if available
                    output: $output,
                    type: $type,
                    message: $message
                );

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
