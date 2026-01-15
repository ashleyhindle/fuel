<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class PlanSession
{
    private Process $process;

    private array $planData = [];

    private ?string $epicId = null;

    private string $conversationState = 'initial';

    private array $conversationHistory = [];

    /**
     * Start a new planning session
     */
    public function start(?string $epicId = null): void
    {
        $this->epicId = $epicId;

        // Build the command to spawn Claude with JSON mode
        $command = [
            'claude',
            '--model', 'opus-4-5-20250101',
            '--input-format', 'json',
            '--output-format', 'json',
        ];

        $this->process = new Process($command);
        $this->process->setTty(false);
        $this->process->setPty(false);
        $this->process->setTimeout(null);
        $this->process->setIdleTimeout(null);
        $this->process->setWorkingDirectory(getcwd());

        $this->process->start();
    }

    /**
     * Send a message to Claude
     */
    public function sendMessage(array $message): void
    {
        if (! $this->process->isRunning()) {
            throw new \RuntimeException('Planning session is not running');
        }

        $this->process->getInput()->write(json_encode($message)."\n");
        $this->process->getInput()->flush();
    }

    /**
     * Get the next output from Claude
     */
    public function getOutput(): ?array
    {
        if (! $this->process->isRunning()) {
            return null;
        }

        $output = $this->process->getIncrementalOutput();
        if (! $output) {
            return null;
        }

        // Parse JSON lines
        $lines = explode("\n", trim($output));
        $messages = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $data = json_decode($line, true);
            if ($data) {
                $messages[] = $data;

                // Track plan refinements in conversation
                $this->trackPlanRefinement($data);
            }
        }

        return $messages;
    }

    /**
     * Track plan refinements from the conversation
     */
    private function trackPlanRefinement(array $message): void
    {
        // Look for plan elements in Claude's responses
        if ($message['type'] === 'message' || $message['type'] === 'assistant') {
            $text = '';
            if (isset($message['message']['content'])) {
                foreach ($message['message']['content'] as $content) {
                    if (isset($content['text'])) {
                        $text .= $content['text'];
                    }
                }
            }

            if (! empty($text)) {
                // Track conversation history
                $this->conversationHistory[] = [
                    'role' => 'assistant',
                    'content' => $text,
                    'timestamp' => time(),
                ];

                // Extract plan elements from conversation
                $this->extractPlanElements($text);
            }
        }

        // Track tool calls for epic/task creation
        if ($message['type'] === 'tool_call' && isset($message['tool_call'])) {
            $this->trackToolCall($message['tool_call']);
        }
    }

    /**
     * Extract plan elements from conversation text
     */
    private function extractPlanElements(string $text): void
    {
        // Look for acceptance criteria
        if (preg_match('/acceptance\s+criteria[:\s]+(.+?)(?:\n\n|$)/is', $text, $matches)) {
            $this->planData['acceptance_criteria'] = $matches[1];
        }

        // Look for implementation approach
        if (preg_match('/(?:approach|implementation)[:\s]+(.+?)(?:\n\n|$)/is', $text, $matches)) {
            $this->planData['approach'] = $matches[1];
        }

        // Look for task breakdown
        if (preg_match('/(?:tasks?|breakdown)[:\s]+(.+?)(?:\n\n|$)/is', $text, $matches)) {
            $this->planData['tasks'] = $matches[1];
        }

        // Detect when user seems satisfied with plan
        $readyPhrases = [
            'looks good',
            'let\'s do it',
            'create the epic',
            'sounds perfect',
            'i like it',
            'go ahead',
        ];

        foreach ($readyPhrases as $phrase) {
            if (stripos($text, $phrase) !== false) {
                $this->conversationState = 'ready_to_create';
                break;
            }
        }
    }

    /**
     * Track tool calls (epic/task creation)
     */
    private function trackToolCall(array $toolCall): void
    {
        $toolName = array_keys($toolCall)[0] ?? '';
        $params = $toolCall[$toolName] ?? [];

        if ($toolName === 'Bash' && isset($params['command'])) {
            $command = $params['command'];

            // Track epic creation
            if (preg_match('/fuel\s+epic:add\s+"([^"]+)"/', $command, $matches)) {
                $this->planData['epic_title'] = $matches[1];

                // Extract epic ID from expected output format
                if (preg_match('/--selfguided/', $command)) {
                    $this->planData['selfguided'] = true;
                }
            }

            // Track task creation
            if (preg_match('/fuel\s+add\s+"([^"]+)"/', $command, $matches)) {
                if (! isset($this->planData['created_tasks'])) {
                    $this->planData['created_tasks'] = [];
                }
                $this->planData['created_tasks'][] = $matches[1];
            }
        }
    }

    /**
     * Get current plan data
     */
    public function getPlanData(): array
    {
        return $this->planData;
    }

    /**
     * Get conversation state
     */
    public function getConversationState(): string
    {
        return $this->conversationState;
    }

    /**
     * Set conversation state
     */
    public function setConversationState(string $state): void
    {
        $this->conversationState = $state;
    }

    /**
     * Add user message to history
     */
    public function addUserMessage(string $message): void
    {
        $this->conversationHistory[] = [
            'role' => 'user',
            'content' => $message,
            'timestamp' => time(),
        ];
    }

    /**
     * Get conversation summary for plan file
     */
    public function getConversationSummary(): string
    {
        $summary = "## Planning Conversation Summary\n\n";

        foreach ($this->conversationHistory as $entry) {
            $time = date('H:i:s', $entry['timestamp']);
            $role = ucfirst($entry['role']);
            $content = $this->truncateContent($entry['content'], 200);

            $summary .= "**[{$time}] {$role}:** {$content}\n\n";
        }

        return $summary;
    }

    /**
     * Truncate content for summary
     */
    private function truncateContent(string $content, int $maxLength): string
    {
        if (strlen($content) <= $maxLength) {
            return $content;
        }

        return substr($content, 0, $maxLength - 3).'...';
    }

    /**
     * Check if process is running
     */
    public function isRunning(): bool
    {
        return $this->process && $this->process->isRunning();
    }

    /**
     * Stop the planning session
     */
    public function stop(): void
    {
        if ($this->isRunning()) {
            $this->process->stop(5);
        }
    }

    /**
     * Get process for direct access
     */
    public function getProcess(): Process
    {
        return $this->process;
    }
}
