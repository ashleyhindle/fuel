<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Models\Epic;
use App\Services\EpicService;
use App\Services\FuelContext;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicAddCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:add
        {title : The epic title}
        {--description= : Epic description}
        {--selfguided : Create self-guided epic with single iterating task}
        {--json : Output as JSON}';

    protected $description = 'Add a new epic';

    public function handle(EpicService $epicService, FuelContext $context): int
    {
        $title = $this->argument('title');
        $description = $this->option('description');
        $selfGuided = $this->option('selfguided');

        try {
            $epic = $epicService->createEpic($title, $description, $selfGuided);
        } catch (RuntimeException $runtimeException) {
            return $this->outputError($runtimeException->getMessage());
        }

        // Create stub plan file and store filename on epic
        $planFilename = $this->createPlanFile($context, $epic, $description, $selfGuided);

        if ($this->option('json')) {
            $this->outputJson($epic->toArray());
        } else {
            $this->info('Created epic: '.$epic->short_id);
            $this->line('  Title: '.$epic->title);
            if ($epic->description) {
                $this->line('  Description: '.$epic->description);
            }

            $this->line('  Plan: .fuel/plans/'.$planFilename.' (add your plan here)');
            if ($selfGuided && isset($epic->selfGuidedTaskId)) {
                $this->line('  Created self-guided task: '.$epic->selfGuidedTaskId);
            }
        }

        return self::SUCCESS;
    }

    private function createPlanFile(FuelContext $context, Epic $epic, ?string $description, bool $selfGuided = false): string
    {
        $plansDir = $context->getPlansPath();
        if (! is_dir($plansDir)) {
            mkdir($plansDir, 0755, true);
        }

        $filename = Epic::generatePlanFilename($epic->title, $epic->short_id);
        $planPath = $plansDir.'/'.$filename;

        // Store the filename on the epic
        $epic->plan_filename = $filename;
        $epic->save();

        $title = $epic->title;
        $epicId = $epic->short_id;
        $descriptionSection = $description ? PHP_EOL.$description.PHP_EOL : '';

        if ($selfGuided) {
            $content = <<<MARKDOWN
# Epic: {$title} ({$epicId})

## Plan
{$descriptionSection}
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
        } else {
            $content = <<<MARKDOWN
# Epic: {$title} ({$epicId})

## Plan
{$descriptionSection}
<!-- Add implementation plan here -->

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->
MARKDOWN;
        }

        file_put_contents($planPath, $content);

        return $filename;
    }
}
