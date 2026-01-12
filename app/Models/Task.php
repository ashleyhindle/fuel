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
        'labels' => 'array',
        'blocked_by' => 'array',
        'priority' => 'integer',
        'consumed' => 'boolean',
        'consumed_exit_code' => 'integer',
        'consume_pid' => 'integer',
    ];

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
        return $this->status === TaskStatus::InProgress->value;
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
}
