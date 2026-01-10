<?php

declare(strict_types=1);

namespace App\Process;

use Symfony\Component\Process\Process;

/**
 * Value object representing a running agent process with its metadata.
 *
 * Immutable except for session_id and output_buffer which are captured
 * incrementally during process execution.
 */
final class AgentProcess
{
    /** Maximum size for output buffer (16KB) to prevent memory leaks */
    private const MAX_OUTPUT_BUFFER_SIZE = 16384;

    private ?string $sessionId = null;

    private string $outputBuffer = '';

    private bool $sessionIdCaptured = false;

    public function __construct(
        private readonly Process $process,
        private readonly string $taskId,
        private readonly string $agentName,
        private readonly int $startTime,
    ) {}

    public function getProcess(): Process
    {
        return $this->process;
    }

    public function getPid(): ?int
    {
        return $this->process->getPid();
    }

    public function getTaskId(): string
    {
        return $this->taskId;
    }

    public function getAgentName(): string
    {
        return $this->agentName;
    }

    public function getStartTime(): int
    {
        return $this->startTime;
    }

    public function getDuration(): int
    {
        return time() - $this->startTime;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
        $this->sessionIdCaptured = true;
        $this->outputBuffer = ''; // Clear buffer after capture to free memory
    }

    public function isSessionIdCaptured(): bool
    {
        return $this->sessionIdCaptured;
    }

    public function getOutputBuffer(): string
    {
        return $this->outputBuffer;
    }

    public function appendToOutputBuffer(string $content): void
    {
        $this->outputBuffer .= $content;

        // Cap buffer size to prevent memory leaks from long-running processes
        if (strlen($this->outputBuffer) > self::MAX_OUTPUT_BUFFER_SIZE) {
            $this->outputBuffer = substr($this->outputBuffer, -self::MAX_OUTPUT_BUFFER_SIZE);
        }
    }

    public function clearOutputBuffer(): void
    {
        $this->outputBuffer = '';
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function getExitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    /**
     * Get full output including buffered output, stdout, and stderr.
     */
    public function getOutput(): string
    {
        return $this->outputBuffer.$this->process->getOutput().$this->process->getErrorOutput();
    }

    /**
     * Get incremental output from the process.
     */
    public function getIncrementalOutput(): string
    {
        return $this->process->getIncrementalOutput();
    }

    /**
     * Get metadata array for display purposes.
     *
     * @return array{task_id: string, agent_name: string, start_time: int, session_id: ?string, duration: int}
     */
    public function getMetadata(): array
    {
        return [
            'task_id' => $this->taskId,
            'agent_name' => $this->agentName,
            'start_time' => $this->startTime,
            'session_id' => $this->sessionId,
            'duration' => $this->getDuration(),
        ];
    }
}
