<?php

declare(strict_types=1);

namespace App\Commands;

use App\Agents\Tasks\MergeEpicAgentTask;
use App\Commands\Concerns\HandlesJsonOutput;
use App\Enums\MirrorStatus;
use App\Services\ConfigService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\ProcessManager;
use App\Services\RunService;
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
        ConfigService $configService,
        ProcessManager $processManager,
        RunService $runService,
        FuelContext $fuelContext
    ): int {
        try {
            $epic = $epicService->markAsReviewed($this->argument('id'));

            // Check if we need to create a merge task
            if ($configService->getEpicMirrorsEnabled() && $epic->hasMirror()) {
                // Update mirror status to Merging
                $epicService->updateMirrorStatus($epic, MirrorStatus::Merging);

                // Create and spawn merge task
                $agentTask = MergeEpicAgentTask::fromEpic($epic);
                $mergeTaskId = $agentTask->getTaskId();
                $agentName = $agentTask->getAgentName($configService);

                if ($agentName !== null) {
                    $model = null;
                    try {
                        $agentDef = $configService->getAgentDefinition($agentName);
                        $model = $agentDef['model'] ?? null;
                    } catch (\RuntimeException) {
                        $model = null;
                    }

                    $runId = $runService->createRun($mergeTaskId, [
                        'agent' => $agentName,
                        'model' => $model,
                        'started_at' => date('c'),
                    ]);

                    // Spawn the merge task
                    $cwd = $fuelContext->getProjectPath();
                    $result = $processManager->spawnAgentTask($agentTask, $cwd, $runId);

                    if ($result->success && $result->process) {
                        $pid = $result->process->getPid();
                        if ($pid !== null) {
                            $runService->updateRun($runId, ['pid' => $pid]);
                        }
                    }
                }
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
