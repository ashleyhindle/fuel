<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\FuelContext;
use LaravelZero\Framework\Commands\Command;

class DbCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'db
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Open SQLite database in TablePlus';

    public function handle(FuelContext $context): int
    {
        $this->configureCwd($context);

        $dbPath = $context->getDatabasePath();

        if (! file_exists($dbPath)) {
            return $this->outputError(sprintf('Database not found at: %s', $dbPath));
        }

        // Check if TablePlus is installed (only warn in non-JSON mode)
        $tablePlusPath = '/Applications/TablePlus.app';
        if (! file_exists($tablePlusPath) && ! file_exists('/usr/local/bin/tableplus')) {
            if (! $this->option('json')) {
                $this->warn('TablePlus not found. Attempting to open anyway...');
            }
        }

        // Use macOS 'open' command to open TablePlus with the database file
        $command = sprintf('open -a TablePlus %s', escapeshellarg($dbPath));
        $result = shell_exec($command.' 2>&1');

        if ($result !== null && $result !== '') {
            // Check if there was an error
            if (str_contains($result, 'Unable to find application')) {
                return $this->outputError('TablePlus not found. Please install TablePlus from https://tableplus.com/');
            }

            // Other errors
            return $this->outputError(sprintf('Failed to open database: %s', trim($result)));
        }

        if ($this->option('json')) {
            $this->outputJson([
                'success' => true,
                'message' => 'Opening database in TablePlus',
                'path' => $dbPath,
            ]);
        } else {
            $this->info(sprintf('Opening database in TablePlus: %s', $dbPath));
        }

        return self::SUCCESS;
    }
}
