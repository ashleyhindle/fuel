<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use LaravelZero\Framework\Commands\Command;

/**
 * Backfill model and cost data for existing runs from stdout.log files.
 */
class BackfillRunsCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'runs:backfill
        {--dry-run : Show what would be updated without making changes}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Backfill model and cost data for existing runs from process output logs';

    public function handle(DatabaseService $databaseService, FuelContext $fuelContext): int
    {
        $this->configureCwd($fuelContext);
        $cwd = $this->option('cwd') ?: getcwd();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run mode - no changes will be made');
        }

        $processesDir = $cwd.'/.fuel/processes';
        if (! is_dir($processesDir)) {
            $this->error('No processes directory found at '.$processesDir);

            return self::FAILURE;
        }

        // Get all runs that are missing model or cost
        $runs = $databaseService->fetchAll(
            'SELECT r.id, r.short_id, r.model, r.cost_usd
             FROM runs r
             WHERE r.model IS NULL OR r.cost_usd IS NULL'
        );

        if ($runs === []) {
            $this->info('No runs need backfilling');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d runs to check', count($runs)));

        $updated = 0;
        $skipped = 0;

        foreach ($runs as $run) {
            $runShortId = $run['short_id'];
            if ($runShortId === null) {
                $this->line(sprintf('  <fg=gray>Run %d: No run short_id, skipping</>', $run['id']));
                $skipped++;

                continue;
            }

            // Look for stdout.log using run ID
            $stdoutPath = $processesDir.'/'.$runShortId.'/stdout.log';

            if (! file_exists($stdoutPath)) {
                $this->line(sprintf('  <fg=gray>Run %d (%s): No stdout.log found</>', $run['id'], $runShortId));
                $skipped++;

                continue;
            }

            // Parse the stdout.log
            $data = $this->parseStdoutLog($stdoutPath);

            if ($data['model'] === null && $data['cost_usd'] === null) {
                $this->line(sprintf('  <fg=gray>Run %d (%s): No model or cost found in output</>', $run['id'], $runShortId));
                $skipped++;

                continue;
            }

            // Build update
            $updates = [];
            $params = [];

            if ($data['model'] !== null && $run['model'] === null) {
                $updates[] = 'model = ?';
                $params[] = $data['model'];
            }

            if ($data['cost_usd'] !== null && $run['cost_usd'] === null) {
                $updates[] = 'cost_usd = ?';
                $params[] = $data['cost_usd'];
            }

            if ($updates === []) {
                $skipped++;

                continue;
            }

            $params[] = $run['id'];

            $modelInfo = $data['model'] ? sprintf('model=%s', $data['model']) : '';
            $costInfo = $data['cost_usd'] ? sprintf('cost=$%.4f', $data['cost_usd']) : '';
            $info = trim($modelInfo.'  '.$costInfo);

            if ($dryRun) {
                $this->line(sprintf('  <fg=yellow>Would update</> Run %d (%s): %s', $run['id'], $runShortId, $info));
            } else {
                $databaseService->query(
                    'UPDATE runs SET '.implode(', ', $updates).' WHERE id = ?',
                    $params
                );
                $this->line(sprintf('  <fg=green>Updated</> Run %d (%s): %s', $run['id'], $runShortId, $info));
            }

            $updated++;
        }

        $this->newLine();
        if ($dryRun) {
            $this->info(sprintf('Would update %d runs, skipped %d', $updated, $skipped));
        } else {
            $this->info(sprintf('Updated %d runs, skipped %d', $updated, $skipped));
        }

        return self::SUCCESS;
    }

    /**
     * Parse stdout.log to extract model and cost.
     *
     * @return array{model: ?string, cost_usd: ?float}
     */
    private function parseStdoutLog(string $path): array
    {
        $result = ['model' => null, 'cost_usd' => null];

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $result;
        }

        // Read first line for init message with model
        $firstLine = fgets($handle);
        if ($firstLine !== false) {
            $data = json_decode(trim($firstLine), true);
            if (is_array($data) && ($data['type'] ?? '') === 'system' && ($data['subtype'] ?? '') === 'init') {
                $result['model'] = $data['model'] ?? null;
            }
        }

        // Read last line for result message with cost
        // Seek to end and read backwards to find last line
        fseek($handle, -1, SEEK_END);
        $lastLine = '';
        $pos = ftell($handle);

        // Read backwards to find start of last line
        while ($pos > 0) {
            $char = fgetc($handle);
            if ($char === "\n" && $lastLine !== '') {
                break;
            }

            if ($char !== "\n") {
                $lastLine = $char.$lastLine;
            }

            fseek($handle, --$pos);
        }

        // If we hit the start of file, read from there
        if ($pos === 0) {
            fseek($handle, 0);
            $lastLine = fgets($handle) ?: $lastLine;
            $lastLine = trim($lastLine);
        }

        fclose($handle);

        if ($lastLine !== '') {
            $data = json_decode($lastLine, true);
            if (is_array($data) && ($data['type'] ?? '') === 'result') {
                $result['cost_usd'] = isset($data['total_cost_usd']) ? (float) $data['total_cost_usd'] : null;

                // If we didn't get model from init, try to get from result's modelUsage
                if ($result['model'] === null && isset($data['modelUsage']) && is_array($data['modelUsage'])) {
                    // Get the primary model (first one or the one with most output tokens)
                    $primaryModel = null;
                    $maxOutput = 0;
                    foreach ($data['modelUsage'] as $model => $usage) {
                        $outputTokens = $usage['outputTokens'] ?? 0;
                        if ($outputTokens > $maxOutput) {
                            $maxOutput = $outputTokens;
                            $primaryModel = $model;
                        }
                    }

                    $result['model'] = $primaryModel;
                }
            }
        }

        // If no cost from result line, try summing step_finish costs (opencode format)
        if ($result['cost_usd'] === null) {
            $stepCost = $this->sumStepCosts($path);
            if ($stepCost > 0) {
                $result['cost_usd'] = $stepCost;
            }
        }

        return $result;
    }

    /**
     * Sum cost values from step_finish events (opencode format).
     */
    private function sumStepCosts(string $path): float
    {
        $total = 0.0;

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $total;
        }

        while (($line = fgets($handle)) !== false) {
            $data = json_decode(trim($line), true);
            if (! is_array($data)) {
                continue;
            }

            if (($data['type'] ?? '') === 'step_finish') {
                $partData = $data['part'] ?? $data;
                if (isset($partData['cost']) && is_numeric($partData['cost'])) {
                    $total += (float) $partData['cost'];
                }
            }
        }

        fclose($handle);

        return $total;
    }
}
