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
     * Backward compatibility: Create a Review instance from an array.
     * Creates a hydrated model instance without database interaction.
     *
     * @param  array<string, mixed>  $data
     *
     * @deprecated Use Review::create() or new Review() with fill() instead
     */
    public static function fromArray(array $data): self
    {
        $review = new self;
        $review->exists = true;

        foreach ($data as $key => $value) {
            // Handle 'issues' field: Eloquent's array cast expects JSON strings
            // If an already-decoded array is passed, json_encode it first
            if ($key === 'issues' && is_array($value)) {
                $review->attributes[$key] = json_encode($value);
            } else {
                $review->attributes[$key] = $value;
            }
        }
        $review->original = $review->attributes;

        return $review;
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
        if ($issuesArray === null || $issuesArray === '' || $issuesArray === []) {
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
}
