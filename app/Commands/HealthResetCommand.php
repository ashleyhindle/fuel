<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\AgentHealthTracker;
use LaravelZero\Framework\Commands\Command;

class HealthResetCommand extends Command
{
    protected $signature = 'health:reset
        {agent? : Agent name to reset, or all if omitted}
        {--all : Reset all agents}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Reset agent health status';

    public function handle(AgentHealthTracker $healthTracker): int
    {
        $agent = $this->argument('agent');
        $resetAll = $this->option('all');

        // If specific agent provided
        if ($agent !== null) {
            $healthTracker->clearHealth($agent);
            $this->info('Health status reset for agent: '.$agent);

            return self::SUCCESS;
        }

        // If --all flag provided
        if ($resetAll) {
            $this->resetAllAgents($healthTracker);

            return self::SUCCESS;
        }

        // Neither agent nor --all provided, prompt for confirmation
        if (! $this->confirm('Reset health status for all agents?', false)) {
            $this->info('Reset cancelled.');

            return self::SUCCESS;
        }

        $this->resetAllAgents($healthTracker);

        return self::SUCCESS;
    }

    /**
     * Reset health status for all tracked agents.
     */
    private function resetAllAgents(AgentHealthTracker $healthTracker): void
    {
        $allHealth = $healthTracker->getAllHealthStatus();

        if ($allHealth === []) {
            $this->info('No agent health data to reset.');

            return;
        }

        foreach ($allHealth as $health) {
            $healthTracker->clearHealth($health->agent);
        }

        $count = count($allHealth);
        $this->info(sprintf('Health status reset for %d agent(s).', $count));
    }
}
