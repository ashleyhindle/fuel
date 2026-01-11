<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\HandlesJsonOutput;
use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\RunService;
use App\Services\TaskService;
use App\Support\BoxRenderer;
use LaravelZero\Framework\Commands\Command;

/**
 * Display project statistics and metrics.
 *
 * Example BoxRenderer usage (refactor existing box rendering to use this):
 *
 * $renderer = new BoxRenderer($this->output);
 * $renderer->box('TASK STATISTICS', [
 *     'Total: 47',
 *     '',
 *     'By Status:',
 *     '  ✅ Done: 32  🔄 In Progress: 5',
 * ], '📋');
 */
class StatsCommand extends Command
{
    use HandlesJsonOutput;

    protected $signature = 'stats
        {--cwd= : Working directory (defaults to current directory)}';

    protected $description = 'Display project statistics and metrics';

    public function handle(
        TaskService $taskService,
        RunService $runService,
        EpicService $epicService,
        DatabaseService $databaseService
    ): int {
        $this->configureCwd($taskService);

        $this->renderTaskStats($taskService);
        $this->line('');
        $this->renderEpicStats($epicService);
        $this->line('');
        $this->renderRunStats($runService);
        $this->line('');
        $this->renderTimingStats($runService);
        $this->line('');
        $this->renderTrends($databaseService);
        $this->line('');
        $this->renderActivityHeatmap($databaseService);
        $this->line('');
        $this->renderStreaksAndAchievements($databaseService);

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

        // Build content lines
        $lines = [];
        $lines[] = sprintf('Total: <fg=yellow>%s</>', $total);
        $lines[] = '';
        $lines[] = '<fg=cyan>By Status:</>';
        $lines[] = sprintf(
            '  <fg=green>✅ Closed: %d</>  <fg=yellow>🔄 In Progress: %d</>',
            $statusCounts['closed'],
            $statusCounts['in_progress']
        );
        $lines[] = sprintf(
            '  <fg=blue>📋 Open: %d</>   <fg=red>⛔ Blocked: %d</>',
            $statusCounts['open'],
            $statusCounts['blocked']
        );
        if ($statusCounts['review'] > 0 || $statusCounts['cancelled'] > 0) {
            $lines[] = sprintf(
                '  <fg=magenta>👀 Review: %d</>  <fg=gray>❌ Cancelled: %d</>',
                $statusCounts['review'],
                $statusCounts['cancelled']
            );
        }
        $lines[] = '';
        $lines[] = '<fg=cyan>By Complexity:</>';
        $lines[] = sprintf(
            '  trivial: %d  simple: %d  moderate: %d',
            $complexityCounts['trivial'],
            $complexityCounts['simple'],
            $complexityCounts['moderate']
        );
        $lines[] = sprintf('  complex: %s', $complexityCounts['complex']);
        $lines[] = '';
        $lines[] = '<fg=cyan>By Priority:</>';
        $lines[] = sprintf(
            '  P0: %d  P1: %d  P2: %d  P3: %d  P4: %d',
            $priorityCounts['P0'],
            $priorityCounts['P1'],
            $priorityCounts['P2'],
            $priorityCounts['P3'],
            $priorityCounts['P4']
        );
        $lines[] = '';
        $lines[] = '<fg=cyan>By Type:</>';
        $lines[] = sprintf(
            '  bug: %d  fix: %d  feature: %d  task: %d',
            $typeCounts['bug'],
            $typeCounts['fix'],
            $typeCounts['feature'],
            $typeCounts['task']
        );
        $lines[] = sprintf(
            '  chore: %d  docs: %d  test: %d  refactor: %d',
            $typeCounts['chore'],
            $typeCounts['docs'],
            $typeCounts['test'],
            $typeCounts['refactor']
        );
        if ($typeCounts['epic'] > 0) {
            $lines[] = sprintf('  epic: %s', $typeCounts['epic']);
        }

        // Render with BoxRenderer
        $renderer = new BoxRenderer($this->output);
        $renderer->box('<fg=cyan>TASK STATISTICS</>', $lines, '📋', 43);
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

        $lines = [];
        $lines[] = sprintf('Total: %d', $total);
        $lines[] = sprintf('  📋 Planning: %d  🔄 In Progress: %d', $planning, $inProgress);
        $lines[] = sprintf('  ✅ Done: %d', $done);

        $renderer = new BoxRenderer($this->output);
        $renderer->box('EPICS', $lines, '📦', 43);
    }

