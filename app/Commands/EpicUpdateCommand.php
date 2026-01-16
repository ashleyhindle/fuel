<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Epic;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicUpdateCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:update
        {id : The epic ID (supports partial matching)}
        {--cwd= : Working directory (defaults to current directory)}
        {--json : Output as JSON}
        {--title= : Update epic title}
        {--description= : Update epic description}
        {--selfguided : Enable self-guided mode}
        {--no-selfguided : Disable self-guided mode}';

    protected $description = 'Update epic fields';

    public function handle(EpicService $epicService, TaskService $taskService, FuelContext $context): int
    {
        $updateData = [];

        if ($title = $this->option('title')) {
            $updateData['title'] = $title;
        }

        // Handle description - can be empty string to clear it
        if ($this->option('description') !== null) {
            $updateData['description'] = $this->option('description') ?: null;
        }

        // Handle self_guided flag explicitly
        if ($this->option('selfguided') && $this->option('no-selfguided')) {
            return $this->outputError('Cannot use both --selfguided and --no-selfguided flags.');
        }

        if ($this->option('selfguided')) {
            $updateData['self_guided'] = true;
        } elseif ($this->option('no-selfguided')) {
            $updateData['self_guided'] = false;
        }

        if (empty($updateData)) {
            return $this->outputError('No update fields provided. Use --title, --description, --selfguided, or --no-selfguided.');
        }

        try {
            // Get current state before update to detect transitions
            $existingEpic = $epicService->getEpic($this->argument('id'));
            $wasAlreadySelfGuided = $existingEpic?->self_guided ?? false;

            $epic = $epicService->updateEpic($this->argument('id'), $updateData);

            // Handle transition to self-guided mode
            $selfGuidedTaskId = null;
            $planPath = null;
            if ($this->option('selfguided') && ! $wasAlreadySelfGuided) {
                // Check if selfguided task already exists
                $existingTasks = $epicService->getTasksForEpic($epic->short_id);
                $hasSelfguidedTask = false;
                foreach ($existingTasks as $task) {
                    if ($task->agent === 'selfguided') {
                        $hasSelfguidedTask = true;
                        $selfGuidedTaskId = $task->short_id;
                        break;
                    }
                }

                // Create selfguided task if not exists
                if (! $hasSelfguidedTask) {
                    $task = $taskService->create([
                        'title' => 'Implement: '.$epic->title,
                        'description' => 'Self-guided implementation. See epic plan for acceptance criteria.',
                        'epic_id' => $epic->short_id,
                        'agent' => 'selfguided',
                        'complexity' => 'complex',
                        'type' => 'feature',
                    ]);
                    $selfGuidedTaskId = $task->short_id;
                }

                // Update plan file with selfguided sections
                $planPath = $this->ensureSelfguidedPlanSections($context, $epic);
            }

            if ($this->option('json')) {
                $this->outputJson($epic->toArray());
            } else {
                $this->info('Updated epic: '.$epic->short_id);
                $this->line('  Title: '.$epic->title);
                if ($epic->description) {
                    $this->line('  Description: '.$epic->description);
                }

                if (isset($updateData['self_guided'])) {
                    $status = $epic->self_guided ? 'enabled' : 'disabled';
                    $this->line('  Self-guided: '.$status);
                }

                if ($selfGuidedTaskId !== null) {
                    $this->line('  Created self-guided task: '.$selfGuidedTaskId);
                }

                if ($planPath !== null) {
                    $this->line('  Plan: '.$planPath);
                }
            }

            return self::SUCCESS;
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }
    }

    /**
     * Ensure the plan file has the required sections for self-guided mode.
     * If sections are missing, add them. Returns the plan path.
     */
    private function ensureSelfguidedPlanSections(FuelContext $context, Epic $epic): string
    {
        $plansDir = $context->getPlansPath();
        $filename = $epic->getPlanFilename();
        $planPath = $plansDir.'/'.$filename;

        // Check if plan file exists
        if (! file_exists($planPath)) {
            // Create new plan file with selfguided template
            if (! is_dir($plansDir)) {
                mkdir($plansDir, 0755, true);
            }

            // Generate new filename and store it
            $filename = Epic::generatePlanFilename($epic->title, $epic->short_id);
            $planPath = $plansDir.'/'.$filename;
            $epic->plan_filename = $filename;
            $epic->save();

            $title = $epic->title;
            $epicId = $epic->short_id;
            $content = <<<MARKDOWN
# Epic: {$title} ({$epicId})

## Plan

<!-- Add implementation plan here -->

## Acceptance Criteria

- [ ] Criterion 1
- [ ] Criterion 2
- [ ] Criterion 3

## Progress Log

<!-- Self-guided task appends progress entries here -->

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->
MARKDOWN;
            file_put_contents($planPath, $content);

            return '.fuel/plans/'.$filename;
        }

        // Read existing plan
        $content = file_get_contents($planPath);

        // Add missing sections
        $modified = false;

        // Check for Acceptance Criteria section
        if (stripos($content, '## Acceptance Criteria') === false) {
            // Find Implementation Notes section to insert before it
            $insertPoint = stripos($content, '## Implementation Notes');
            if ($insertPoint !== false) {
                $acceptanceCriteria = <<<'MARKDOWN'

## Acceptance Criteria

- [ ] Criterion 1
- [ ] Criterion 2
- [ ] Criterion 3

## Progress Log

<!-- Self-guided task appends progress entries here -->

MARKDOWN;
                $content = substr($content, 0, $insertPoint).$acceptanceCriteria.substr($content, $insertPoint);
                $modified = true;
            } else {
                // Append at end
                $content .= <<<'MARKDOWN'


## Acceptance Criteria

- [ ] Criterion 1
- [ ] Criterion 2
- [ ] Criterion 3

## Progress Log

<!-- Self-guided task appends progress entries here -->
MARKDOWN;
                $modified = true;
            }
        } elseif (stripos($content, '## Progress Log') === false) {
            // Has Acceptance Criteria but missing Progress Log
            $insertPoint = stripos($content, '## Implementation Notes');
            if ($insertPoint !== false) {
                $progressLog = <<<'MARKDOWN'

## Progress Log

<!-- Self-guided task appends progress entries here -->

MARKDOWN;
                $content = substr($content, 0, $insertPoint).$progressLog.substr($content, $insertPoint);
                $modified = true;
            } else {
                $content .= <<<'MARKDOWN'


## Progress Log

<!-- Self-guided task appends progress entries here -->
MARKDOWN;
                $modified = true;
            }
        }

        if ($modified) {
            file_put_contents($planPath, $content);
        }

        return '.fuel/plans/'.$filename;
    }
}
