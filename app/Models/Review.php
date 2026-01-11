<?php

declare(strict_types=1);

namespace App\Models;

class Review extends Model
{
    /**
     * Create a Review instance from an array of data.
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Parse and return the issues array from JSON.
     * This is the key method - centralizes JSON parsing that was scattered everywhere.
     *
     * @return array<int, string> Array of issue strings, empty array if no issues
     */
    public function issues(): array
    {
        $issuesJson = $this->attributes['issues'] ?? null;

        // Handle null or empty string
        if ($issuesJson === null || $issuesJson === '') {
            return [];
        }

        // Handle already-decoded arrays (defensive)
        if (is_array($issuesJson)) {
            return $issuesJson;
        }

        // Decode JSON
        $decoded = json_decode($issuesJson, true);

        // Handle invalid JSON, decode errors, or non-array results
        if (! is_array($decoded)) {
            return [];
        }

        // Only accept indexed arrays (not associative arrays/objects)
        // Issues should be a list of strings, not a key-value object
        if (array_keys($decoded) !== range(0, count($decoded) - 1)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Check if the review is pending.
     */
    public function isPending(): bool
    {
        return $this->attributes['status'] === 'pending';
    }

    /**
     * Check if the review is completed.
     */
    public function isCompleted(): bool
    {
        return $this->attributes['status'] === 'completed';
    }

    /**
     * Check if the review passed.
     * A review passes if it has no issues.
     */
    public function hasPassed(): bool
    {
        return count($this->issues()) === 0;
    }

    /**
     * Check if the review failed.
     * A review fails if it's completed AND has issues.
     */
    public function hasFailed(): bool
    {
        return $this->isCompleted() && count($this->issues()) > 0;
    }

    /**
     * Get the count of issues.
     */
    public function getIssueCount(): int
    {
        return count($this->issues());
    }
}
