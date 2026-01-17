<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Centralized service for spawning background processes.
 *
 * This service provides a mockable interface for fire-and-forget background process spawning,
 * primarily used for tasks like mirror creation that need to run independently.
 */
class ProcessSpawner
{
    /**
     * Spawn a background process using nohup and shell backgrounding.
     *
     * The process runs detached from the current shell and redirects all output to /dev/null.
     * This is a fire-and-forget operation - the process runs independently and is not tracked.
     *
     * @param  string  $command  The fuel command to execute (e.g., 'mirror:create')
     * @param  array  $args  Command arguments (will be escaped for shell)
     */
    public function spawnBackground(string $command, array $args = []): void
    {
        $fuelPath = base_path('fuel');
        $escapedArgs = array_map('escapeshellarg', array_merge([$command], $args));
        $allArgs = implode(' ', $escapedArgs);

        $fullCommand = sprintf(
            'nohup %s %s %s > /dev/null 2>&1 &',
            PHP_BINARY,
            $fuelPath,
            $allArgs
        );

        exec($fullCommand);
    }
}
