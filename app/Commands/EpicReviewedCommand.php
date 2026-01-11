<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicReviewedCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:reviewed
        {id : The epic ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Mark an epic as reviewed';

    public function handle(FuelContext $context, DatabaseService $dbService, EpicService $epicService): int
    {
        // Configure context with --cwd if provided
        $this->configureCwd($context, $dbService);

        try {
            $epic = $epicService->markAsReviewed($this->argument('id'));

            if ($this->option('json')) {
                $this->outputJson($epic->toArray());

                return self::SUCCESS;
            }

            $this->info(sprintf('Epic %s marked as reviewed', $epic->id));

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        } catch (\Exception $e) {
            return $this->outputError('Failed to mark epic as reviewed: '.$e->getMessage());
        }
    }
}
