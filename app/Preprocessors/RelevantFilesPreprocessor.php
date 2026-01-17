<?php

declare(strict_types=1);

namespace App\Preprocessors;

use App\Contracts\PreprocessorInterface;
use App\Models\Task;

/**
 * Preprocessor that finds files likely relevant to a task using ripgrep.
 *
 * Extracts keywords from task title/description and searches for files
 * containing those terms, providing the agent with a head start.
 */
class RelevantFilesPreprocessor implements PreprocessorInterface
{
    private const MAX_FILES = 10;

    private const MIN_KEYWORD_LENGTH = 3;

    /**
     * Common words to exclude from keyword extraction.
     */
    private const STOP_WORDS = [
        'the', 'and', 'for', 'that', 'this', 'with', 'from', 'are', 'was', 'were',
        'been', 'being', 'have', 'has', 'had', 'does', 'did', 'will', 'would',
        'could', 'should', 'may', 'might', 'must', 'shall', 'can', 'need', 'dare',
        'add', 'fix', 'update', 'remove', 'delete', 'create', 'implement', 'change',
        'make', 'use', 'get', 'set', 'new', 'old', 'all', 'any', 'some', 'when',
        'where', 'which', 'what', 'who', 'how', 'why', 'not', 'but', 'also',
        'task', 'file', 'code', 'test', 'tests', 'feature', 'bug', 'issue',
    ];

    public function getName(): string
    {
        return 'relevant-files';
    }

    public function process(Task $task, string $cwd): ?string
    {
        $keywords = $this->extractKeywords($task);
        if ($keywords === []) {
            return null;
        }

        $files = $this->findRelevantFiles($keywords, $cwd);
        if ($files === []) {
            return null;
        }

        return $this->formatOutput($files, $keywords);
    }

    /**
     * Extract meaningful keywords from task title and description.
     *
     * @return array<string>
     */
    private function extractKeywords(Task $task): array
    {
        $text = $task->title.' '.($task->description ?? '');

        // Extract words, including CamelCase splitting
        $words = $this->tokenize($text);

        // Filter and deduplicate
        $keywords = [];
        foreach ($words as $word) {
            $lower = strtolower($word);
            if (strlen($lower) >= self::MIN_KEYWORD_LENGTH
                && ! in_array($lower, self::STOP_WORDS, true)
                && ! is_numeric($lower)
            ) {
                $keywords[$lower] = $word; // Preserve original case
            }
        }

        // Limit to most likely useful keywords (first N unique)
        return array_slice(array_values($keywords), 0, 8);
    }

    /**
     * Tokenize text into words, splitting CamelCase.
     *
     * @return array<string>
     */
    private function tokenize(string $text): array
    {
        // Split CamelCase: "TaskService" -> "Task Service"
        $text = preg_replace('/([a-z])([A-Z])/', '$1 $2', $text);

        // Split on non-alphanumeric
        $words = preg_split('/[^a-zA-Z0-9]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY);

        return $words ?: [];
    }

    /**
     * Find files containing any of the keywords using ripgrep.
     *
     * @param  array<string>  $keywords
     * @return array<string> File paths relative to cwd
     */
    private function findRelevantFiles(array $keywords, string $cwd): array
    {
        // Build regex pattern: keyword1|keyword2|keyword3
        $pattern = implode('|', array_map(preg_quote(...), $keywords));

        // Try ripgrep first, fall back to grep
        $command = $this->buildSearchCommand($pattern, $cwd);
        if ($command === null) {
            return [];
        }

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return [];
        }

        // Parse output and count matches per file
        $fileCounts = [];
        foreach ($output as $line) {
            // ripgrep -c output: "file:count"
            if (preg_match('/^(.+):(\d+)$/', $line, $matches)) {
                $file = $matches[1];
                $count = (int) $matches[2];

                // Make path relative to cwd
                if (str_starts_with($file, $cwd)) {
                    $file = substr($file, strlen($cwd) + 1);
                }

                // Skip vendor, node_modules, .git, etc.
                if ($this->shouldSkipFile($file)) {
                    continue;
                }

                $fileCounts[$file] = ($fileCounts[$file] ?? 0) + $count;
            }
        }

        // Sort by match count (descending) and take top N
        arsort($fileCounts);

        return array_slice(array_keys($fileCounts), 0, self::MAX_FILES);
    }

    /**
     * Build the search command (ripgrep or grep fallback).
     */
    private function buildSearchCommand(string $pattern, string $cwd): ?string
    {
        // Check for ripgrep
        $rgPath = $this->findExecutable('rg');
        if ($rgPath !== null) {
            // -c = count matches, -i = case insensitive, --no-heading
            return sprintf(
                '%s -c -i --no-heading %s %s 2>/dev/null',
                escapeshellcmd($rgPath),
                escapeshellarg($pattern),
                escapeshellarg($cwd)
            );
        }

        // Fall back to grep
        $grepPath = $this->findExecutable('grep');
        if ($grepPath !== null) {
            return sprintf(
                '%s -r -c -i -E %s %s 2>/dev/null',
                escapeshellcmd($grepPath),
                escapeshellarg($pattern),
                escapeshellarg($cwd)
            );
        }

        return null;
    }

    /**
     * Find an executable in PATH.
     */
    private function findExecutable(string $name): ?string
    {
        $result = shell_exec('which '.escapeshellarg($name).' 2>/dev/null');

        return $result !== null ? trim($result) : null;
    }

    /**
     * Check if a file should be skipped (vendor, node_modules, etc.).
     */
    private function shouldSkipFile(string $file): bool
    {
        $skipPatterns = [
            'vendor/',
            'node_modules/',
            '.git/',
            '.fuel/',
            'builds/',
            'storage/',
            'bootstrap/cache/',
            '.png', '.jpg', '.jpeg', '.gif', '.ico', '.svg',
            '.lock', '.map',
        ];

        foreach ($skipPatterns as $pattern) {
            if (str_contains($file, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format the output for injection into the prompt.
     *
     * @param  array<string>  $files
     * @param  array<string>  $keywords
     */
    private function formatOutput(array $files, array $keywords): string
    {
        $output = "== PREPROCESSOR: Relevant Files ==\n";
        $output .= 'Keywords searched: '.implode(', ', $keywords)."\n";
        $output .= "Files likely relevant to this task (by match count):\n";

        foreach ($files as $file) {
            $output .= sprintf('  - %s%s', $file, PHP_EOL);
        }

        return $output . "\nConsider starting your exploration with these files.\n";
    }
}
