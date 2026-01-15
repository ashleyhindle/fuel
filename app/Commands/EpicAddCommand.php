<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\EpicService;
use App\Services\FuelContext;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class EpicAddCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'epic:add
        {title : The epic title}
        {--description= : Epic description}
        {--selfguided : Create self-guided epic with single iterating task}
        {--json : Output as JSON}
        {--cwd= : Working directory (defaults to current directory)}';

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

        // Create stub plan file
        $planFilename = $this->createPlanFile($context, $epic->title, $epic->short_id, $description);

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

    private function createPlanFile(FuelContext $context, string $title, string $epicId, ?string $description): string
    {
        $plansDir = $context->getPlansPath();
        if (! is_dir($plansDir)) {
            mkdir($plansDir, 0755, true);
        }

        $filename = Str::kebab($title).'-'.$epicId.'.md';
        $planPath = $plansDir.'/'.$filename;

        $descriptionSection = $description ? "\n{$description}\n" : '';

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

        file_put_contents($planPath, $content);

        return $filename;
    }
}
