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
        'consumed_exit_code',
        'consumed_output',
        'consume_pid',
        'last_review_issues',
    ];

    protected $casts = [
        'status' => TaskStatus::class,
        'labels' => 'array',
        'blocked_by' => 'array',
        'priority' => 'integer',
        'consumed' => 'boolean',
        'consumed_exit_code' => 'integer',
        'consume_pid' => 'integer',
    ];

    /** @var bool Flag to bypass casts/accessors for fromArray compatibility */
    private bool $bypassCasts = false;

    // Hide the integer primary key 'id' from array/JSON output
    protected $hidden = ['id'];

    /**
     * Backward compatibility: Create a Task instance from an array.
     * This method exists for compatibility with TaskService until it's refactored.
     * Creates a hydrated model instance without database interaction.
     *
     * @param  array<string, mixed>  $data
     *
     * @deprecated Use Task::create() or new Task() with fill() instead
     */
    public static function fromArray(array $data): self
    {
        // Create instance without initializing connection
        $task = new self;
        $task->exists = true; // Mark as existing to prevent save() from inserting
        $task->bypassCasts = true; // Disable casts for compatibility

        // Directly set attributes array to bypass casts and accessors
        // This is safe because TaskService provides data in the expected format
        $task->attributes = $data;
        $task->original = $data;

        return $task;
    }

    /**
     * Override getAttribute to bypass casts when in compatibility mode.
     * This prevents database connection errors when using fromArray().
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        if (property_exists($this, 'bypassCasts') && $this->bypassCasts && isset($this->attributes[$key])) {
            return $this->attributes[$key];
        }

        return parent::getAttribute($key);
    }

    /**
     * Scope tasks that are ready to work.
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query
            ->where('status', TaskStatus::Open)
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
            ->where('status', TaskStatus::Open)
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
        return $query->where('status', TaskStatus::Someday);
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
        $blockedBy = $this->blocked_by;

        if (empty($blockedBy)) {
            $blockedBy = $this->getRawOriginal('blocked_by');
        }

        return ! empty($blockedBy);
    }

    /**
     * Check if the task is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
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
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if the task is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Get labels as an array.
     */
    public function getLabelsArray(): array
    {
        $labels = $this->labels;

        if (empty($labels)) {
            $labels = $this->getRawOriginal('labels');
        }

        if (empty($labels)) {
            return [];
        }

        if (is_array($labels)) {
            return $labels;
        }

        return array_map(trim(...), explode(',', (string) $labels));
    }

    /**
     * Get blocked_by task IDs as an array.
     */
    public function getBlockedByArray(): array
    {
        $blockedBy = $this->blocked_by;

        if (empty($blockedBy)) {
            $blockedBy = $this->getRawOriginal('blocked_by');
        }

        if (empty($blockedBy)) {
            return [];
        }

        if (is_array($blockedBy)) {
            return $blockedBy;
        }

        return array_map(trim(...), explode(',', (string) $blockedBy));
    }

    /**
     * Accessor: Map 'id' to 'short_id' for backward compatibility.
     * This allows $task->id to return the short_id (f-xxxxxx) instead of the database primary key.
     */
    public function getIdAttribute($value): ?string
    {
        return $this->attributes['short_id'] ?? null;
    }
}
