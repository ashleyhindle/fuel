<?php

declare(strict_types=1);

namespace App\Process;

use DateTimeImmutable;

readonly class Process
{
    public function __construct(
        public string $id,
        public string $taskId,
        public string $agent,
        public string $command,
        public string $cwd,
        public int $pid,
        public ProcessStatus $status,
        public ?int $exitCode = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $completedAt = null,
    ) {}

    public function isRunning(): bool
    {
        return $this->status === ProcessStatus::Running;
    }

    public function getDurationSeconds(): ?int
    {
        if (!$this->startedAt instanceof \DateTimeImmutable) {
            return null;
        }

        $endTime = $this->completedAt ?? new DateTimeImmutable;

        return $endTime->getTimestamp() - $this->startedAt->getTimestamp();
    }
}
