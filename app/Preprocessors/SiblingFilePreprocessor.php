<?php

declare(strict_types=1);

namespace App\Preprocessors;

use App\Contracts\PreprocessorInterface;
use App\Models\Task;

/**
 * Preprocessor that finds sibling files based on paths mentioned in the task description.
 *
 * If a task says "Create src/app/api/search/route.ts", this finds other
 * src/app/api/[wildcard]/route.ts files and injects one as a template.
 */
class SiblingFilePreprocessor implements PreprocessorInterface
{
    private const MAX_CONTENT_LINES = 150;

    public function getName(): string
    {
        return 'sibling-files';
    }

    public function process(Task $task, string $cwd): ?string
    {
        $description = $task->title . ' ' . ($task->description ?? '');

        // Extract file paths from description
        $targetPaths = $this->extractFilePaths($description);
        if ($targetPaths === []) {
            return null;
        }

        $outputs = [];
        foreach ($targetPaths as $targetPath) {
            $siblings = $this->findSiblingFiles($targetPath, $cwd);
            if ($siblings === []) {
                continue;
            }

            // Pick the smallest sibling as template
            $template = $this->pickBestTemplate($siblings, $cwd);
            if ($template === null) {
                continue;
            }

            $content = $this->getFileContent($template, $cwd);
            if ($content === null) {
                continue;
            }

            $outputs[] = $this->formatOutput($targetPath, $template, $content);
        }

        return $outputs !== [] ? implode("\n\n", $outputs) : null;
    }

    /**
     * Extract file paths from text using regex.
     *
     * @return array<string>
     */
    private function extractFilePaths(string $text): array
    {
        $paths = [];

        // Match paths like src/app/api/search/route.ts or ./lib/auth.ts
        // Must have at least one directory separator and a file extension
        if (preg_match_all(
            '#(?:^|[\s\'"(])\.{0,2}/?([a-zA-Z0-9_\-./]+/[a-zA-Z0-9_\-]+\.[a-zA-Z0-9]+)#',
            $text,
            $matches
        )) {
            foreach ($matches[1] as $match) {
                // Clean up the path
                $path = ltrim($match, './');

                // Skip obvious non-code files
                if ($this->shouldSkipPath($path)) {
                    continue;
                }

                $paths[] = $path;
            }
        }

        return array_unique($paths);
    }

    /**
     * Find sibling files that match a similar pattern.
     *
     * @return array<string>
     */
    private function findSiblingFiles(string $targetPath, string $cwd): array
    {
        $dir = dirname($targetPath);
        $filename = basename($targetPath);

        // Strategy 1: Same filename in sibling directories
        // src/app/api/search/route.ts -> src/app/api/*/route.ts
        $parentDir = dirname($dir);
        if ($parentDir !== '.') {
            $pattern = $cwd . '/' . $parentDir . '/*/' . $filename;
            $siblings = glob($pattern);

            // Filter out the target itself and non-existent files
            $siblings = array_filter($siblings, function ($path) use ($targetPath, $cwd) {
                $relative = str_replace($cwd . '/', '', $path);
                return $relative !== $targetPath && is_file($path);
            });

            if ($siblings !== []) {
                return array_values($siblings);
            }
        }

        // Strategy 2: Same extension in same directory pattern
        // src/lib/newHelper.ts -> src/lib/*.ts
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if ($ext !== '') {
            $pattern = $cwd . '/' . $dir . '/*.' . $ext;
            $siblings = glob($pattern);

            $siblings = array_filter($siblings, function ($path) use ($targetPath, $cwd) {
                $relative = str_replace($cwd . '/', '', $path);
                return $relative !== $targetPath && is_file($path);
            });

            if ($siblings !== []) {
                return array_values($siblings);
            }
        }

        return [];
    }

    /**
     * Pick the best template file (prefer smaller files).
     */
    private function pickBestTemplate(array $siblings, string $cwd): ?string
    {
        if ($siblings === []) {
            return null;
        }

        // Sort by file size (smallest first)
        usort($siblings, function ($a, $b) {
            return filesize($a) <=> filesize($b);
        });

        // Return the smallest file that's not too tiny (>10 lines)
        foreach ($siblings as $sibling) {
            $lines = count(file($sibling));
            if ($lines >= 10) {
                return $sibling;
            }
        }

        // Fall back to first if all are tiny
        return $siblings[0];
    }

    /**
     * Get file content with line numbers, truncated if needed.
     */
    private function getFileContent(string $path, string $cwd): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        $lines = file($path);
        if ($lines === false) {
            return null;
        }

        $totalLines = count($lines);
        $truncated = false;

        if ($totalLines > self::MAX_CONTENT_LINES) {
            $lines = array_slice($lines, 0, self::MAX_CONTENT_LINES);
            $truncated = true;
        }

        $output = '';
        foreach ($lines as $i => $line) {
            $lineNum = str_pad((string)($i + 1), 3, ' ', STR_PAD_LEFT);
            $output .= $lineNum . ' | ' . rtrim($line) . "\n";
        }

        if ($truncated) {
            $output .= "... (" . ($totalLines - self::MAX_CONTENT_LINES) . " more lines)\n";
        }

        return $output;
    }

    /**
     * Check if a path should be skipped.
     */
    private function shouldSkipPath(string $path): bool
    {
        $skipPatterns = [
            '.json',
            '.lock',
            '.md',
            '.txt',
            '.yaml',
            '.yml',
            '.env',
            'node_modules/',
            'vendor/',
            '.git/',
        ];

        foreach ($skipPatterns as $pattern) {
            if (str_contains($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format the output for injection into the prompt.
     */
    private function formatOutput(string $targetPath, string $templatePath, string $content): string
    {
        // Make template path relative
        $relativePath = $templatePath;
        if (str_contains($templatePath, '/')) {
            $parts = explode('/', $templatePath);
            // Find where src/ or app/ starts
            foreach ($parts as $i => $part) {
                if (in_array($part, ['src', 'app', 'lib', 'tests', 'prisma'])) {
                    $relativePath = implode('/', array_slice($parts, $i));
                    break;
                }
            }
        }

        $output = "== TEMPLATE: Sibling file for {$targetPath} ==\n";
        $output .= "Use this existing file as a pattern to follow:\n\n";
        $output .= "// {$relativePath}\n";
        $output .= $content;

        return $output;
    }
}