    private function renderRunStats(RunService $runService): void
    {
        $stats = $runService->getStats();

        // Status counts
        $completedCount = $stats['by_status']['completed'];
        $failedCount = $stats['by_status']['failed'];
        $runningCount = $stats['by_status']['running'];

        $lines = [];
        $lines[] = sprintf('Total Runs: %d', $stats['total_runs']);
        $lines[] = sprintf('  ✅ Completed: %d  ❌ Failed: %d', $completedCount, $failedCount);
        $lines[] = sprintf('  🔄 Running: %d', $runningCount);
        $lines[] = '';

        // Top Agents
        $lines[] = 'Top Agents:';
        if (empty($stats['by_agent'])) {
            $lines[] = '  (no agent data)';
        } else {
            $agentRank = 1;
            foreach (array_slice($stats['by_agent'], 0, 3, true) as $agent => $count) {
                $lines[] = sprintf('  %d. %s (%d runs)', $agentRank, $agent, $count);
                $agentRank++;
            }
        }

        $lines[] = '';

        // Top Models
        $lines[] = 'Top Models:';
        if (empty($stats['by_model'])) {
            $lines[] = '  (no model data)';
        } else {
            $modelRank = 1;
            foreach (array_slice($stats['by_model'], 0, 3, true) as $model => $count) {
                $lines[] = sprintf('  %d. %s (%d)', $modelRank, $model, $count);
                $modelRank++;
            }
        }

        $renderer = new BoxRenderer($this->output);
        $renderer->box('AGENT RUNS', $lines, '🤖', 43);
    }

    private function renderTimingStats(RunService $runService): void
    {
        $stats = $runService->getTimingStats();
        $lines = [];

        // Average Run Duration
        if ($stats['average_duration'] !== null) {
            $avgFormatted = $this->formatDuration((int) round($stats['average_duration']));
            $lines[] = sprintf('Average Run Duration: %s', $avgFormatted);
        } else {
            $lines[] = 'Average Run Duration: (no data)';
        }

        // By Agent
        if (! empty($stats['by_agent'])) {
            $lines[] = '';
            $lines[] = 'By Agent:';
            foreach ($stats['by_agent'] as $agent => $avgDuration) {
                $formatted = $this->formatDuration((int) round($avgDuration));
                $lines[] = sprintf('  %s: %s', $agent, $formatted);
            }
        }

        // By Model
        if (! empty($stats['by_model'])) {
            $lines[] = '';
            $lines[] = 'By Model:';
            foreach ($stats['by_model'] as $model => $avgDuration) {
                $formatted = $this->formatDuration((int) round($avgDuration));
                $lines[] = sprintf('  %s: %s', $model, $formatted);
            }
        }

        // Longest and shortest runs
        if ($stats['longest_run'] !== null || $stats['shortest_run'] !== null) {
            $lines[] = '';
            if ($stats['longest_run'] !== null) {
                $longestFormatted = $this->formatDuration($stats['longest_run']);
                $lines[] = sprintf('Longest Run: %s', $longestFormatted);
            }
            if ($stats['shortest_run'] !== null) {
                $shortestFormatted = $this->formatDuration($stats['shortest_run']);
                $lines[] = sprintf('Shortest Run: %s', $shortestFormatted);
            }
        }

        $renderer = new BoxRenderer($this->output);
        $renderer->box('TIMING', $lines, '⏱️', 43);
    }

