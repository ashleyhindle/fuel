<?php

declare(strict_types=1);

namespace App\Services;

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

    /** @var array<string, SymfonyProcess> Active processes indexed by taskId */
    private array $processes = [];

    /** @var array<string, Process> Process metadata indexed by taskId */
    private array $processMetadata = [];

    /** @var array<string, AgentProcess> Active agent processes indexed by taskId */
    private array $activeAgentProcesses = [];

    /** @var array<string, int> Agent process counts indexed by agent name */
    private array $agentCounts = [];

    private ConfigService $configService;

    /** Flag indicating if the process manager is shutting down */
    private bool $shuttingDown = false;

    /** Number of times SIGTERM/SIGINT has been received */
    private int $shutdownSignalCount = 0;

    public function __construct(?ConfigService $configService = null)
    {
        $this->configService = $configService ?? new ConfigService;
    }

    /**
     * Check if a process for the given task is currently running.
     *
     * @param  string  $taskId  The task ID to check
     * @return bool True if the process is running, false otherwise
     */
    public function isRunning(string $taskId): bool
    {
        if (! isset($this->processes[$taskId])) {
            return false;
        }

        $symfonyProcess = $this->processes[$taskId];
        $isRunning = $symfonyProcess->isRunning();

        // Update metadata if process completed
        if (! $isRunning && isset($this->processMetadata[$taskId])) {
            $metadata = $this->processMetadata[$taskId];
            if ($metadata->status === ProcessStatus::Running) {
                $exitCode = $symfonyProcess->getExitCode();
                $status = match ($exitCode) {
                    0 => ProcessStatus::Completed,
                    -1, null => ProcessStatus::Failed,
                    default => ProcessStatus::Failed
                };

                $this->processMetadata[$taskId] = new Process(
                    id: $metadata->id,
                    taskId: $metadata->taskId,
                    agent: $metadata->agent,
                    command: $metadata->command,
                    cwd: $metadata->cwd,
                    pid: $metadata->pid,
                    status: $status,
                    exitCode: $exitCode,
                    startedAt: $metadata->startedAt,
                    completedAt: new DateTimeImmutable
                );
            }
        }

        return $isRunning;
    }

    /**
     * Kill the process associated with the given task.
     *
     * @param  string  $taskId  The task ID whose process should be killed
     */
    public function kill(string $taskId): void
    {
        if (! isset($this->processes[$taskId])) {
            return;
        }

        $symfonyProcess = $this->processes[$taskId];

        // Try SIGTERM first
        if ($symfonyProcess->isRunning()) {
            $symfonyProcess->stop(5); // 5 second timeout, then SIGKILL
        }

        // Update metadata
        if (isset($this->processMetadata[$taskId])) {
            $metadata = $this->processMetadata[$taskId];
            $this->processMetadata[$taskId] = new Process(
                id: $metadata->id,
                taskId: $metadata->taskId,
                agent: $metadata->agent,
                command: $metadata->command,
                cwd: $metadata->cwd,
                pid: $metadata->pid,
                status: ProcessStatus::Killed,
                exitCode: $symfonyProcess->getExitCode() ?? -1,
                startedAt: $metadata->startedAt,
                completedAt: new DateTimeImmutable
            );
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
        $outputDir = storage_path(".fuel/processes/{$taskId}");
        $stdoutPath = "{$outputDir}/stdout.log";
        $stderrPath = "{$outputDir}/stderr.log";

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
        foreach ($this->processes as $taskId => $process) {
            if ($this->isRunning($taskId)) {
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

        foreach ($this->processMetadata as $taskId => $metadata) {
            if ($this->isRunning($taskId)) {
                $runningProcesses[] = $metadata;
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
            foreach ($this->processes as $taskId => $symfonyProcess) {
                // Check if this process just completed
                if (! $symfonyProcess->isRunning() &&
                    isset($this->processMetadata[$taskId]) &&
                    $this->processMetadata[$taskId]->status === ProcessStatus::Running) {

                    // Update status and return result
                    $this->isRunning($taskId); // This updates the metadata

                    $metadata = $this->processMetadata[$taskId];
                    $output = $this->getOutput($taskId);

                    return new ProcessResult(
                        process: $metadata,
                        output: $output,
                        success: $metadata->exitCode === 0
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

            foreach ($this->processes as $taskId => $symfonyProcess) {
                if ($this->isRunning($taskId)) {
                    $allCompleted = false;
                } elseif (! isset($results[$taskId])) {
                    // Process completed but not yet in results
                    $metadata = $this->processMetadata[$taskId];
                    $output = $this->getOutput($taskId);

                    $results[$taskId] = new ProcessResult(
                        process: $metadata,
                        output: $output,
                        success: $metadata->exitCode === 0
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
        pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
        pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
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
            foreach ($this->processes as $symfonyProcess) {
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
        foreach ($this->processes as $taskId => $symfonyProcess) {
            if ($symfonyProcess->isRunning()) {
                $pid = $symfonyProcess->getPid();
                $metadata = $this->processMetadata[$taskId];
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
            foreach ($this->processes as $taskId => $symfonyProcess) {
                if ($symfonyProcess->isRunning()) {
                    $stillRunning[] = $taskId;
                }
            }

            if (empty($stillRunning)) {
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
        foreach ($this->processes as $taskId => $symfonyProcess) {
            if ($symfonyProcess->isRunning()) {
                $pid = $symfonyProcess->getPid();
                fwrite(STDERR, "\033[31m  - Force killing {$taskId} (PID: {$pid})\033[0m\n");
                $symfonyProcess->stop(0); // Immediate SIGKILL
                $forceKilled[] = $taskId;
            }
        }

        // Step 4: Log final status
        if (! empty($forceKilled)) {
            fwrite(STDERR, "\033[31m✗ Had to force kill ".count($forceKilled)." process(es).\033[0m\n");
        }

        // Update metadata for all terminated processes
        foreach ($this->processMetadata as $taskId => $metadata) {
            if ($metadata->status === ProcessStatus::Running) {
                $exitCode = $this->processes[$taskId]->getExitCode() ?? -1;
                $this->processMetadata[$taskId] = new Process(
                    id: $metadata->id,
                    taskId: $metadata->taskId,
                    agent: $metadata->agent,
                    command: $metadata->command,
                    cwd: $metadata->cwd,
                    pid: $metadata->pid,
                    status: ProcessStatus::Killed,
                    exitCode: $exitCode,
                    startedAt: $metadata->startedAt,
                    completedAt: new DateTimeImmutable
                );
            }
        }

        // Clear process tracking
        $this->processes = [];
        $this->processMetadata = [];
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
        return ! empty($this->activeAgentProcesses);
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
     * Spawn a new process for a given task and agent (interface method).
     *
     * @param  string  $taskId  The ID of the task to process
     * @param  string  $agent  The agent name to use for processing
     * @param  string  $command  The command to execute
     * @param  string  $cwd  The current working directory for the process
     * @return Process The spawned process
     */
    public function spawn(string $taskId, string $agent, string $command, string $cwd): Process
    {
        // Generate unique process ID
        $processId = 'p-'.substr(bin2hex(random_bytes(3)), 0, 6);

        // Create output directory
        $outputDir = storage_path(".fuel/processes/{$taskId}");
        if (! File::exists($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $stdoutPath = "{$outputDir}/stdout.log";
        $stderrPath = "{$outputDir}/stderr.log";

        // Clear existing output files
        File::put($stdoutPath, '');
        File::put($stderrPath, '');

        // Parse command into array if it's a string
        $commandArray = is_string($command) ? explode(' ', $command) : [$command];

        // Create and start the process
        $symfonyProcess = new SymfonyProcess(
            $commandArray,
            $cwd,
            null,  // inherit environment
            null,  // no stdin
            null   // no timeout
        );

        $symfonyProcess->setTimeout(null);
        $symfonyProcess->setIdleTimeout(null);

        // Start process and capture output to files
        $symfonyProcess->start(function ($type, $buffer) use ($stdoutPath, $stderrPath) {
            if ($type === SymfonyProcess::ERR) {
                File::append($stderrPath, $buffer);
            } else {
                File::append($stdoutPath, $buffer);
            }
        });

        $pid = $symfonyProcess->getPid() ?? 0;

        // Create Process value object
        $process = new Process(
            id: $processId,
            taskId: $taskId,
            agent: $agent,
            command: $command,
            cwd: $cwd,
            pid: $pid,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable,
            completedAt: null
        );

        // Store process and metadata
        $this->processes[$taskId] = $symfonyProcess;
        $this->processMetadata[$taskId] = $process;

        return $process;
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

            // Create output directory
            $outputDir = storage_path(".fuel/processes/{$taskId}");
            if (! File::exists($outputDir)) {
                File::makeDirectory($outputDir, 0755, true);
            }

            $stdoutPath = "{$outputDir}/stdout.log";
            $stderrPath = "{$outputDir}/stderr.log";

            // Clear existing output files
            File::put($stdoutPath, '');
            File::put($stderrPath, '');

            // Create and start the process with env vars
            $env = array_merge($_ENV, $agentDef['env']);
            $symfonyProcess = new SymfonyProcess(
                $commandArray,
                $cwd,
                $env,
                null,  // no stdin
                null   // no timeout
            );

            $symfonyProcess->setTimeout(null);
            $symfonyProcess->setIdleTimeout(null);

            // Start process and capture output to files
            $symfonyProcess->start(function ($type, $buffer) use ($stdoutPath, $stderrPath) {
                if ($type === SymfonyProcess::ERR) {
                    File::append($stderrPath, $buffer);
                } else {
                    File::append($stdoutPath, $buffer);
                }
            });

            // Check if process started successfully
            if (! $symfonyProcess->isRunning()) {
                return SpawnResult::spawnFailed($taskId);
            }

            // Create AgentProcess instance
            $agentProcess = new AgentProcess(
                $symfonyProcess,
                $taskId,
                $agentName,
                time()
            );

            // Track the process
            $this->activeAgentProcesses[$taskId] = $agentProcess;
            $this->agentCounts[$agentName] = ($this->agentCounts[$agentName] ?? 0) + 1;

            // Also update legacy tracking for compatibility
            $this->processes[$taskId] = $symfonyProcess;
            $pid = $symfonyProcess->getPid() ?? 0;
            $this->processMetadata[$taskId] = new Process(
                id: 'p-'.substr(bin2hex(random_bytes(3)), 0, 6),
                taskId: $taskId,
                agent: $agentName,
                command: implode(' ', $commandArray),
                cwd: $cwd,
                pid: $pid,
                status: ProcessStatus::Running,
                exitCode: null,
                startedAt: new DateTimeImmutable,
                completedAt: null
            );

            return SpawnResult::success($agentProcess);

        } catch (\Exception $e) {
            return SpawnResult::configError($e->getMessage());
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

                // Also update legacy tracking
                unset($this->processes[$taskId]);
                if (isset($this->processMetadata[$taskId])) {
                    $metadata = $this->processMetadata[$taskId];
                    $this->processMetadata[$taskId] = new Process(
                        id: $metadata->id,
                        taskId: $taskId,
                        agent: $agentName,
                        command: $metadata->command,
                        cwd: $metadata->cwd,
                        pid: $metadata->pid,
                        status: $exitCode === 0 ? ProcessStatus::Completed : ProcessStatus::Failed,
                        exitCode: $exitCode,
                        startedAt: $metadata->startedAt,
                        completedAt: new DateTimeImmutable
                    );
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
            return file_exists("/proc/{$pid}");
        }

        // Fallback: use ps command
        $output = shell_exec("ps -p {$pid} -o pid=");

        return ! empty(trim($output ?? ''));
    }
}
