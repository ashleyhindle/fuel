<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Process\Process;
use App\Process\ProcessOutput;
use App\Process\ProcessResult;
use App\Process\ProcessType;

/**
 * Contract for process management.
 *
 * Defines the interface for spawning and managing agent processes,
 * monitoring their status, and handling their lifecycle.
 */
interface ProcessManagerInterface
{
    /**
     * Spawn a new process for a given task and agent.
     *
     * @param  string  $taskId  The ID of the task to process
     * @param  string  $agent  The agent name to use for processing
     * @param  string  $command  The command to execute
     * @param  string  $cwd  The current working directory for the process
     * @param  ProcessType  $processType  The type of process (task or review)
     * @return Process The spawned process
     */
    public function spawn(string $taskId, string $agent, string $command, string $cwd, ProcessType $processType = ProcessType::Task): Process;

    /**
     * Check if a process for the given task is currently running.
     *
     * @param  string  $taskId  The task ID to check
     * @return bool True if the process is running, false otherwise
     */
    public function isRunning(string $taskId): bool;

    /**
     * Kill the process associated with the given task.
     *
     * @param  string  $taskId  The task ID whose process should be killed
     */
    public function kill(string $taskId): void;

    /**
     * Get the output (stdout and stderr) for a given task's process.
     *
     * @param  string  $taskId  The task ID whose output to retrieve
     * @return ProcessOutput The process output
     */
    public function getOutput(string $taskId): ProcessOutput;

    /**
     * Get the count of currently running processes.
     *
     * @return int The number of running processes
     */
    public function getRunningCount(): int;

    /**
     * Get all currently running processes.
     *
     * @return array<Process> Array of running processes
     */
    public function getRunningProcesses(): array;

    /**
     * Wait for any process to complete, up to the given timeout.
     *
     * @param  int  $timeoutMs  Timeout in milliseconds
     * @return ProcessResult|null The result of the first completed process, or null if timeout
     */
    public function waitForAny(int $timeoutMs): ?ProcessResult;

    /**
     * Wait for all processes to complete, up to the given timeout.
     *
     * @param  int  $timeoutMs  Timeout in milliseconds
     * @return array<ProcessResult> Array of process results for all completed processes
     */
    public function waitForAll(int $timeoutMs): array;

    /**
     * Gracefully shutdown the process manager, terminating all running processes.
     */
    public function shutdown(): void;
}
