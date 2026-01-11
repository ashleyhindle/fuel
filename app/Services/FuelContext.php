<?php

declare(strict_types=1);

namespace App\Services;

class FuelContext
{
    public string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? getcwd().'/.fuel';
    }

    public function getDatabasePath(): string
    {
        return $this->basePath.'/agent.db';
    }

    public function getBacklogPath(): string
    {
        return $this->basePath.'/backlog.jsonl';
    }

    public function getRunsPath(): string
    {
        return $this->basePath.'/runs';
    }

    public function getProcessesPath(): string
    {
        return $this->basePath.'/processes';
    }

    public function getConfigPath(): string
    {
        return $this->basePath.'/config.yaml';
    }
}
