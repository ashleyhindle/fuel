<?php

declare(strict_types=1);

namespace Laravel\Boost\Mcp\Prompts;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;

class CompleteFuelTasksPrompt extends Prompt
{
    public string $name = 'complete-fuel-tasks';

    public string $title = 'Complete Fuel Tasks';

    public string $description = 'Work through all ready Fuel tasks using subagents until completion criteria and tests pass';

    public function handle(): Response
    {
        $content = <<<'PROMPT'
# Complete Fuel Tasks

Work through the Fuel task queue in a loop using subagents until all ready tasks are complete and tests pass.

## Workflow
Each subagent should complete their individual task.

1. Run `artisan fuel:ready --json` to see available tasks
2. Pick the highest priority task (P0 > P1 > P2 > P3 > P4)
3. Implement the task requirements
4. Verify - run tests, linting, static analysis
5. Complete with `artisan fuel:done <id> --reason="What was done"`
6. Check `artisan fuel:ready` again for newly unblocked work
7. Repeat until no ready tasks remain

## Use Subagents

Spawn subagents for parallel work:

- **Explore agents** for understanding codebase
- **Plan agents** for designing approaches
- **General-purpose agents** for independent implementations

When multiple tasks are ready and independent, work them in parallel.

## Completion Criteria

A task is complete when:
- Implementation finished
- Tests pass
- Code style passes
- Static analysis passes (if configured)

## Discovering Work

When you find additional work needed, add it with `artisan fuel:add` and link dependencies with `--blocked-by` if needed.

Begin by running `artisan fuel:ready --json`.
PROMPT;

        return Response::text($content);
    }
}
