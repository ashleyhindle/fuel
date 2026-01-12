<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Epic;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicApproveCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:approve
        {ids* : The epic ID(s) (supports partial matching, accepts multiple IDs)}
        {--cwd= : Working directory (defaults to current directory)}
        {--by= : Who approved it (defaults to "human")}
        {--json : Output as JSON}';

    protected $description = 'Approve one or more epics (mark as approved)';

    public function handle(FuelContext $context, DatabaseService $dbService, EpicService $epicService): int
    {
        // Configure context with --cwd if provided
        $this->configureCwd($context, $dbService);

        $ids = $this->argument('ids');
        $approvedBy = $this->option('by');
        $epics = [];
        $errors = [];

        foreach ($ids as $id) {
            try {
                $epic = $epicService->approveEpic($id, $approvedBy);
                $epics[] = $epic;
            } catch (RuntimeException $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        if ($epics === [] && $errors !== []) {
            // All failed
            return $this->outputError($errors[0]['error']);
        }

        if ($this->option('json')) {
            if (count($epics) === 1) {
                // Single epic - return object for backward compatibility
                $this->outputJson($epics[0]->toArray());
            } else {
                // Multiple epics - return array
                $this->outputJson(array_map(fn (Epic $epic): array => $epic->toArray(), $epics));
            }
        } else {
            foreach ($epics as $epic) {
                $this->info(sprintf('Epic %s approved', $epic->short_id));
                if (isset($epic->approved_by)) {
                    $this->line(sprintf('  Approved by: %s', $epic->approved_by));
                }

                if (isset($epic->approved_at)) {
                    $this->line(sprintf('  Approved at: %s', $epic->approved_at));
                }
            }
        }

        // If there were any errors, return failure even if some succeeded
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->outputError(sprintf("Epic '%s': %s", $error['id'], $error['error']));
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
