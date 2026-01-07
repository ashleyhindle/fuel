<?php

declare(strict_types=1);

namespace Laravel\Boost\Console\Fuel;

use Illuminate\Console\Command;
use Laravel\Boost\Fuel\TaskService;

class PruneCommand extends Command
{
    protected $signature = 'fuel:prune
        {--dry-run : Show what would be pruned without actually pruning}
        {--json : Output JSON instead of human-readable}';

    protected $description = 'Prune old closed fuel tasks to keep the file size manageable';

    public function handle(TaskService $service): int
    {
        $stats = $service->getPruneStats();

        if ($this->option('dry-run')) {
            return $this->dryRun($stats);
        }

        $pruned = $service->prune();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'pruned' => $pruned,
                'remaining' => $stats['total'] - $pruned,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($pruned === 0) {
            $this->info("No tasks to prune. Total: {$stats['total']}, Max: {$stats['max']}");
        } else {
            $remaining = $stats['total'] - $pruned;
            $this->info("Pruned {$pruned} old closed task(s). Remaining: {$remaining}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{total: int, closed: int, max: int, should_prune: bool, prunable: int}  $stats
     */
    private function dryRun(array $stats): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($stats, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Prune statistics (dry run):');
        $this->line("  Total tasks: {$stats['total']}");
        $this->line("  Closed tasks: {$stats['closed']}");
        $this->line("  Max tasks: {$stats['max']}");

        if ($stats['should_prune']) {
            $this->warn("  Would prune: {$stats['prunable']} oldest closed task(s)");
        } else {
            $this->info('  No pruning needed.');
        }

        return self::SUCCESS;
    }
}
