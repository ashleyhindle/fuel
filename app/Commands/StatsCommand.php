<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\EpicService;
use App\Services\RunService;
use App\Services\TaskService;
use LaravelZero\Framework\Commands\Command;

class StatsCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'stats
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Display project statistics and metrics';

    public function handle(
        TaskService $taskService,
        RunService $runService,
        EpicService $epicService
    ): int {
        $this->configureCwd($taskService);

        $this->renderTaskStats($taskService);
        $this->line('');
        $this->renderEpicStats($epicService);
        $this->line('');
        $this->renderRunStats($runService);
        $this->line('');
        $this->renderActivityHeatmap($taskService, $runService);

        return self::SUCCESS;
    }

    /**
     * Render the task statistics section.
     */
    private function renderTaskStats(TaskService $taskService): void
    {
        $tasks = $taskService->all();
        $total = $tasks->count();

        // Aggregate by status
        $statusCounts = [
            'open' => 0,
            'in_progress' => 0,
            'closed' => 0,
            'blocked' => 0,
            'review' => 0,
            'cancelled' => 0,
        ];

        // Aggregate by complexity
        $complexityCounts = [
            'trivial' => 0,
            'simple' => 0,
            'moderate' => 0,
            'complex' => 0,
        ];

        // Aggregate by priority
        $priorityCounts = [
            'P0' => 0,
            'P1' => 0,
            'P2' => 0,
            'P3' => 0,
            'P4' => 0,
        ];

        // Aggregate by type
        $typeCounts = [
            'bug' => 0,
            'fix' => 0,
            'feature' => 0,
            'task' => 0,
            'epic' => 0,
            'chore' => 0,
            'docs' => 0,
            'test' => 0,
            'refactor' => 0,
        ];

        // Count blocked tasks
        $blockedIds = $taskService->getBlockedIds($tasks);

        foreach ($tasks as $task) {
            // Count by status
            $status = $task->status ?? 'open';
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }

            // Count blocked separately
            if (in_array($task->id, $blockedIds, true) && $status === 'open') {
                $statusCounts['blocked']++;
            }

            // Count by complexity
            $complexity = $task->complexity ?? 'simple';
            if (isset($complexityCounts[$complexity])) {
                $complexityCounts[$complexity]++;
            }

            // Count by priority
            $priority = 'P'.($task->priority ?? 2);
            if (isset($priorityCounts[$priority])) {
                $priorityCounts[$priority]++;
            }

            // Count by type
            $type = $task->type ?? 'task';
            if (isset($typeCounts[$type])) {
                $typeCounts[$type]++;
            }
        }

        // Display the statistics
        $this->line('┌─────────────────────────────────────────┐');
        $this->line('│ <fg=cyan>📋 TASK STATISTICS</>                      │');
        $this->line('├─────────────────────────────────────────┤');
        $this->line(sprintf('│ Total: <fg=yellow>%-31s</> │', $total));
        $this->line('│                                         │');
        $this->line('│ <fg=cyan>By Status:</>                              │');
        $this->line(sprintf(
            '│   <fg=green>✅ Closed: %d</>  <fg=yellow>🔄 In Progress: %d</>%-*s │',
            $statusCounts['closed'],
            $statusCounts['in_progress'],
            max(0, 17 - strlen((string) $statusCounts['closed']) - strlen((string) $statusCounts['in_progress'])),
            ''
        ));
        $this->line(sprintf(
            '│   <fg=blue>📋 Open: %d</>   <fg=red>⛔ Blocked: %d</>%-*s │',
            $statusCounts['open'],
            $statusCounts['blocked'],
            max(0, 21 - strlen((string) $statusCounts['open']) - strlen((string) $statusCounts['blocked'])),
            ''
        ));
        if ($statusCounts['review'] > 0 || $statusCounts['cancelled'] > 0) {
            $this->line(sprintf(
                '│   <fg=magenta>👀 Review: %d</>  <fg=gray>❌ Cancelled: %d</>%-*s │',
                $statusCounts['review'],
                $statusCounts['cancelled'],
                max(0, 18 - strlen((string) $statusCounts['review']) - strlen((string) $statusCounts['cancelled'])),
                ''
            ));
        }
        $this->line('│                                         │');
        $this->line('│ <fg=cyan>By Complexity:</>                          │');
        $this->line(sprintf(
            '│   trivial: %d  simple: %d  moderate: %d%-*s │',
            $complexityCounts['trivial'],
            $complexityCounts['simple'],
            $complexityCounts['moderate'],
            max(0, 9 - strlen((string) $complexityCounts['trivial']) - strlen((string) $complexityCounts['simple']) - strlen((string) $complexityCounts['moderate'])),
            ''
        ));
        $this->line(sprintf('│   complex: %-29s │', $complexityCounts['complex']));
        $this->line('│                                         │');
        $this->line('│ <fg=cyan>By Priority:</>                            │');
        $this->line(sprintf(
            '│   P0: %d  P1: %d  P2: %d  P3: %d  P4: %d%-*s │',
            $priorityCounts['P0'],
            $priorityCounts['P1'],
            $priorityCounts['P2'],
            $priorityCounts['P3'],
            $priorityCounts['P4'],
            max(0, 13 - strlen((string) $priorityCounts['P0']) - strlen((string) $priorityCounts['P1']) - strlen((string) $priorityCounts['P2']) - strlen((string) $priorityCounts['P3']) - strlen((string) $priorityCounts['P4'])),
            ''
        ));
        $this->line('│                                         │');
        $this->line('│ <fg=cyan>By Type:</>                                │');
        $this->line(sprintf(
            '│   bug: %d  fix: %d  feature: %d  task: %d%-*s │',
            $typeCounts['bug'],
            $typeCounts['fix'],
            $typeCounts['feature'],
            $typeCounts['task'],
            max(0, 9 - strlen((string) $typeCounts['bug']) - strlen((string) $typeCounts['fix']) - strlen((string) $typeCounts['feature']) - strlen((string) $typeCounts['task'])),
            ''
        ));
        $this->line(sprintf(
            '│   chore: %d  docs: %d  test: %d  refactor: %d%-*s │',
            $typeCounts['chore'],
            $typeCounts['docs'],
            $typeCounts['test'],
            $typeCounts['refactor'],
            max(0, 3 - strlen((string) $typeCounts['chore']) - strlen((string) $typeCounts['docs']) - strlen((string) $typeCounts['test']) - strlen((string) $typeCounts['refactor'])),
            ''
        ));
        if ($typeCounts['epic'] > 0) {
            $this->line(sprintf('│   epic: %-32s │', $typeCounts['epic']));
        }
        $this->line('└─────────────────────────────────────────┘');
    }

    private function renderEpicStats(EpicService $epicService): void
    {
        $epics = $epicService->getAllEpics();

        $total = count($epics);
        $planning = 0;
        $inProgress = 0;
        $done = 0;

        foreach ($epics as $epic) {
            match ($epic->status) {
                'planning' => $planning++,
                'in_progress' => $inProgress++,
                'approved' => $done++,
                default => null,
            };
        }

        $this->line('┌─────────────────────────────────────────┐');
        $this->line('│ 📦 EPICS                                │');
        $this->line('├─────────────────────────────────────────┤');
        $this->line(sprintf('│ Total: %-33d│', $total));
        $this->line(sprintf('│   📋 Planning: %d  🔄 In Progress: %-4d│', $planning, $inProgress));
        $this->line(sprintf('│   ✅ Done: %-29d│', $done));
        $this->line('└─────────────────────────────────────────┘');
    }

    private function renderRunStats(RunService $runService): void
    {
        $stats = $runService->getStats();

        $this->line('┌─────────────────────────────────────────┐');
        $this->line('│ 🤖 AGENT RUNS                           │');
        $this->line('├─────────────────────────────────────────┤');
        $this->line(sprintf('│ Total Runs: %-28d│', $stats['total_runs']));

        // Status counts
        $completedCount = $stats['by_status']['completed'];
        $failedCount = $stats['by_status']['failed'];
        $runningCount = $stats['by_status']['running'];

        $statusLine = sprintf(
            '│   ✅ Completed: %d  ❌ Failed: %-8d│',
            $completedCount,
            $failedCount
        );
        $this->line($statusLine);

        $this->line(sprintf('│   🔄 Running: %-27d│', $runningCount));
        $this->line('│                                         │');

        // Top Agents
        $this->line('│ Top Agents:                             │');
        if (empty($stats['by_agent'])) {
            $this->line('│   (no agent data)                       │');
        } else {
            $agentRank = 1;
            foreach (array_slice($stats['by_agent'], 0, 3, true) as $agent => $count) {
                $agentLine = sprintf('│   %d. %s (%d runs)', $agentRank, $agent, $count);
                // Pad to 41 chars total (39 content + 2 border)
                $padding = 41 - mb_strlen($agentLine) - 1;
                $this->line($agentLine.str_repeat(' ', $padding).'│');
                $agentRank++;
            }
        }

        $this->line('│                                         │');

        // Top Models
        $this->line('│ Top Models:                             │');
        if (empty($stats['by_model'])) {
            $this->line('│   (no model data)                       │');
        } else {
            $modelRank = 1;
            foreach (array_slice($stats['by_model'], 0, 3, true) as $model => $count) {
                $modelLine = sprintf('│   %d. %s (%d)', $modelRank, $model, $count);
                // Pad to 41 chars total
                $padding = 41 - mb_strlen($modelLine) - 1;
                $this->line($modelLine.str_repeat(' ', $padding).'│');
                $modelRank++;
            }
        }

        $this->line('└─────────────────────────────────────────┘');
    }

    private function renderActivityHeatmap(TaskService $taskService, RunService $runService): void
    {
        // TODO: Implement activity heatmap (assigned to another agent)
    }
}
