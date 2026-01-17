<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Process\AgentHealth;
use App\Services\AgentHealthTracker;
use DateTimeImmutable;
use LaravelZero\Framework\Commands\Command;

class HealthCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'health
        {--json : Output as JSON}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Display agent health status';

    public function handle(AgentHealthTracker $healthTracker): int
    {
        $allHealth = $healthTracker->getAllHealthStatus();

        if ($this->option('json')) {
            $this->outputJson($this->formatForJson($allHealth));
        } else {
            $this->displayTable($allHealth);
        }

        return self::SUCCESS;
    }

    /**
     * Display health status in table format.
     *
     * @param  array<AgentHealth>  $allHealth
     */
    private function displayTable(array $allHealth): void
    {
        if ($allHealth === []) {
            $this->info('No agent health data available.');

            return;
        }

        $rows = [];
        foreach ($allHealth as $health) {
            $rows[] = [
                $health->agent,
                $this->getStatusLabel($health),
                (string) $health->consecutiveFailures,
                $this->formatBackoffRemaining($health),
                $this->formatSuccessRate($health),
                $this->formatDateTime($health->lastSuccessAt),
                $this->formatDateTime($health->lastFailureAt),
            ];
        }

        $this->info('Agent Health Status:');
        $this->newLine();
        $this->table(
            [
                'Agent',
                'Status',
                'Consecutive Failures',
                'Backoff Remaining',
                'Success Rate',
                'Last Success',
                'Last Failure',
            ],
            $rows
        );
    }

    /**
     * Get status label: healthy, backoff, or dead.
     */
    private function getStatusLabel(AgentHealth $health): string
    {
        if ($health->consecutiveFailures === 0) {
            return 'healthy';
        }

        if ($health->consecutiveFailures >= 5) {
            return 'dead';
        }

        if ($health->getBackoffSeconds() > 0) {
            return 'backoff';
        }

        // Has failures but backoff expired
        return 'healthy';
    }

    /**
     * Format backoff remaining time.
     */
    private function formatBackoffRemaining(AgentHealth $health): string
    {
        $seconds = $health->getBackoffSeconds();
        if ($seconds === 0) {
            return '-';
        }

        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            $minutes = (int) ($seconds / 60);
            $remainingSeconds = $seconds % 60;

            return $remainingSeconds > 0 ? $minutes.'m '.$remainingSeconds.'s' : $minutes.'m';
        }

        $hours = (int) ($seconds / 3600);
        $remainingMinutes = (int) (($seconds % 3600) / 60);

        return $remainingMinutes > 0 ? $hours.'h '.$remainingMinutes.'m' : $hours.'h';
    }

    /**
     * Format success rate as percentage.
     */
    private function formatSuccessRate(AgentHealth $health): string
    {
        $rate = $health->getSuccessRate();
        if ($rate === null) {
            return '-';
        }

        return number_format($rate, 1).'%';
    }

    /**
     * Format DateTimeImmutable or return '-'.
     */
    private function formatDateTime(?DateTimeImmutable $dateTime): string
    {
        if (! $dateTime instanceof \DateTimeImmutable) {
            return '-';
        }

        return $dateTime->format('Y-m-d H:i:s');
    }

    /**
     * Format health data for JSON output.
     *
     * @param  array<AgentHealth>  $allHealth
     * @return array<int, array<string, mixed>>
     */
    private function formatForJson(array $allHealth): array
    {
        return array_map(fn (AgentHealth $health): array => [
            'agent' => $health->agent,
            'status' => $this->getStatusLabel($health),
            'consecutive_failures' => $health->consecutiveFailures,
            'backoff_remaining_seconds' => $health->getBackoffSeconds(),
            'success_rate' => $health->getSuccessRate(),
            'last_success_at' => $health->lastSuccessAt?->format('c'),
            'last_failure_at' => $health->lastFailureAt?->format('c'),
            'total_runs' => $health->totalRuns,
            'total_successes' => $health->totalSuccesses,
        ], $allHealth);
    }
}