    /**
     * Format duration in seconds to human-readable format.
     *
     * @param  int  $seconds  Duration in seconds
     * @return string Formatted duration (e.g., "4m 32s", "1h 23m")
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%ds', $seconds);
        }

        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm %ds', $minutes, $secs);
    }

    private function renderTrends(DatabaseService $db): void
    {
        // Get last 14 days of task completions
        $taskQuery = "SELECT DATE(updated_at) as day, COUNT(*) as cnt
                      FROM tasks
                      WHERE status = 'closed'
                      AND updated_at >= date('now', '-14 days')
                      GROUP BY DATE(updated_at)
                      ORDER BY day ASC";
        $taskResults = $db->fetchAll($taskQuery);

        // Get last 14 days of runs
        $runQuery = "SELECT DATE(started_at) as day, COUNT(*) as cnt
                     FROM runs
                     WHERE started_at >= date('now', '-14 days')
                     GROUP BY DATE(started_at)
                     ORDER BY day ASC";
        $runResults = $db->fetchAll($runQuery);

        // Build arrays for each day (last 14 days)
        $taskValues = [];
        $runValues = [];
        $startDate = new \DateTime('-14 days');

        for ($i = 0; $i < 14; $i++) {
            $date = clone $startDate;
            $date->modify("+{$i} days");
            $dateStr = $date->format('Y-m-d');

            $taskValues[] = 0;
            $runValues[] = 0;

            foreach ($taskResults as $row) {
                if ($row['day'] === $dateStr) {
                    $taskValues[$i] = (int) $row['cnt'];
                    break;
                }
            }

            foreach ($runResults as $row) {
                if ($row['day'] === $dateStr) {
                    $runValues[$i] = (int) $row['cnt'];
                    break;
                }
            }
        }

        // Calculate totals
        $totalTasks = array_sum($taskValues);
        $totalRuns = array_sum($runValues);

        // Generate sparklines
        $taskSparkline = $this->sparkline($taskValues);
        $runSparkline = $this->sparkline($runValues);

        // Render box
        $renderer = new BoxRenderer($this->output);
        $lines = [
            sprintf('Tasks:  %s  (%d total)', $taskSparkline, $totalTasks),
            sprintf('Runs:   %s  (%d total)', $runSparkline, $totalRuns),
        ];
        $renderer->box('TRENDS (14 days)', $lines, '📈', 43);
    }

    /**
     * Generate a sparkline from an array of values.
     */
    private function sparkline(array $values): string
    {
        $chars = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
        $max = max($values) ?: 1;

        return implode('', array_map(
            fn ($v) => $chars[(int) floor(($v / $max) * 7)],
            $values
        ));
    }

    private function renderActivityHeatmap(DatabaseService $db): void
    {
        $activityData = $this->getActivityByDay($db);

        $renderer = new BoxRenderer($this->output);

        // Build heatmap content
        $heatmapLines = $this->buildHeatmapLines($activityData);

        // Combine all lines
        $lines = array_merge($heatmapLines, [
            '',
            'Legend: ░ none  ▒ low  ▓ medium  █ high',
        ]);

        $renderer->box('ACTIVITY (last 8 weeks)', $lines, '📊', 43);
    }

    private function getActivityByDay(DatabaseService $db): array
    {
        // Get tasks completed per day
        $taskQuery = "SELECT DATE(updated_at) as day, COUNT(*) as cnt
                      FROM tasks
                      WHERE status = 'done'
                      AND updated_at >= date('now', '-56 days')
                      GROUP BY DATE(updated_at)";
        $taskResults = $db->fetchAll($taskQuery);

        // Get runs per day
        $runQuery = "SELECT DATE(started_at) as day, COUNT(*) as cnt
                     FROM runs
                     WHERE started_at >= date('now', '-56 days')
                     GROUP BY DATE(started_at)";
        $runResults = $db->fetchAll($runQuery);

        // Merge data
        $activity = [];
        foreach ($taskResults as $row) {
            $activity[$row['day']] = ($activity[$row['day']] ?? 0) + $row['cnt'];
        }
        foreach ($runResults as $row) {
            $activity[$row['day']] = ($activity[$row['day']] ?? 0) + $row['cnt'];
        }

        return $activity;
    }

