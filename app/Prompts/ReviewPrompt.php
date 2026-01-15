<?php

declare(strict_types=1);

namespace App\Prompts;

use App\Models\Task;
use App\Services\PromptService;

class ReviewPrompt
{
    /**
     * Maximum character limit for git diff before truncation.
     */
    private const MAX_DIFF_CHARS = 5000;

    public function __construct(
        private readonly PromptService $promptService
    ) {}

    /**
     * Generate the review prompt for a reviewing agent.
     *
     * @param  Task  $task  The task model
     * @param  string  $gitDiff  The git diff output
     * @param  string  $gitStatus  The git status output
     */
    public function generate(Task $task, string $gitDiff, string $gitStatus): string
    {
        $template = $this->promptService->loadTemplate('review');

        $variables = [
            'task' => [
                'id' => $task->short_id ?? 'unknown',
                'title' => $task->title ?? 'Untitled task',
                'description' => $task->description ?? 'No description provided',
            ],
            'git' => [
                'diff' => $this->truncateDiff($gitDiff),
                'status' => $gitStatus,
            ],
        ];

        return $this->promptService->render($template, $variables);
    }

    /**
     * Truncate the diff if it exceeds the maximum character limit.
     */
    private function truncateDiff(string $diff): string
    {
        if (strlen($diff) <= self::MAX_DIFF_CHARS) {
            return $diff;
        }

        $truncated = substr($diff, 0, self::MAX_DIFF_CHARS);

        // Try to truncate at a newline to avoid cutting mid-line
        $lastNewline = strrpos($truncated, "\n");
        if ($lastNewline !== false && $lastNewline > self::MAX_DIFF_CHARS * 0.8) {
            $truncated = substr($truncated, 0, $lastNewline);
        }

        $remainingChars = strlen($diff) - strlen($truncated);

        return $truncated."\n\n... [TRUNCATED: {$remainingChars} more characters] ...";
    }
}
