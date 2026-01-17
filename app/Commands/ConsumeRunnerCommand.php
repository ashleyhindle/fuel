<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ConsumeRunner;
use LaravelZero\Framework\Commands\Command;

class ConsumeRunnerCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'consume:runner
        {--review : Enable automatic review of completed work}
        {--port= : Port number to bind to (overrides config)}
        {--cwd= : Working directory (defaults to current directory)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Headless consume runner for background execution';

    /**
     * Hidden from command list (this is a background service).
     *
     * @var bool
     */
    protected $hidden = true;

    /**
     * Execute the console command.
     */
    public function handle(ConsumeRunner $runner): int
    {
        // Start the runner (will run headless until stopped)
        $taskReviewEnabled = (bool) $this->option('review');
        $port = $this->option('port') ? (int) $this->option('port') : null;
        $runner->start($taskReviewEnabled, $port);

        return self::SUCCESS;
    }
}
