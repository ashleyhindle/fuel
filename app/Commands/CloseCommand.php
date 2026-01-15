<?php

declare(strict_types=1);

namespace App\Commands;

use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Commands\Command;

class CloseCommand extends Command
{
    protected $signature = 'close
        {ids* : The task ID(s) (supports partial matching, accepts multiple IDs)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--commit= : Git commit hash to associate with this completion}';

    protected $description = 'Mark one or more tasks as done with reason "closed"';

    public function handle(): int
    {
        $ids = $this->argument('ids');
        $commit = $this->option('commit');
        $json = $this->option('json');
        $cwd = $this->option('cwd');

        $params = [
            'ids' => $ids,
            '--reason' => 'closed',
        ];

        if ($commit !== null) {
            $params['--commit'] = $commit;
        }

        if ($json) {
            $params['--json'] = true;
        }

        if ($cwd !== null) {
            $params['--cwd'] = $cwd;
        }

        return Artisan::call('done', $params);
    }
}
