<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use Mockery\MockInterface;
use App\Services\ConfigService;
use App\Services\FuelContext;
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
     * Configure services with --cwd option if provided.
     *
     * Accepts either FuelContext (new pattern) or TaskService (legacy pattern).
     * The new pattern should be preferred for all new code.
     */
    protected function configureCwd(FuelContext|TaskService $contextOrService, ?ConfigService $configService = null): void
    {
        if ($cwd = $this->option('cwd')) {
            if ($contextOrService instanceof FuelContext) {
                $contextOrService->basePath = $cwd.'/.fuel';
            }

            // Legacy TaskService pattern - TaskService now uses SQLite via DI, no direct path setting needed
            // The database service will be reconfigured by commands that need it
            if (
                $contextOrService instanceof TaskService
                && (! interface_exists(MockInterface::class) || ! $contextOrService instanceof MockInterface)
                && method_exists($contextOrService, 'setDatabasePath')
            ) {
                $contextOrService->setDatabasePath($cwd.'/.fuel/agent.db');
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
