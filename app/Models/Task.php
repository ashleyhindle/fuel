<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends EloquentModel
{
    protected $table = 'tasks';

    protected $fillable = [
        'short_id',
        'title',
        'description',
        'status',
        'type',
        'priority',
        'complexity',
        'labels',
        'blocked_by',
        'epic_id',
        'commit_hash',
        'reason',
        'consumed',
        'consumed_at',
        'consumed_output',
        'consume_pid',
        'last_review_issues',
    ];

    protected $casts = [
        'labels' => 'array',
        'blocked_by' => 'array',
        'priority' => 'integer',
        'status' => TaskStatus::class,
        'consumed' => 'boolean',
        'consume_pid' => 'integer',
        'last_review_issues' => 'array',
    ];

    // Hide the integer primary key 'id' from array/JSON output
    protected $hidden = ['id'];

    /**
     * Scope tasks that are ready to work.
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query
            ->where('status', TaskStatus::Open->value)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('blocked_by')
                    ->orWhere('blocked_by', '')
                    ->orWhere('blocked_by', '[]');
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('labels')
                    ->orWhere('labels', '')
                    ->orWhere('labels', '[]')
                    ->orWhere('labels', 'not like', '%needs-human%');
            });
    }

    /**
     * Scope tasks that are blocked by other tasks.
     */
    public function scopeBlocked(Builder $query): Builder
    {
        return $query
            ->where('status', TaskStatus::Open->value)
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('blocked_by')
                    ->where('blocked_by', '!=', '')
                    ->where('blocked_by', '!=', '[]');
            });
    }

    /**
     * Scope tasks that are in the backlog.
     */
    public function scopeBacklog(Builder $query): Builder
    {
        return $query->where('status', TaskStatus::Someday->value);
    }

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Epic::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Check if the task is blocked by other tasks.
     */
    public function isBlocked(): bool
    {
        $blockedBy = $this->getBlockedByArray();

        return $blockedBy !== [];
    }

    /**
     * Check if the task is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === TaskStatus::Done;
    }

    /**
     * Check if the task is in progress.
     */
    public function isInProgress(): bool
    {
        return $this->status === TaskStatus::InProgress;
    }

    /**
     * Check if the task is failed.
     * Note: 'failed' is not a valid TaskStatus for tasks.
     * This method exists for legacy compatibility but always returns false.
     */
    public function isFailed(): bool
    {
        // Tasks cannot have a 'failed' status - they can be cancelled but not failed
        return false;
    }

    /**
     * Check if the task is pending.
     * Note: 'pending' is not a valid TaskStatus for tasks.
     * Tasks use 'open' status instead of 'pending'.
     * This method exists for legacy compatibility but always returns false.
     */
    public function isPending(): bool
    {
        // Tasks use 'open' status, not 'pending'
        return false;
    }

    /**
     * Get labels as an array.
     */
    public function getLabelsArray(): array
    {
        return $this->normalizeArrayAttribute($this->labels);
    }

    /**
     * Get blocked_by task IDs as an array.
     */
    public function getBlockedByArray(): array
    {
        return $this->normalizeArrayAttribute($this->blocked_by);
    }

    /**
     * Normalize an array attribute that may be null, empty array, "[]" string, or actual array.
     */
    private function normalizeArrayAttribute(mixed $value): array
    {
        if (in_array($value, [null, '', '[]'], true)) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        // Handle JSON string
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            // Fallback: comma-separated string
            return array_filter(array_map(trim(...), explode(',', $value)));
        }

        return [];
    }

    /**
     * Find a task by partial ID matching.
     * Supports integer primary key ID, full short_id (f-xxxxxx), or partial short_id.
     *
     * @param  string  $id  Integer primary key, full short_id, or partial short_id
     *
     * @throws \RuntimeException When multiple tasks match the partial ID
     */
    public static function findByPartialId(string $id): ?self
    {
        // Check if it's a numeric ID (integer primary key)
        if (is_numeric($id)) {
            $task = static::find((int) $id);
            if ($task !== null) {
                return $task;
            }
        }

        // Exact match for full ID format (f-xxxxxx)
        if (str_starts_with($id, 'f-') && strlen($id) === 8) {
            return static::where('short_id', $id)->first();
        }

        // Partial match - try both with and without prefixes
        $tasks = static::where('short_id', 'like', $id.'%')
            ->orWhere('short_id', 'like', 'f-'.$id.'%')
            ->orWhere('short_id', 'like', 'fuel-'.$id.'%')
            ->get();

        if ($tasks->count() === 1) {
            return $tasks->first();
        }

        if ($tasks->count() > 1) {
            $ids = $tasks->pluck('short_id')->toArray();
            throw new \RuntimeException(
                sprintf("Ambiguous task ID '%s'. Matches: %s", $id, implode(', ', $ids))
            );
        }

        return null;
    }
}
