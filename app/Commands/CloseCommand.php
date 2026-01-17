<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class CloseCommand extends Command
{
    protected $signature = 'close
        {ids* : The task ID(s) (supports partial matching, accepts multiple IDs)}
        {--json : Output as JSON}
        {--commit= : Git commit hash to associate with this completion}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Mark one or more tasks as done with reason "closed"';

    public function handle(): int
    {
        $params = [
            'ids' => $this->argument('ids'),
            '--reason' => 'closed',
        ];

        if ($this->option('commit') !== null) {
            $params['--commit'] = $this->option('commit');
        }

        if ($this->option('json')) {
            $params['--json'] = true;
        }

        if ($this->option('cwd') !== null) {
            $params['--cwd'] = $this->option('cwd');
        }

        return $this->call('done', $params);
    }
}
