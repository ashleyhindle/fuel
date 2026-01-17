<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EpicStatus;
use App\Enums\TaskStatus;
use App\Services\FuelContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Epic extends Model
{
    protected $table = 'epics';

    // Disable automatic timestamp management - EpicService handles timestamps manually
    public $timestamps = true;

    // Use custom timestamp column names (Eloquent expects created_at/updated_at)
    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    // Hide the integer primary key 'id' from array/JSON output
    protected $hidden = ['id'];

    protected $fillable = [
        'short_id',
        'title',
        'description',
        'self_guided',
        'status',
        'plan_filename',
        'paused_at',
        'reviewed_at',
        'approved_at',
        'approved_by',
        'changes_requested_at',
        'mirror_path',
        'mirror_status',
        'mirror_branch',
        'mirror_base_commit',
        'mirror_created_at',
    ];

    protected $casts = [
        'status' => EpicStatus::class,
        'mirror_status' => \App\Enums\MirrorStatus::class,
        'self_guided' => 'boolean',
        'paused_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'changes_requested_at' => 'datetime',
        'mirror_created_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Short ID accessor - provides public 'id' attribute mapped to 'short_id'.
     * This maintains backward compatibility where 'id' refers to the short_id (e-xxxxxx).
     * The database 'id' (integer primary key) is hidden from the public interface.
     */
    protected function getShortIdForPublicId(): ?string
    {
        return $this->attributes['short_id'] ?? null;
    }

    /**
     * Relationship: Epic has many Tasks.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Computed status accessor - status is derived from tasks and review state.
     * This mirrors the logic from EpicService::getEpicStatus().
     *
     * For backward compatibility with EpicService, if 'status' attribute exists,
     * use it directly. Otherwise, compute from tasks (future Eloquent usage).
     */
    public function getComputedStatusAttribute(): string
    {
        // Backward compatibility: if status was set directly (from EpicService), use it
        if (isset($this->attributes['status']) && $this->attributes['status'] !== null) {
            return $this->attributes['status'];
        }

        // Future Eloquent usage: compute status from tasks and review state
        // Check paused status first
        if ($this->paused_at !== null) {
            return EpicStatus::Paused;
        }

        // Check approval/rejection status first (these override computed status)
        if ($this->approved_at !== null) {
            return EpicStatus::Approved;
        }

        if ($this->changes_requested_at !== null) {
            // If changes were requested, check if tasks are back in progress
            $hasActiveTask = $this->tasks()
                ->whereIn('status', [TaskStatus::Open->value, TaskStatus::InProgress->value])
                ->exists();

            // If tasks are active again, it's in_progress; otherwise still changes_requested
            return $hasActiveTask ? EpicStatus::InProgress : EpicStatus::ChangesRequested;
        }

        // If reviewed_at is set but not approved, epic is reviewed (but not approved)
        if ($this->reviewed_at !== null) {
            return EpicStatus::Reviewed;
        }

        // Check task states
        $tasksCount = $this->tasks()->count();

        // If no tasks, epic is in planning
        if ($tasksCount === 0) {
            return EpicStatus::Planning;
        }

        // Check if any task is open or in_progress
        $hasActiveTask = $this->tasks()
            ->whereIn('status', [TaskStatus::Open->value, TaskStatus::InProgress->value])
            ->exists();

        if ($hasActiveTask) {
            return EpicStatus::InProgress;
        }

        // Check if all tasks are done
        $allDone = $this->tasks()
            ->where('status', TaskStatus::Done->value)
            ->count() === $tasksCount;

        if ($allDone) {
            return EpicStatus::ReviewPending;
        }

        // Fallback: if tasks exist but not all done and none active, still in_progress
        return EpicStatus::InProgress;
    }

    /**
     * Get the status as a string value for comparison.
     */
    private function getStatusValue(): string
    {
        $status = $this->computed_status;

        return $status instanceof EpicStatus ? $status->value : (string) $status;
    }

    /**
     * Check if the epic is in planning status.
     */
    public function isPlanning(): bool
    {
        return $this->getStatusValue() === EpicStatus::Planning->value;
    }

    /**
     * Check if the epic is approved (terminal state).
     */
    public function isApproved(): bool
    {
        return $this->getStatusValue() === EpicStatus::Approved->value;
    }

    /**
     * Check if the epic has been reviewed.
     */
    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null && $this->reviewed_at !== '';
    }

    /**
     * Check if the epic is in planning or in_progress status.
     */
    public function isPlanningOrInProgress(): bool
    {
        return in_array($this->getStatusValue(), [
            EpicStatus::Planning->value,
            EpicStatus::InProgress->value,
        ], true);
    }

    /**
     * Find an epic by partial ID matching.
     * Supports integer primary key ID, full short_id (e-xxxxxx), or partial short_id.
     *
     * @param  string  $id  Integer primary key, full short_id, or partial short_id
     * @return static|null The epic instance or null if not found
     *
     * @throws \RuntimeException When multiple epics match the partial ID
     */
    public static function findByPartialId(string $id): ?self
    {
        // Check if it's a numeric ID (integer primary key)
        if (is_numeric($id)) {
            $epic = static::find((int) $id);
            if ($epic !== null) {
                return $epic;
            }
        }

        // Exact match for full ID format (e-xxxxxx)
        if (str_starts_with($id, 'e-') && strlen($id) === 8) {
            return static::where('short_id', $id)->first();
        }

        // Partial match - try both with and without 'e-' prefix
        $epics = static::where('short_id', 'LIKE', $id.'%')
            ->orWhere('short_id', 'LIKE', 'e-'.$id.'%')
            ->get();

        if ($epics->count() === 1) {
            return $epics->first();
        }

        if ($epics->count() > 1) {
            $ids = $epics->pluck('short_id')->toArray();
            throw new \RuntimeException(
                sprintf("Ambiguous epic ID '%s'. Matches: %s", $id, implode(', ', $ids))
            );
        }

        return null;
    }

    /**
     * Get the plan filename for this epic.
     * Uses stored filename if available, otherwise generates it.
     * For old epics without stored filename, tries to find existing file.
     */
    public function getPlanFilename(): string
    {
        // Use stored filename if available
        if (! empty($this->plan_filename)) {
            return $this->plan_filename;
        }

        // For old epics, try to find existing plan file
        $context = app(FuelContext::class);
        $plansDir = $context->getPlansPath();

        // Try slug format first (new style)
        $slugFilename = Str::slug($this->title).'-'.$this->short_id.'.md';
        if (file_exists($plansDir.'/'.$slugFilename)) {
            return $slugFilename;
        }

        // Try kebab format (old style)
        $kebabFilename = Str::kebab($this->title).'-'.$this->short_id.'.md';
        if (file_exists($plansDir.'/'.$kebabFilename)) {
            return $kebabFilename;
        }

        // Default to new slug format
        return $slugFilename;
    }

    /**
     * Get the full plan path for this epic.
     */
    public function getPlanPath(): string
    {
        return '.fuel/plans/'.$this->getPlanFilename();
    }

    /**
     * Generate a plan filename for a title and epic ID.
     * Use this when creating new epics.
     */
    public static function generatePlanFilename(string $title, string $epicId): string
    {
        return Str::slug($title).'-'.$epicId.'.md';
    }

    /**
     * Get epics pending review (has tasks, all tasks done, not yet reviewed).
     */
    public static function pendingReview(): Collection
    {
        return static::whereNull('reviewed_at')
            ->whereHas('tasks')
            ->whereDoesntHave('tasks', function ($query): void {
                $query->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value]);
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
