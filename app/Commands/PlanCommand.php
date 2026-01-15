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
            $this->info('🚀 Starting interactive planning session with Claude Opus 4.5');
            $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
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
        $conversationState = 'initial'; // Track state: initial, planning, refining, ready_to_create
        $turnCount = 0;
        $lastClaudeResponse = '';
        $epicCreated = false;

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
                        $result = $this->processClaudeOutput($line, $lastClaudeResponse, $epicCreated);
                        $waitingForInput = $result['waiting'];
                        if ($result['epicCreated']) {
                            $epicCreated = true;
                        }
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
                $turnCount++;

                // Update state based on conversation progress
                $this->updateConversationState($conversationState, $lastClaudeResponse, $turnCount, $epicCreated);

                // Show hints based on state
                $this->showStateHint($conversationState, $epicCreated);

                $userInput = $this->ask('You');

                if (strtolower($userInput) === 'exit') {
                    $this->info("\n👋 Ending planning session...");
                    if (! $epicCreated) {
                        $this->warn("Note: No epic was created. Run 'fuel plan' again to start fresh.");
                    }
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
        if ($epicCreated) {
            $this->info('✅ Planning session ended successfully!');
            $this->info("Run 'fuel consume' to begin working on the tasks.");
        } else {
            $this->info('Planning session ended.');
        }

        if (! $process->isRunning() && $process->getExitCode() !== 0) {
            $this->error('Claude process exited with error code: '.$process->getExitCode());
        }
    }

    /**
     * Process a line of output from Claude
     *
     * @return array ['waiting' => bool, 'epicCreated' => bool]
     */
    private function processClaudeOutput(string $line, string &$lastClaudeResponse, bool $epicCreated): array
    {
        $data = json_decode($line, true);
        if (! $data) {
            // Not JSON, skip it
            return ['waiting' => false, 'epicCreated' => false];
        }

        $newEpicCreated = false;

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

                    // Track the last response for state detection
                    $lastClaudeResponse = $text;

                    // After Claude responds, we're waiting for input
                    return ['waiting' => true, 'epicCreated' => false];
                }
                break;

            case 'tool_call':
                if (isset($data['subtype']) && $data['subtype'] === 'started') {
                    if (isset($data['tool_call'])) {
                        $toolName = array_keys($data['tool_call'])[0] ?? 'unknown';
                        $params = $data['tool_call'][$toolName] ?? [];
                        $this->checkToolConstraint($toolName, $params);

                        // Check if this is an epic creation
                        if ($toolName === 'Bash' && isset($params['command'])) {
                            $command = $params['command'];
                            if (str_contains($command, 'fuel epic:add')) {
                                $newEpicCreated = true;
                                // Detect if --selfguided flag is present
                                if (str_contains($command, '--selfguided')) {
                                    $this->info("→ Creating self-guided epic (iterative execution)");
                                } else {
                                    $this->info("→ Creating pre-planned epic (all tasks upfront)");
                                }
                            }
                        }
                    }
                }
                break;

            case 'tool_result':
                // Don't show tool results in planning mode to keep output clean
                break;

            case 'error':
                $this->error('❌ '.($data['message'] ?? 'Unknown error'));
                break;

            case 'system':
                // Ignore system messages in planning mode
                break;
        }

        return ['waiting' => false, 'epicCreated' => $newEpicCreated];
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
            'Write' => function ($params) {
                // Only allow writing to .fuel/plans/*.md files
                $filePath = $params['file_path'] ?? '';

                return str_contains($filePath, '.fuel/plans/') && str_ends_with($filePath, '.md');
            },
            'Bash' => function ($params) {
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
                if ($toolName === 'Write') {
                    $this->warn("⚠️  Can only write to .fuel/plans/*.md files in planning mode.");
                } else {
                    $this->warn("⚠️  Command not allowed in planning mode. Only 'fuel' commands permitted.");
                }

                return;
            }
        }

        // Tool is allowed - show what Claude is doing
        if ($toolName === 'Write' && isset($params['file_path'])) {
            $this->info("→ Updating plan file: " . basename($params['file_path']));
        } else {
            $this->info("→ Claude is using: {$toolName}");
        }
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
        // Check if user is choosing mode
        if (stripos($userInput, 'self-guided') !== false) {
            $conversationState = 'mode_selected_selfguided';
        } elseif (stripos($userInput, 'pre-planned') !== false) {
            $conversationState = 'mode_selected_preplanned';
        }
        // Check if user is ready to create the epic
        elseif (stripos($userInput, 'looks good') !== false ||
            stripos($userInput, 'let\'s do it') !== false ||
            stripos($userInput, 'create the epic') !== false) {
            $conversationState = 'ready_to_create';
        }

        $reminder = '';
        if ($conversationState === 'mode_selected_selfguided') {
            $reminder = "\n\n[REMINDER: User chose self-guided. Create the epic with 'fuel epic:add \"Title\" --selfguided --description=\"...\"', then write the plan file with acceptance criteria as checkboxes.]";
        } elseif ($conversationState === 'mode_selected_preplanned') {
            $reminder = "\n\n[REMINDER: User chose pre-planned. Create the epic with 'fuel epic:add \"Title\" --description=\"...\"' (no --selfguided flag), write the plan file, then create all tasks with dependencies.]";
        } elseif ($conversationState === 'ready_to_create') {
            $reminder = "\n\n[REMINDER: The user seems ready. First ask whether this should be self-guided (iterative) or pre-planned (all tasks upfront), then create the epic accordingly.]";
        } else {
            $reminder = "\n\n[REMINDER: You're in planning mode. Focus on discussion and refinement. Only use Read/Grep/Glob for exploration and 'fuel' commands when ready to create the epic.]";
        }

        return [
            'type' => 'message',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $userInput.$reminder,
                ],
            ],
        ];
    }

    /**
     * Update conversation state based on progress
     */
    private function updateConversationState(string &$state, string $lastResponse, int $turnCount, bool $epicCreated): void
    {
        if ($epicCreated) {
            $state = 'completed';

            return;
        }

        // Check if we're asking about self-guided vs pre-planned
        if (stripos($lastResponse, 'self-guided') !== false && stripos($lastResponse, 'pre-planned') !== false) {
            $state = 'choosing_mode';

            return;
        }

        // Detect user satisfaction with plan
        $readyPhrases = [
            'looks good',
            'let\'s do it',
            'create the epic',
            'sounds perfect',
            'i like it',
            'go ahead',
            'ship it',
            'perfect',
        ];

        foreach ($readyPhrases as $phrase) {
            if (stripos($lastResponse, $phrase) !== false) {
                $state = 'ready_to_create';

                return;
            }
        }

        // Progress through states based on turn count and content
        if ($turnCount > 2 && str_contains(strtolower($lastResponse), 'acceptance criteria')) {
            $state = 'refining';
        } elseif ($turnCount > 1) {
            $state = 'planning';
        }
    }

    /**
     * Show helpful hints based on conversation state
     */
    private function showStateHint(string $state, bool $epicCreated): void
    {
        if ($epicCreated) {
            return;
        }

        switch ($state) {
            case 'initial':
                // No hints on first turn
                break;
            case 'planning':
                $this->line($this->wrapInBox('💡 Tip: Ask clarifying questions or suggest improvements to refine the plan.', 'dim'));
                break;
            case 'refining':
                $this->line($this->wrapInBox("💡 Tip: When the plan looks good, say 'looks good' or 'create the epic' to proceed.", 'dim'));
                break;
            case 'choosing_mode':
                $this->line($this->wrapInBox('🎯 Choose: "self-guided" (iterative execution) or "pre-planned" (all tasks upfront)', 'dim'));
                break;
            case 'ready_to_create':
                $this->line($this->wrapInBox('🎯 Claude is ready to create the epic and tasks. Confirm to proceed.', 'dim'));
                break;
        }
    }

    /**
     * Wrap text in a simple box for visual distinction
     */
    private function wrapInBox(string $text, string $style = 'info'): string
    {
        $length = strlen($text) + 2;
        $border = str_repeat('─', $length);

        return "<{$style}>┌{$border}┐\n│ {$text} │\n└{$border}┘</{$style}>";
    }

    /**
     * Get the system prompt for planning mode
     */
    private function getPlanningSystemPrompt(): string
    {
        return <<<'PROMPT'
You are in PLANNING MODE for the Fuel task management system. Your role is to help the user plan a feature through iterative conversation and refinement.

STRICT CONSTRAINTS - YOU MUST FOLLOW THESE:
1. You may ONLY use these tools:
   - Read: To understand the codebase (READ-ONLY)
   - Bash: ONLY for `fuel` commands (epic:add, add, etc.) - NO other commands allowed
   - Grep/Glob: To search the codebase (READ-ONLY)
   - Write: ONLY for `.fuel/plans/*.md` files - plan documentation only

2. You may NOT use these tools:
   - Edit, NotebookEdit: NO code file modifications allowed
   - Write for anything except `.fuel/plans/*.md` files
   - Bash for anything except fuel commands: NO running tests, NO executing code
   - ExitPlanMode, EnterPlanMode: You're already in planning mode
   - TodoWrite: Planning doesn't need session todos

3. Focus on:
   - Understanding requirements through questions
   - Exploring the codebase to inform the plan
   - Iteratively refining the plan with the user
   - Creating clear acceptance criteria

ITERATIVE REFINEMENT PROCESS:
1. **Initial Understanding**: Ask about the user's feature idea and goals
2. **Clarification**: Ask specific questions to uncover edge cases and requirements
3. **Exploration**: Read relevant files to understand existing patterns
4. **Draft Plan**: Present an initial plan with acceptance criteria
5. **Refinement**: Iterate based on user feedback, adjusting the plan as needed
6. **Finalization**: Once user approves, create epic and tasks

KEY BEHAVIORS:
- Start with open questions: "What would you like to build?" "What problem does this solve?"
- Present plans incrementally: Start with high-level approach, then add details
- Check understanding: "Does this match what you had in mind?" "Should we adjust anything?"
- Suggest improvements: "Have you considered...?" "What about edge case X?"
- Be collaborative: This is a discussion, not a one-way specification

WHEN THE USER APPROVES THE PLAN:
1. Ask whether this should be self-guided or pre-planned:
   - Self-guided: Claude implements the feature iteratively, one criterion at a time
   - Pre-planned: You create all tasks upfront with dependencies
2. Create the epic with appropriate flag:
   - Self-guided: `fuel epic:add "Title" --selfguided --description="..."`
   - Pre-planned: `fuel epic:add "Title" --description="..."`
3. Write the detailed plan to `.fuel/plans/{title-kebab}-{epic-id}.md`
4. For pre-planned epics only: create tasks with proper dependencies
5. Tell the user: "Planning complete! Run `fuel consume` to begin execution"

REMEMBER: This is a collaborative planning session. Take time to understand what the user wants to build and help them think through edge cases and design decisions. The goal is a well-thought-out plan that both of you are confident in.
PROMPT;
    }
}
