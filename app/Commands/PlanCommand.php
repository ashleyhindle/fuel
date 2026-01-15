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
            '--output-format', 'json',
        ];

        $process = new Process($command);

        // JSON mode requires no TTY
        $process->setTty(false);
        $process->setPty(false);
        $process->setTimeout(null); // No timeout for interactive session
        $process->setIdleTimeout(null);

        // Set working directory to project root
        $process->setWorkingDirectory(getcwd());

        try {
            $this->info("🚀 Starting interactive planning session with Claude Opus 4.5");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("Type 'exit' or press Ctrl+C to end the session.\n");

            // Start the process
            $process->start();

            if (! $process->isRunning()) {
                $this->error('Failed to start Claude process');
                $this->error($process->getErrorOutput());

                return;
            }

            // Send initial system prompt with planning constraints
            $systemPrompt = $this->getPlanningSystemPrompt();
            $initialMessage = [
                'type' => 'message',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $systemPrompt."\n\nWhat would you like to build? Describe your feature idea, and I'll help you plan it thoroughly before we start implementation.",
                    ],
                ],
            ];

            $process->getInput()->write(json_encode($initialMessage)."\n");
            $process->getInput()->flush();

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
        $waitingForInput = false;
        $conversationState = 'initial'; // Track state: initial, planning, ready_to_create

        while ($process->isRunning()) {
            // Check for Claude's output
            $output = $process->getIncrementalOutput();
            if ($output) {
                $outputBuffer .= $output;

                // Process complete JSON objects (they end with newline)
                while (($newlinePos = strpos($outputBuffer, "\n")) !== false) {
                    $line = substr($outputBuffer, 0, $newlinePos);
                    $outputBuffer = substr($outputBuffer, $newlinePos + 1);

                    if (! empty(trim($line))) {
                        $waitingForInput = $this->processClaudeOutput($line);
                    }
                }
            }

            // Check for errors
            $error = $process->getIncrementalErrorOutput();
            if ($error) {
                $this->error('❌ Error from Claude: '.$error);
            }

            // Only prompt for input when Claude is done responding
            if ($waitingForInput && ! $this->hasMoreOutput($process)) {
                $userInput = $this->ask('You');

                if (strtolower($userInput) === 'exit') {
                    $this->info("\n👋 Ending planning session...");
                    $this->warn("Note: No epic was created. Run 'fuel plan' again to start fresh.");
                    break;
                }

                // Inject planning constraints with each user message
                $messageWithConstraints = $this->wrapUserMessage($userInput, $conversationState);

                $process->getInput()->write(json_encode($messageWithConstraints)."\n");
                $process->getInput()->flush();

                $waitingForInput = false;
            }

            // Small delay to prevent CPU spinning
            usleep(50000); // 50ms
        }

        $this->info("\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("Planning session ended.");

        if (! $process->isRunning() && $process->getExitCode() !== 0) {
            $this->error('Claude process exited with error code: '.$process->getExitCode());
        }
    }

    /**
     * Process a line of output from Claude
     *
     * @return bool Whether we're waiting for user input
     */
    private function processClaudeOutput(string $line): bool
    {
        $data = json_decode($line, true);
        if (! $data) {
            // Not JSON, skip it
            return false;
        }

        // Handle different message types from Claude
        switch ($data['type'] ?? '') {
            case 'message':
            case 'assistant':
                // Extract text content from nested structure
                $text = '';
                if (isset($data['message']['content'])) {
                    foreach ($data['message']['content'] as $content) {
                        if (isset($content['text'])) {
                            $text .= $content['text'];
                        }
                    }
                }

                if (! empty($text)) {
                    $this->line('');
                    $this->comment('Claude:');
                    $this->line($text);
                    $this->line('');

                    // After Claude responds, we're waiting for input
                    return true;
                }
                break;

            case 'tool_call':
                if (isset($data['subtype']) && $data['subtype'] === 'started') {
                    if (isset($data['tool_call'])) {
                        $toolName = array_keys($data['tool_call'])[0] ?? 'unknown';
                        $this->checkToolConstraint($toolName, $data['tool_call'][$toolName] ?? []);
                    }
                }
                break;

            case 'tool_result':
                // Don't show tool results in planning mode to keep output clean
                break;

            case 'error':
                $this->error('❌ ' . ($data['message'] ?? 'Unknown error'));
                break;

            case 'system':
                // Ignore system messages in planning mode
                break;
        }

        return false;
    }

    /**
     * Check if a tool call violates planning mode constraints
     */
    private function checkToolConstraint(string $toolName, array $params): void
    {
        // Whitelist of allowed tools and their constraints
        $allowedTools = [
            'Read' => true,
            'Grep' => true,
            'Glob' => true,
            'Bash' => function($params) {
                // Only fuel commands are allowed
                $command = $params['command'] ?? '';
                return str_starts_with($command, 'fuel ') || str_starts_with($command, './fuel ');
            },
        ];

        // Check if tool is allowed
        if (! isset($allowedTools[$toolName])) {
            $this->warn("⚠️  Tool '{$toolName}' is not allowed in planning mode. Ignoring.");
            return;
        }

        // Check specific constraints for the tool
        if (is_callable($allowedTools[$toolName])) {
            if (! $allowedTools[$toolName]($params)) {
                $this->warn("⚠️  Command not allowed in planning mode. Only 'fuel' commands permitted.");
                return;
            }
        }

        // Tool is allowed - show what Claude is doing
        $this->info("→ Claude is using: {$toolName}");
    }

    /**
     * Check if there's more output coming from the process
     */
    private function hasMoreOutput(Process $process): bool
    {
        // Wait a bit to see if more output is coming
        usleep(100000); // 100ms

        $output = $process->getIncrementalOutput();
        $error = $process->getIncrementalErrorOutput();

        return ! empty($output) || ! empty($error);
    }

    /**
     * Wrap user message with planning constraints reminder
     */
    private function wrapUserMessage(string $userInput, string &$conversationState): array
    {
        // Check if user is ready to create the epic
        if (stripos($userInput, 'looks good') !== false ||
            stripos($userInput, 'let\'s do it') !== false ||
            stripos($userInput, 'create the epic') !== false) {
            $conversationState = 'ready_to_create';
        }

        $reminder = '';
        if ($conversationState === 'ready_to_create') {
            $reminder = "\n\n[REMINDER: The user seems ready. Create the epic with 'fuel epic:add', write the plan file, and if pre-planned, create the tasks.]";
        } else {
            $reminder = "\n\n[REMINDER: You're in planning mode. Focus on discussion and refinement. Only use Read/Grep/Glob for exploration and 'fuel' commands when ready to create the epic.]";
        }

        return [
            'type' => 'message',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $userInput . $reminder,
                ],
            ],
        ];
    }

    /**
     * Get the system prompt for planning mode
     */
    private function getPlanningSystemPrompt(): string
    {
        return <<<'PROMPT'
You are in PLANNING MODE for the Fuel task management system. Your role is to help the user plan a feature through iterative conversation.

STRICT CONSTRAINTS - YOU MUST FOLLOW THESE:
1. You may ONLY use these tools:
   - Read: To understand the codebase (READ-ONLY)
   - Bash: ONLY for `fuel` commands (epic:add, add, etc.) - NO other commands allowed
   - Grep/Glob: To search the codebase (READ-ONLY)

2. You may NOT use these tools:
   - Write, Edit, NotebookEdit: NO file modifications allowed during planning
   - Bash for anything except fuel commands: NO running tests, NO executing code
   - ExitPlanMode, EnterPlanMode: You're already in planning mode
   - TodoWrite: Planning doesn't need session todos

3. Focus on:
   - Understanding requirements through questions
   - Exploring the codebase to inform the plan
   - Iteratively refining the plan with the user
   - Creating clear acceptance criteria

PLANNING WORKFLOW:
1. Discuss and refine the feature idea with the user
2. Ask clarifying questions to understand scope and requirements
3. Read relevant files to understand existing patterns and constraints
4. Once the plan is solid, create an epic with `fuel epic:add "Title" --description="..."`
5. Write the plan to `.fuel/plans/{title-kebab}-{epic-id}.md`
6. For pre-planned epics: create tasks with proper dependencies
7. Tell the user: "Planning complete! Run `fuel consume` to begin execution"

IMPORTANT: This is a collaborative planning session. Don't rush to implementation. Take time to understand what the user wants to build and help them think through edge cases and design decisions.
PROMPT;
    }
}
