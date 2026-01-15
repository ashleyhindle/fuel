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
        // Don't actually run in testing mode
        if (app()->environment() === 'testing') {
            return;
        }

        // Build the command to spawn Claude with JSON mode
        $command = [
            'claude',
            '--model', 'opus-4-5-20250101',
            '--input-format', 'json',
        ];

        $process = new Process($command);

        // JSON mode requires no TTY
        $process->setTty(false);
        $process->setPty(false);
        $process->setTimeout(null); // No timeout for interactive session
        $process->setIdleTimeout(null);

        try {
            $this->info("Connecting to Claude Opus 4.5 in planning mode...\n");

            // Start the process
            $process->start();

            if (! $process->isRunning()) {
                $this->error('Failed to start Claude process');
                $this->error($process->getErrorOutput());

                return;
            }

            // Send initial system prompt
            $systemPrompt = $this->getPlanningSystemPrompt();
            $initialMessage = [
                'type' => 'message',
                'content' => $systemPrompt."\n\nWhat would you like to build? Describe your feature idea:",
            ];

            $process->getInput()->write(json_encode($initialMessage)."\n");

            // Main interaction loop
            $this->runInteractionLoop($process);

        } catch (\Exception $e) {
            $this->error('Failed to start planning session: '.$e->getMessage());
        } finally {
            if ($process->isRunning()) {
                $process->stop(5);
            }
        }
    }

    /**
     * Run the main interaction loop with Claude
     */
    private function runInteractionLoop(Process $process): void
    {
        $outputBuffer = '';

        while ($process->isRunning()) {
            // Check for Claude's output
            $output = $process->getIncrementalOutput();
            if ($output) {
                $outputBuffer .= $output;

                // Process complete lines
                while (($newlinePos = strpos($outputBuffer, "\n")) !== false) {
                    $line = substr($outputBuffer, 0, $newlinePos);
                    $outputBuffer = substr($outputBuffer, $newlinePos + 1);

                    if (! empty(trim($line))) {
                        $this->processClaudeOutput($line);
                    }
                }
            }

            // Check for errors
            $error = $process->getIncrementalErrorOutput();
            if ($error) {
                $this->error('Error from Claude: '.$error);
            }

            // Small delay to prevent CPU spinning
            usleep(100000); // 100ms

            // Check if user wants to provide input (non-blocking)
            if ($this->hasWaitingInput()) {
                $userInput = $this->ask('You');

                if ($userInput === 'exit') {
                    $this->info('Ending planning session...');
                    break;
                }

                // Send user message to Claude
                $userMessage = [
                    'type' => 'message',
                    'content' => $userInput,
                ];

                $process->getInput()->write(json_encode($userMessage)."\n");
            }
        }

        $this->info("\nPlanning session ended.");
        if (! $process->isRunning() && $process->getExitCode() !== 0) {
            $this->error('Claude exited with error code: '.$process->getExitCode());
        }
    }

    /**
     * Process a line of output from Claude
     */
    private function processClaudeOutput(string $line): void
    {
        $data = json_decode($line, true);
        if (! $data) {
            // Not JSON, might be raw output
            return;
        }

        // Handle different message types from Claude
        switch ($data['type'] ?? '') {
            case 'message':
            case 'assistant':
                if (isset($data['content'])) {
                    $this->line('');
                    $this->comment('Claude:');
                    $this->line($data['content']);
                    $this->line('');
                }
                break;

            case 'tool_call':
                if (isset($data['tool_call'])) {
                    $toolName = array_keys($data['tool_call'])[0] ?? 'unknown';
                    $this->info('→ Claude is executing: '.$toolName);
                }
                break;

            case 'tool_result':
                // Tool result, might want to show something
                break;

            case 'error':
                $this->error('Error: '.($data['message'] ?? 'Unknown error'));
                break;

            case 'system':
                // System messages, possibly ignore or show in verbose mode
                break;
        }
    }

    /**
     * Check if there's input waiting (simplified version)
     */
    private function hasWaitingInput(): bool
    {
        // For now, we'll prompt for input periodically
        // In a real implementation, this could use select() or similar
        static $lastPrompt = 0;
        $now = time();

        if ($now - $lastPrompt > 2) { // Prompt every 2 seconds
            $lastPrompt = $now;

            return true;
        }

        return false;
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
