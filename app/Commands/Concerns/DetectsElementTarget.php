<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

/**
 * Provides auto-detection of element refs vs CSS selectors.
 *
 * Refs always start with @ (e.g., @e1, @e2, @e123)
 * Anything else is treated as a CSS selector.
 */
trait DetectsElementTarget
{
    /**
     * Check if a target string is an element ref (starts with @)
     */
    protected function isRef(string $target): bool
    {
        return str_starts_with($target, '@');
    }

    /**
     * Parse a target string into selector and ref components.
     *
     * @return array{selector: string|null, ref: string|null}
     */
    protected function parseTarget(string $target): array
    {
        if ($this->isRef($target)) {
            return ['selector' => null, 'ref' => $target];
        }

        return ['selector' => $target, 'ref' => null];
    }
}
