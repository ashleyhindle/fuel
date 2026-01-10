<?php

namespace App\Process;

readonly class ProcessOutput
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public string $stdoutPath,
        public string $stderrPath,
    ) {}

    public function getCombined(): string
    {
        return $this->stdout.$this->stderr;
    }

    public function hasErrors(): bool
    {
        return trim($this->stderr) !== '';
    }
}
