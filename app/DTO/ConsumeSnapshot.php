<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\EpicStatus;
use App\Models\Epic;
use App\Process\AgentHealth;
use App\Process\AgentProcess;
use Illuminate\Support\Collection;
use JsonSerializable;

/**
 * Data Transfer Object for consume snapshot state.
 *
 * Represents the full state of the consume runner at a point in time,
 * including task board state, active processes, agent health, and configuration.
 */
final readonly class ConsumeSnapshot implements JsonSerializable
{
    /**
     * @param  array{ready: Collection, in_progress: Collection, review: Collection, blocked: Collection, human: Collection, done: Collection}  $boardState  Task board state by status
     * @param  array<string, array{task_id: string, run_id: string|null, agent: string, pid: int|null, started_at: int, last_output_time: int|null}>  $activeProcesses  Currently running processes keyed by task_id
     * @param  array<string, array{status: string, consecutive_failures: int, in_backoff: bool, is_dead: bool, backoff_seconds: int}>  $healthSummary  Per-agent health status
     * @param  array{paused: bool, started_at: int|null, instance_id: string}  $runnerState  Runner process state
     * @param  array{interval_seconds: int, agents: array<string, array{max_concurrent: int}>}  $config  Runtime configuration
     * @param  array<string, array{short_id: string, title: string, status: string}>  $epics  Epic data keyed by short_id
     * @param  int  $doneCount  Count of done tasks (for footer display, tasks lazy-loaded on demand)
     * @param  int  $blockedCount  Count of blocked tasks (for footer display, tasks lazy-loaded on demand)
     * @param  array{running: bool, healthy: bool}  $browserDaemon  Browser daemon status
     */
    public function __construct(
        public array $boardState,
        public array $activeProcesses,
        public array $healthSummary,
        public array $runnerState,
        public array $config,
        public array $epics = [],
        public int $doneCount = 0,
        public int $blockedCount = 0,
        public array $browserDaemon = ['running' => false, 'healthy' => false],
    ) {}

    /**
     * Create a snapshot from board data and runtime state.
     *
     * @param  array{ready: Collection, in_progress: Collection, review: Collection, blocked: Collection, human: Collection, done: Collection}  $boardData  Board state from getBoardData()
     * @param  array<AgentProcess>  $activeProcesses  Active agent processes
     * @param  array<AgentHealth>  $healthStatuses  Agent health statuses
     * @param  bool  $paused  Whether runner is paused
     * @param  int|null  $startedAt  Runner start timestamp
     * @param  string  $instanceId  Runner instance identifier
     * @param  int  $intervalSeconds  Check interval in seconds
     * @param  array<string, int>  $agentLimits  Max concurrent per agent
     * @param  array<Epic>  $epics  Epic models to include
     * @param  int  $doneCount  Count of done tasks for footer display
     * @param  int  $blockedCount  Count of blocked tasks for footer display
     * @param  array{running: bool, healthy: bool}  $browserDaemon  Browser daemon status
     */
    public static function fromBoardData(
        array $boardData,
        array $activeProcesses,
        array $healthStatuses,
        bool $paused,
        ?int $startedAt,
        string $instanceId,
        int $intervalSeconds,
        array $agentLimits,
        array $epics = [],
        int $doneCount = 0,
        int $blockedCount = 0,
        array $browserDaemon = ['running' => false, 'healthy' => false],
    ): self {
        // Convert active processes to serializable format (keyed by task_id)
        $processesData = [];
        foreach ($activeProcesses as $process) {
            $processesData[$process->getTaskId()] = [
                'task_id' => $process->getTaskId(),
                'run_id' => $process->getRunId(),
                'agent' => $process->getAgentName(),
                'pid' => $process->getPid(),
                'started_at' => $process->getStartTime(),
                'last_output_time' => $process->getLastOutputTime(),
            ];
        }

        // Convert health statuses to serializable format
        $healthData = [];
        foreach ($healthStatuses as $health) {
            $healthData[$health->agent] = [
                'status' => $health->getStatus(),
                'consecutive_failures' => $health->consecutiveFailures,
                'in_backoff' => ! $health->isAvailable(),
                'is_dead' => $health->consecutiveFailures >= 5, // Default threshold
                'backoff_seconds' => $health->getBackoffSeconds(),
            ];
        }

        // Convert agent limits to config format
        $agentsConfig = [];
        foreach ($agentLimits as $agent => $limit) {
            $agentsConfig[$agent] = [
                'max_concurrent' => $limit,
            ];
        }

        // Convert epics to serializable format (keyed by short_id for easy lookup)
        // Use computed_status to get the dynamically calculated status from task states
        $epicsData = [];
        foreach ($epics as $epic) {
            $status = $epic->computed_status;
            $epicsData[$epic->short_id] = [
                'short_id' => $epic->short_id,
                'title' => $epic->title,
                'status' => $status instanceof EpicStatus
                    ? $status->value
                    : (string) $status,
            ];
        }

        return new self(
            boardState: $boardData,
            activeProcesses: $processesData,
            healthSummary: $healthData,
            runnerState: [
                'paused' => $paused,
                'started_at' => $startedAt,
                'instance_id' => $instanceId,
            ],
            config: [
                'interval_seconds' => $intervalSeconds,
                'agents' => $agentsConfig,
            ],
            epics: $epicsData,
            doneCount: $doneCount,
            blockedCount: $blockedCount,
            browserDaemon: $browserDaemon,
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'board_state' => [
                'ready' => $this->serializeTaskCollection($this->boardState['ready']),
                'in_progress' => $this->serializeTaskCollection($this->boardState['in_progress']),
                'review' => $this->serializeTaskCollection($this->boardState['review']),
                'blocked' => $this->serializeTaskCollection($this->boardState['blocked']),
                'human' => $this->serializeTaskCollection($this->boardState['human']),
                'done' => $this->serializeTaskCollection($this->boardState['done']),
            ],
            'active_processes' => $this->activeProcesses,
            'health_summary' => $this->healthSummary,
            'runner_state' => $this->runnerState,
            'config' => $this->config,
            'epics' => $this->epics,
            'done_count' => $this->doneCount,
            'blocked_count' => $this->blockedCount,
            'browser_daemon' => $this->browserDaemon,
        ];
    }

    /**
     * Serialize to JSON.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert a Collection of tasks to an array format.
     *
     * Uses attributesToArray() for Eloquent models to avoid serializing
     * loaded relationships (like epic) which would fail hydration on the client.
     * Adds epic_short_id as a flat attribute for display purposes.
     *
     * @return array<array<string, mixed>>
     */
    private function serializeTaskCollection(Collection $tasks): array
    {
        return $tasks->map(function ($task) {
            // Support both Task models and arrays
            if (is_array($task)) {
                return $task;
            }

            // If it's an Eloquent model, use attributesToArray() to exclude relationships
            if (method_exists($task, 'attributesToArray')) {
                $data = $task->attributesToArray();

                // Include epic_short_id if epic relationship is loaded (for display)
                if (method_exists($task, 'relationLoaded') && $task->relationLoaded('epic') && $task->epic) {
                    $data['epic_short_id'] = $task->epic->short_id;
                }

                return $data;
            }

            // Fallback for other objects with toArray
            if (method_exists($task, 'toArray')) {
                return $task->toArray();
            }

            // Fallback: cast to array
            return (array) $task;
        })->toArray();
    }
}
