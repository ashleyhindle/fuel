<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Run extends Model
{
    protected $table = 'runs';

    public $timestamps = false; // Uses started_at/ended_at instead

    protected $fillable = [
        'short_id', 'task_id', 'agent', 'status', 'exit_code',
        'started_at', 'ended_at', 'duration_seconds', 'session_id',
        'error_type', 'model', 'output', 'cost_usd',
    ];

    protected $casts = [
        'exit_code' => 'integer',
        'duration_seconds' => 'integer',
        'cost_usd' => 'float',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    /**
     * Get the task that this run belongs to.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Virtual accessor for backward compatibility.
     * Maps 'run_id' to 'short_id'.
     */
    public function getRunIdAttribute(): ?string
    {
        return $this->short_id;
    }

    /**
     * Check if the run is currently running.
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if the run is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the run failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Format duration_seconds as '1h 23m 45s' or '23m 45s' or '45s'.
     */
    public function getDurationFormatted(): string
    {
        $seconds = $this->duration_seconds;
        if ($seconds === null) {
            return '';
        }

        $seconds = (int) $seconds;
        $parts = [];

        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;

        $minutes = intdiv($seconds, 60);
        $seconds %= 60;

        if ($hours > 0) {
            $parts[] = $hours.'h';
        }

        if ($minutes > 0) {
            $parts[] = $minutes.'m';
        }

        if ($seconds > 0 || $parts === []) {
            $parts[] = $seconds.'s';
        }

        return implode(' ', $parts);
    }

    /**
     * Split output into lines.
     *
     * @return array<int, string>
     */
    public function getOutputLines(): array
    {
        if ($this->output === null || $this->output === '') {
            return [];
        }

        return explode("\n", $this->output);
    }
}
