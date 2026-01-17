<?php

declare(strict_types=1);

namespace App\Commands;

use App\Agents\Tasks\MergeEpicAgentTask;
use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\MirrorStatus;
use App\Services\ConfigService;
use App\Services\EpicService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicReviewedCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:reviewed
        {id : The epic ID (supports partial matching)}
        {--json : Output as JSON}
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Mark an epic as reviewed';

    public function handle(
        EpicService $epicService,
        ConfigService $configService
    ): int {
        try {
            $epic = $epicService->markAsReviewed($this->argument('id'));

            // Check if we need to create a merge task
            if ($configService->getEpicMirrorsEnabled() && $epic->hasMirror()) {
                // Update mirror status to Merging
                $epicService->updateMirrorStatus($epic, MirrorStatus::Merging);

                // Create merge task - it goes into the queue with status=pending, agent=merge
                // The daemon will pick it up and spawn it with proper lifecycle hooks
                $agentTask = MergeEpicAgentTask::fromEpic($epic);
                $mergeTaskId = $agentTask->getTaskId();

                $this->info(sprintf('Merge task %s created for epic %s', $mergeTaskId, $epic->short_id));
            }

            if ($this->option('json')) {
                $this->outputJson($epic->toArray());

                return self::SUCCESS;
            }

            $this->info(sprintf('Epic %s marked as reviewed', $epic->short_id));

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->outputError($e->getMessage());
        } catch (\Exception $e) {
            return $this->outputError('Failed to mark epic as reviewed: '.$e->getMessage());
        }
    }
}
