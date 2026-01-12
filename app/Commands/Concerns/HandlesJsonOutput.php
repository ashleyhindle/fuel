<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

/**
 * Provides common JSON output for Fuel commands.
 *
 * Commands using this trait should have this option in their signature:
 *   {--json : Output as JSON}
 */
trait HandlesJsonOutput
{
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
