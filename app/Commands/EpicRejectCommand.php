<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\EpicService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicRejectCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:reject
        {id : The epic ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--reason= : Reason for rejection}
        {--json : Output as JSON}';

    protected $description = 'Reject an epic and request changes (moves tasks back to open)';

    public function handle(EpicService $epicService): int
    {
        try {
            $reason = $this->option('reason');
            $epic = $epicService->rejectEpic($this->argument('id'), $reason);

            if ($this->option('json')) {
                $this->outputJson($epic->toArray());

                return self::SUCCESS;
            }

            $this->info(sprintf('Epic %s rejected - changes requested', $epic->short_id));
            if ($reason !== null) {
                $this->line(sprintf('  Reason: %s', $reason));
            }

            $this->line('  Tasks have been reopened for changes');

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        } catch (\Exception $e) {
            return $this->outputError('Failed to reject epic: '.$e->getMessage());
        }
    }
}