    private function buildHeatmapLines(array $data): array
    {
        $maxCount = max(array_merge([1], array_values($data)));

        // Build a 2D grid: [day_of_week][week_index] = count
        $grid = array_fill(0, 7, array_fill(0, 8, 0));

        // Start from 56 days ago (8 weeks)
        $startDate = new \DateTime('-56 days');
        $today = new \DateTime;

        for ($i = 0; $i < 56; $i++) {
            $date = clone $startDate;
            $date->modify("+{$i} days");

            if ($date > $today) {
                break;
            }

            // N format: 1=Mon, 2=Tue, ..., 7=Sun
            // Convert to: 0=Sun, 1=Mon, ..., 6=Sat
            $dayOfWeek = (int) $date->format('N') % 7; // 7 % 7 = 0 (Sun), 1 % 7 = 1 (Mon), etc.
            $weekIndex = (int) floor($i / 7);

            $dateStr = $date->format('Y-m-d');
            $grid[$dayOfWeek][$weekIndex] = $data[$dateStr] ?? 0;
        }

        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $lines = [];

        foreach ($grid as $dayIdx => $weeks) {
            $line = str_pad($days[$dayIdx], 4).' ';

            foreach ($weeks as $count) {
                $color = $this->getHeatmapColor($count, $maxCount);
                $line .= $color.'█'."\e[0m";
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function getHeatmapColor(int $count, int $max): string
    {
        if ($count === 0) {
            // Level 0: #161b22 (dark gray)
            return "\e[38;2;22;27;34m";
        }

        $percentage = $count / $max;

        if ($percentage <= 0.25) {
            // Level 1: #0e4429 (dark green)
            return "\e[38;2;14;68;41m";
        } elseif ($percentage <= 0.50) {
            // Level 2: #006d32 (medium green)
            return "\e[38;2;0;109;50m";
        } elseif ($percentage <= 0.75) {
            // Level 3: #26a641 (bright green)
            return "\e[38;2;38;166;65m";
        } else {
            // Level 4: #39d353 (neon green)
            return "\e[38;2;57;211;83m";
        }
    }

    private function renderStreaksAndAchievements(DatabaseService $db): void
    {
        $currentStreak = $this->calculateStreak($db);
        $longestStreak = $this->getLongestStreak($db);
        $earnedBadges = $this->getEarnedBadges($db);

        // Get task counts
        $today = $this->getTasksCompletedToday($db);
        $thisWeek = $this->getTasksCompletedThisWeek($db);
        $thisMonth = $this->getTasksCompletedThisMonth($db);
        $allTime = $this->getTasksCompletedAllTime($db);

        $lines = [];
        $lines[] = sprintf('🔥 Current Streak: <fg=yellow>%d days</>', $currentStreak);
        $lines[] = sprintf('🏆 Longest Streak: <fg=yellow>%d days</>', $longestStreak);
        $lines[] = '';
        $lines[] = sprintf('Today: %d ✅  This Week: %d ✅', $today, $thisWeek);
        $lines[] = sprintf('This Month: %d ✅  All Time: %d ✅', $thisMonth, $allTime);
        $lines[] = '';
        $lines[] = '<fg=cyan>🎖️  Badges:</>';

        if (empty($earnedBadges)) {
            $lines[] = '  <fg=gray>(no badges earned yet)</>';
        } else {
            foreach ($earnedBadges as $badge) {
                $lines[] = sprintf('  %s %s', $badge['emoji'], $badge['name']);
            }
        }

        $renderer = new BoxRenderer($this->output);
        $renderer->box('<fg=yellow>STREAKS & ACHIEVEMENTS</>', $lines, '🔥', 43);
    }

    /**
     * Calculate the current streak (consecutive days with at least 1 task completed).
     */
    private function calculateStreak(DatabaseService $db): int
    {
        $query = "SELECT DATE(updated_at) as day
                  FROM tasks
                  WHERE status = 'closed'
                  ORDER BY day DESC";
        $results = $db->fetchAll($query);

        if (empty($results)) {
            return 0;
        }

        $streak = 0;
        $today = new \DateTime;
        $today->setTime(0, 0, 0);
        $checkDate = clone $today;

        // Group dates to get unique days
        $uniqueDays = array_unique(array_column($results, 'day'));

        foreach ($uniqueDays as $dayStr) {
            $taskDate = new \DateTime($dayStr);
            $taskDate->setTime(0, 0, 0);

            // Check if this date matches the expected streak date
            if ($taskDate->format('Y-m-d') === $checkDate->format('Y-m-d')) {
                $streak++;
                $checkDate->modify('-1 day');
            } elseif ($taskDate < $checkDate) {
                // Gap found, streak is broken
                break;
            }
        }

        return $streak;
    }

    /**
     * Get the longest streak ever recorded.
     */
    private function getLongestStreak(DatabaseService $db): int
    {
        $query = "SELECT DATE(updated_at) as day
                  FROM tasks
                  WHERE status = 'closed'
                  ORDER BY day ASC";
        $results = $db->fetchAll($query);

        if (empty($results)) {
            return 0;
        }

        $uniqueDays = array_unique(array_column($results, 'day'));
        $maxStreak = 0;
        $currentStreak = 0;
        $previousDate = null;

        foreach ($uniqueDays as $dayStr) {
            $currentDate = new \DateTime($dayStr);

            if ($previousDate === null) {
                $currentStreak = 1;
            } else {
                $diff = $previousDate->diff($currentDate);
                if ($diff->days === 1) {
                    // Consecutive day
                    $currentStreak++;
                } else {
                    // Gap found, reset streak
                    $maxStreak = max($maxStreak, $currentStreak);
                    $currentStreak = 1;
                }
            }

            $previousDate = $currentDate;
        }

        return max($maxStreak, $currentStreak);
    }

    /**
     * Get earned badges based on achievements.
     */
    private function getEarnedBadges(DatabaseService $db): array
    {
        $badges = [];

        // Century Club: 100+ total tasks completed
        $totalCompleted = $this->getTasksCompletedAllTime($db);
        if ($totalCompleted >= 100) {
            $badges[] = [
                'emoji' => '<fg=yellow>🌟</>',
                'name' => '<fg=yellow>Century Club (100+ tasks)</>',
            ];
        }

        // Speed Demon: 10+ tasks completed in single day
        $maxInDay = $this->getMaxTasksInSingleDay($db);
        if ($maxInDay >= 10) {
            $badges[] = [
                'emoji' => '<fg=cyan>⚡</>',
                'name' => '<fg=cyan>Speed Demon (10+ in one day)</>',
            ];
        }

        // Complex Crusher: 10+ complex tasks completed
        $complexCompleted = $this->getComplexTasksCompleted($db);
        if ($complexCompleted >= 10) {
            $badges[] = [
                'emoji' => '<fg=magenta>🦾</>',
                'name' => '<fg=magenta>Complex Crusher (10+ complex)</>',
            ];
        }

        // Documenter: 10+ docs tasks completed
        $docsCompleted = $this->getDocsTasksCompleted($db);
        if ($docsCompleted >= 10) {
            $badges[] = [
                'emoji' => '<fg=blue>📚</>',
                'name' => '<fg=blue>Documenter (10+ docs)</>',
            ];
        }

        // Bug Hunter: 25+ bugs fixed
        $bugsFixed = $this->getBugsFixed($db);
        if ($bugsFixed >= 25) {
            $badges[] = [
                'emoji' => '<fg=red>🐛</>',
                'name' => '<fg=red>Bug Hunter (25+ bugs fixed)</>',
            ];
        }

        // On Fire: 7+ day streak
        $currentStreak = $this->calculateStreak($db);
        if ($currentStreak >= 7) {
            $badges[] = [
                'emoji' => '<fg=red>🔥</>',
                'name' => '<fg=red>On Fire (7+ day streak)</>',
            ];
        }

        return $badges;
    }

    private function getTasksCompletedToday(DatabaseService $db): int
    {
        $query = "SELECT COUNT(*) as cnt
                  FROM tasks
                  WHERE status = 'closed'
                  AND DATE(updated_at) = DATE('now')";
        $result = $db->fetchAll($query);

        return (int) ($result[0]['cnt'] ?? 0);
    }

    private function getTasksCompletedThisWeek(DatabaseService $db): int
    {
        $query = "SELECT COUNT(*) as cnt
                  FROM tasks
                  WHERE status = 'closed'
                  AND updated_at >= date('now', '-7 days')";
        $result = $db->fetchAll($query);

        return (int) ($result[0]['cnt'] ?? 0);
    }

    private function getTasksCompletedThisMonth(DatabaseService $db): int
    {
        $query = "SELECT COUNT(*) as cnt
                  FROM tasks
                  WHERE status = 'closed'
                  AND updated_at >= date('now', '-30 days')";
        $result = $db->fetchAll($query);

        return (int) ($result[0]['cnt'] ?? 0);
    }

    private function getTasksCompletedAllTime(DatabaseService $db): int
    {
        $query = "SELECT COUNT(*) as cnt
                  FROM tasks
                  WHERE status = 'closed'";
        $result = $db->fetchAll($query);

        return (int) ($result[0]['cnt'] ?? 0);
    }

    private function getMaxTasksInSingleDay(DatabaseService $db): int
    {
        $query = "SELECT DATE(updated_at) as day, COUNT(*) as cnt
                  FROM tasks
                  WHERE status = 'closed'
                  GROUP BY DATE(updated_at)
                  ORDER BY cnt DESC
                  LIMIT 1";
        $result = $db->fetchAll($query);

        return (int) ($result[0]['cnt'] ?? 0);
    }

    private function getComplexTasksCompleted(DatabaseService $db): int
    {
        $query = "SELECT COUNT(*) as cnt
                  FROM tasks
                  WHERE status = 'closed'
                  AND complexity = 'complex'";
        $result = $db->fetchAll($query);

        return (int) ($result[0]['cnt'] ?? 0);
    }

    private function getDocsTasksCompleted(DatabaseService $db): int
    {
        $query = "SELECT COUNT(*) as cnt
                  FROM tasks
                  WHERE status = 'closed'
                  AND type = 'docs'";
        $result = $db->fetchAll($query);

        return (int) ($result[0]['cnt'] ?? 0);
    }

    private function getBugsFixed(DatabaseService $db): int
    {
        $query = "SELECT COUNT(*) as cnt
                  FROM tasks
                  WHERE status = 'closed'
                  AND type = 'bug'";
        $result = $db->fetchAll($query);

        return (int) ($result[0]['cnt'] ?? 0);
    }
}
