<?php

namespace App\Process;

readonly class ProcessResult
{
    public function __construct(
        public Process $process,
        public ProcessOutput $output,
        public bool $success,
    ) {}

    public function getTaskId(): string
    {
        return $this->process->taskId;
    }

    public function getExitCode(): int
    {
        return $this->process->exitCode ?? 1;
    }

    public function wasSuccessful(): bool
    {
        return $this->success;
    }

    public function getDurationSeconds(): int
    {
        return $this->process->getDurationSeconds() ?? 0;
    }
}
