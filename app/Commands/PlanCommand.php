<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class PlanCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plan {epic-id? : Resume planning for existing epic}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interactive planning session with Claude Opus for feature design';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $epicId = $this->argument('epic-id');

        if ($epicId) {
            $this->info("Resuming planning session for epic: {$epicId}");
            // TODO: Resume existing epic planning
        } else {
            $this->info('Starting new planning session with Claude Opus 4.5...');
            $this->info("Type 'exit' or press Ctrl+C to end the planning session.\n");

            // Start interactive planning immediately
            $this->startPlanningSession();
        }

        return self::SUCCESS;
    }

    /**
     * Start an interactive planning session with Claude
     */
    private function startPlanningSession(): void
    {
        // Build the command to spawn Claude with JSON mode
        $command = [
            'claude',
            '--model', 'opus-4-5-20250101',
            '--input-format', 'json',
        ];

        $process = new Process($command);

        // Only set TTY if we're in a real terminal (not testing)
        if (app()->environment() !== 'testing' && Process::isTtySupported()) {
            $process->setTty(true);
        }
        $process->setTimeout(null); // No timeout for interactive session

        try {
            $this->info("Connecting to Claude Opus 4.5 in planning mode...\n");

            // Inject initial system prompt for planning mode
            $systemPrompt = $this->getPlanningSystemPrompt();

            // For now, just spawn Claude directly
            // TODO: Implement JSON communication layer

            // Don't actually run the process in testing mode
            if (app()->environment() !== 'testing') {
                $process->run();

                if (! $process->isSuccessful()) {
                    $this->error('Planning session ended with errors.');
                    $this->error($process->getErrorOutput());
                } else {
                    $this->info("\nPlanning session ended successfully.");
                    $this->info("Run 'fuel consume' to begin working on your planned tasks.");
                }
            }
        } catch (\Exception $e) {
            $this->error('Failed to start planning session: '.$e->getMessage());
        }
    }

    /**
     * Get the system prompt for planning mode
     */
    private function getPlanningSystemPrompt(): string
    {
        return <<<'PROMPT'
You are in PLANNING MODE. Your role is to help the user plan a feature through conversation.

CONSTRAINTS:
- You may READ files to understand the codebase
- You may NOT write, edit, or create files (except .fuel/plans/*.md via fuel commands)
- You may NOT execute code or run tests
- You may NOT enter your own planning mode (use ExitPlanMode or EnterPlanMode)
- Focus on understanding requirements, asking clarifying questions, and refining the plan

When the plan is ready, you will:
1. Create an epic with `fuel epic:add`
2. Update the plan file
3. For pre-planned epics: create tasks with `fuel add`
4. Tell the user: "Planning complete! Run `fuel consume` to begin execution"
PROMPT;
    }
}
