<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $table = 'reviews';

    public $timestamps = false; // Uses started_at/completed_at instead

    protected $fillable = [
        'short_id', 'task_id', 'agent', 'status', 'issues',
        'started_at', 'completed_at', 'run_id',
    ];

    protected $casts = [
        'issues' => 'array',
        'run_id' => 'integer',
        'task_id' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the task that this review belongs to.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the run that this review belongs to.
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * Parse and return the issues array from JSON.
     * This is the key method - centralizes JSON parsing that was scattered everywhere.
     *
     * @return array<int, string> Array of issue strings, empty array if no issues
     */
    public function issues(): array
    {
        // With Eloquent's array casting, get the attribute directly to avoid recursion
        $issuesArray = $this->getAttribute('issues');

        // Handle null or empty
        if (in_array($issuesArray, [null, '', []], true)) {
            return [];
        }

        // Already an array from Eloquent casting
        if (! is_array($issuesArray)) {
            return [];
        }

        // Only accept indexed arrays (not associative arrays/objects)
        // Issues should be a list of strings, not a key-value object
        if (array_keys($issuesArray) !== range(0, count($issuesArray) - 1)) {
            return [];
        }

        return $issuesArray;
    }

    /**
     * Check if the review is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the review is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the review passed.
     * A review passes if it has no issues.
     */
    public function hasPassed(): bool
    {
        return $this->issues() === [];
    }

    /**
     * Check if the review failed.
     * A review fails if it's completed AND has issues.
     */
    public function hasFailed(): bool
    {
        return $this->isCompleted() && $this->issues() !== [];
    }

    /**
     * Get the count of issues.
     */
    public function getIssueCount(): int
    {
        return count($this->issues());
    }

    /**
     * Find a review by partial short_id (supports partial matching like r-abc).
     *
     * @param  string  $id  The review ID (full like r-xxxxxx or partial like r-abc or abc)
     * @return Review|null The review model or null if not found
     */
    public static function findByPartialId(string $id): ?self
    {
        // Check if it's a numeric ID (integer primary key)
        if (is_numeric($id)) {
            $review = static::find((int) $id);
            if ($review !== null) {
                return $review;
            }
        }

        // Normalize ID - add 'r-' prefix if not present
        $normalizedId = $id;
        if (! str_starts_with($normalizedId, 'r-')) {
            $normalizedId = 'r-'.$normalizedId;
        }

        // Exact match for full ID format (r-xxxxxx)
        if (strlen($normalizedId) === 8) {
            return static::where('short_id', $normalizedId)->first();
        }

        // Partial match
        $reviews = static::where('short_id', 'LIKE', $normalizedId.'%')->get();

        if ($reviews->count() === 1) {
            return $reviews->first();
        }

        if ($reviews->count() > 1) {
            // For partial matches, return the most recent one
            return $reviews->sortByDesc('started_at')->first();
        }

        return null;
    }
}
