<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class PromptService
{
    public const CURRENT_VERSION = 1;

    private const PROMPT_NAMES = ['work', 'review', 'verify', 'reality', 'selfguided'];

    public function __construct(
        private readonly FuelContext $context
    ) {}

    /**
     * Load a template by name, preferring user customization over bundled default.
     */
    public function loadTemplate(string $name): string
    {
        $userPath = $this->getUserPromptPath($name);
        if (file_exists($userPath)) {
            $content = file_get_contents($userPath);
            if ($content !== false) {
                return $content;
            }
        }

        return $this->getDefaultPrompt($name);
    }

    /**
     * Render a template with variable substitution.
     *
     * Supports:
     * - Simple variables: {{var}}
     * - Nested variables: {{task.id}}, {{context.epic}}
     *
     * @param  array<string, mixed>  $variables
     */
    public function render(string $template, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{([a-zA-Z0-9_.]+)\}\}/',
            function (array $matches) use ($variables): string {
                $key = $matches[1];
                $value = $this->resolveVariable($key, $variables);

                return is_string($value) ? $value : (string) $value;
            },
            $template
        ) ?? $template;
    }

    /**
     * Get the bundled default prompt for a given name.
     */
    public function getDefaultPrompt(string $name): string
    {
        $path = $this->getBundledPromptPath($name);
        if (! file_exists($path)) {
            throw new RuntimeException("Bundled prompt not found: {$name}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read bundled prompt: {$name}");
        }

        return $content;
    }

    /**
     * Check which user prompts are outdated compared to current version.
     *
     * @return array<string, array{user: int, current: int}>
     */
    public function checkVersions(): array
    {
        $outdated = [];

        foreach (self::PROMPT_NAMES as $name) {
            $userPath = $this->getUserPromptPath($name);
            if (! file_exists($userPath)) {
                continue;
            }

            $content = file_get_contents($userPath);
            if ($content === false) {
                continue;
            }

            $userVersion = $this->parseVersion($content);
            if ($userVersion < self::CURRENT_VERSION) {
                $outdated[$name] = [
                    'user' => $userVersion,
                    'current' => self::CURRENT_VERSION,
                ];
            }
        }

        return $outdated;
    }

    /**
     * Write default prompts to the user's .fuel/prompts/ directory.
     *
     * Only writes files that don't already exist.
     */
    public function writeDefaultPrompts(): void
    {
        foreach (self::PROMPT_NAMES as $name) {
            $userPath = $this->getUserPromptPath($name);
            if (file_exists($userPath)) {
                continue;
            }

            $default = $this->getDefaultPrompt($name);
            file_put_contents($userPath, $default);
        }
    }

    /**
     * Write .new files for outdated prompts so users can diff/merge.
     *
     * @return array<string> Names of prompts that had .new files written
     */
    public function writeUpgradeFiles(): array
    {
        $written = [];
        $outdated = $this->checkVersions();

        foreach (array_keys($outdated) as $name) {
            $newPath = $this->getUserPromptPath($name).'.new';
            $default = $this->getDefaultPrompt($name);
            file_put_contents($newPath, $default);
            $written[] = $name;
        }

        return $written;
    }

    /**
     * Parse version from prompt content.
     *
     * Looks for: <fuel-prompt version="N" />
     */
    public function parseVersion(string $content): int
    {
        if (preg_match('/<fuel-prompt\s+version="(\d+)"\s*\/>/', $content, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Get all valid prompt names.
     *
     * @return array<string>
     */
    public function getPromptNames(): array
    {
        return self::PROMPT_NAMES;
    }

    /**
     * Get path to user's custom prompt file.
     */
    private function getUserPromptPath(string $name): string
    {
        return $this->context->getPromptsPath().'/'.$name.'.md';
    }

    /**
     * Get path to bundled default prompt file.
     */
    private function getBundledPromptPath(string $name): string
    {
        // In phar: use base_path which resolves correctly
        // In dev: use base_path which points to project root
        return base_path('resources/prompts/'.$name.'.md');
    }

    /**
     * Resolve a dotted variable path from the variables array.
     *
     * @param  array<string, mixed>  $variables
     */
    private function resolveVariable(string $key, array $variables): mixed
    {
        $parts = explode('.', $key);
        $value = $variables;

        foreach ($parts as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } else {
                return '';
            }
        }

        return $value;
    }
}
