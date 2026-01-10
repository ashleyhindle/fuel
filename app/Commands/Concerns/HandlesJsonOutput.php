<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Services\ConfigService;
use App\Services\TaskService;

/**
 * Provides common JSON output and --cwd handling for Fuel commands.
 *
 * Commands using this trait should have these options in their signature:
 *   {--cwd= : Working directory (defaults to current directory)}
 *   {--json : Output as JSON}
 */
trait HandlesJsonOutput
{
    /**
     * Configure the TaskService with --cwd option if provided.
     */
    protected function configureCwd(TaskService $taskService, ?ConfigService $configService = null): void
    {
        if ($cwd = $this->option('cwd')) {
            $taskService->setStoragePath($cwd.'/.fuel/tasks.jsonl');

            if ($configService instanceof ConfigService) {
                $configService->setConfigPath($cwd.'/.fuel/config.yaml');
            }
        }
    }

    /**
     * Output an error message (JSON or text based on --json option).
     *
     * @return int Returns Command::FAILURE for convenient return
     */
    protected function outputError(string $message): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(['error' => $message], JSON_PRETTY_PRINT));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    /**
     * Output data as JSON with proper formatting.
     */
    protected function outputJson(mixed $data): void
    {
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
