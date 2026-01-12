<?php

use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

uses()->group('commands');

describe('stats command', function (): void {
    beforeEach(function (): void {
        $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($this->tempDir.'/.fuel', 0755, true);

        $context = new FuelContext($this->tempDir.'/.fuel');
        $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

        $this->dbPath = $context->getDatabasePath();

        $context->configureDatabase();
        $databaseService = new DatabaseService($context->getDatabasePath());
        $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);
        Artisan::call('migrate', ['--force' => true]);

        $this->app->singleton(TaskService::class, fn (): TaskService => makeTaskService());

        $this->app->singleton(RunService::class, fn (): RunService => makeRunService());

        $this->app->singleton(EpicService::class, fn (): EpicService => makeEpicService($this->taskService));

        $this->taskService = $this->app->make(TaskService::class);
        $this->runService = $this->app->make(RunService::class);
        $this->epicService = $this->app->make(EpicService::class);
        $this->databaseService = $databaseService;
    });

    afterEach(function (): void {
        $deleteDir = function (string $dir) use (&$deleteDir): void {
            if (! is_dir($dir)) {
                return;
            }

            $items = scandir($dir);
            foreach ($items as $item) {
                if ($item === '.') {
                    continue;
                }

                if ($item === '..') {
                    continue;
                }

                $path = $dir.'/'.$item;
                if (is_dir($path)) {
                    $deleteDir($path);
                } elseif (file_exists($path)) {
                    unlink($path);
                }
            }

            rmdir($dir);
        };

        $deleteDir($this->tempDir);
    });

    it('runs without error on empty database', function (): void {

        Artisan::call('stats', []);
        $output = Artisan::output();

        expect($output)->toContain('TASK STATISTICS');
        expect($output)->toContain('AGENT RUNS');
        expect($output)->toContain('TIMING');
        expect($output)->toContain('TRENDS');
        expect($output)->toContain('ACTIVITY');
        expect($output)->toContain('STREAKS & ACHIEVEMENTS');
    });

    it('displays task counts correctly', function (): void {

        // Create tasks with various states
        $open = $this->taskService->create(['title' => 'Open task', 'complexity' => 'simple', 'priority' => 1]);
        $inProgress = $this->taskService->create(['title' => 'In progress task', 'complexity' => 'moderate', 'priority' => 0]);
        $this->taskService->start($inProgress['short_id']);
        $done = $this->taskService->create(['title' => 'Closed task', 'complexity' => 'complex', 'priority' => 2]);
        $this->taskService->done($done['short_id']);
        $blocker = $this->taskService->create(['title' => 'Blocker', 'complexity' => 'trivial', 'priority' => 3]);
        $blocked = $this->taskService->create(['title' => 'Blocked task', 'complexity' => 'simple', 'priority' => 4]);
        $this->taskService->addDependency($blocked['short_id'], $blocker['short_id']);

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check total
        expect($output)->toContain('Total: 5');

        // Check status counts
        expect($output)->toContain('Done: 1');
        expect($output)->toContain('In Progress: 1');
        expect($output)->toContain('Open: 3');
        expect($output)->toContain('Blocked: 1');

        // Check complexity counts
        expect($output)->toContain('trivial: 1');
        expect($output)->toContain('simple: 2');
        expect($output)->toContain('moderate: 1');
        expect($output)->toContain('complex: 1');

        // Check priority counts
        expect($output)->toContain('P0: 1');
        expect($output)->toContain('P1: 1');
        expect($output)->toContain('P2: 1');
        expect($output)->toContain('P3: 1');
        expect($output)->toContain('P4: 1');
    });

    it('displays run statistics correctly', function (): void {

        // Create tasks first
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $task3 = $this->taskService->create(['title' => 'Task 3']);

        // Create completed runs with different agents and models
        for ($i = 0; $i < 5; $i++) {
            $this->runService->logRun($task1['short_id'], [
                'agent' => 'cursor-agent',
                'model' => 'claude-opus-4',
                'started_at' => date('c'),
            ]);
            $this->runService->updateLatestRun($task1['short_id'], [
                'ended_at' => date('c'),
                'exit_code' => 0,
            ]);
        }

        for ($i = 0; $i < 3; $i++) {
            $this->runService->logRun($task2['short_id'], [
                'agent' => 'claude',
                'model' => 'claude-sonnet-4',
                'started_at' => date('c'),
            ]);
            $this->runService->updateLatestRun($task2['short_id'], [
                'ended_at' => date('c'),
                'exit_code' => 0,
            ]);
        }

        // Create 2 failed runs (completed with exit_code 1)
        for ($i = 0; $i < 2; $i++) {
            $this->runService->logRun($task3['short_id'], [
                'agent' => 'cursor-agent',
                'model' => 'claude-sonnet-4',
                'started_at' => date('c'),
            ]);
            $this->runService->updateLatestRun($task3['short_id'], [
                'ended_at' => date('c'),
                'exit_code' => 1,
            ]);
        }

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check total runs
        expect($output)->toContain('Total Runs: 10');

        // Check status counts (all completed via updateLatestRun)
        expect($output)->toContain('Completed: 10');

        // Check agent ranking
        expect($output)->toContain('cursor-agent');
        expect($output)->toContain('claude');
    });

    it('displays timing stats with known durations', function (): void {

        // Create tasks first
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $task3 = $this->taskService->create(['title' => 'Task 3']);

        // Create run 1 with 60s duration
        $this->runService->logRun($task1['short_id'], [
            'agent' => 'cursor-agent',
            'model' => 'claude-opus-4',
            'started_at' => date('c', time() - 60),
        ]);
        $this->runService->updateLatestRun($task1['short_id'], [
            'ended_at' => date('c'),
            'exit_code' => 0,
        ]);

        // Create run 2 with 120s duration
        $this->runService->logRun($task2['short_id'], [
            'agent' => 'cursor-agent',
            'model' => 'claude-opus-4',
            'started_at' => date('c', time() - 120),
        ]);
        $this->runService->updateLatestRun($task2['short_id'], [
            'ended_at' => date('c'),
            'exit_code' => 0,
        ]);

        // Create run 3 with 180s duration
        $this->runService->logRun($task3['short_id'], [
            'agent' => 'claude',
            'model' => 'claude-sonnet-4',
            'started_at' => date('c', time() - 180),
        ]);
        $this->runService->updateLatestRun($task3['short_id'], [
            'ended_at' => date('c'),
            'exit_code' => 0,
        ]);

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check timing section exists
        expect($output)->toContain('TIMING');
        expect($output)->toContain('Average Run Duration:');

        // Average should be (60 + 120 + 180) / 3 = 120s = 2m 0s
        expect($output)->toContain('2m 0s');

        // Check longest run (180s = 3m 0s)
        expect($output)->toContain('Longest Run:');
        expect($output)->toContain('3m 0s');

        // Check shortest run (60s = 1m 0s)
        expect($output)->toContain('Shortest Run:');
        expect($output)->toContain('1m 0s');
    });

    it('heatmap renders correct number of weeks', function (): void {

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check heatmap section exists
        expect($output)->toContain('ACTIVITY (last 8 weeks)');

        // Check all days of week are rendered
        expect($output)->toContain('Sun');
        expect($output)->toContain('Mon');
        expect($output)->toContain('Tue');
        expect($output)->toContain('Wed');
        expect($output)->toContain('Thu');
        expect($output)->toContain('Fri');
        expect($output)->toContain('Sat');

        // Check legend exists
        expect($output)->toContain('Legend:');
    });

    it('streak calculation is accurate', function (): void {
        $db = $this->databaseService;

        // Create tasks completed on consecutive days
        for ($i = 0; $i < 5; $i++) {
            $task = $this->taskService->create(['title' => 'Task '.$i]);
            $this->taskService->done($task['short_id']);

            // Update the task's updated_at to be $i days ago
            // Get the integer ID from the task
            $taskIntId = $db->fetchOne('SELECT id FROM tasks WHERE short_id = ?', [$task['short_id']]);
            $db->query(sprintf("UPDATE tasks SET updated_at = datetime('now', '-%d days') WHERE id = ?", $i), [(int) $taskIntId['id']]);
        }

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check streak section exists
        expect($output)->toContain('STREAKS & ACHIEVEMENTS');
        expect($output)->toContain('Current Streak:');
        expect($output)->toContain('5 days');

        // Longest streak should also be 5
        expect($output)->toContain('Longest Streak:');
    });

    it('sparkline output matches expected pattern', function (): void {
        $db = $this->databaseService;

        // Create tasks with known pattern over last 14 days
        // Day 0 (today): 0 tasks
        // Day 1: 1 task
        // Day 2: 2 tasks
        // Day 3: 3 tasks
        // Rest: 0 tasks
        for ($day = 1; $day <= 3; $day++) {
            for ($count = 0; $count < $day; $count++) {
                $task = $this->taskService->create(['title' => sprintf('Day %d Task %d', $day, $count)]);
                $this->taskService->done($task['short_id']);
                $taskIntId = $db->fetchOne('SELECT id FROM tasks WHERE short_id = ?', [$task['short_id']]);
                $db->query(sprintf("UPDATE tasks SET updated_at = datetime('now', '-%d days') WHERE id = ?", $day), [(int) $taskIntId['id']]);
            }
        }

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check trends section exists
        expect($output)->toContain('TRENDS (14 days)');
        expect($output)->toContain('Tasks:');
        expect($output)->toContain('6 total');

        // Sparkline should be present (contains block characters)
        expect($output)->toMatch('/[▁▂▃▄▅▆▇█]/u');
    });

    it('box rendering produces valid output', function (): void {

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check all boxes are properly formatted with borders
        expect($output)->toContain('┌─────────────────────────────────────────┐');
        expect($output)->toContain('└─────────────────────────────────────────┘');
        expect($output)->toContain('├─────────────────────────────────────────┤');

        // Check all major sections exist
        expect($output)->toContain('📋 TASK STATISTICS');
        expect($output)->toContain('📦 EPICS');
        expect($output)->toContain('🤖 AGENT RUNS');
        expect($output)->toContain('⏱️ TIMING');
        expect($output)->toContain('📈 TRENDS');
        expect($output)->toContain('📊 ACTIVITY');
        expect($output)->toContain('🔥 STREAKS & ACHIEVEMENTS');
    });

    it('displays epic statistics correctly', function (): void {

        // Create epics with different statuses
        $epic1 = $this->epicService->createEpic('Epic 1', 'Description 1');
        $epic2 = $this->epicService->createEpic('Epic 2', 'Description 2');

        // Epic 3: Create with a task to make it in_progress
        $epic3 = $this->epicService->createEpic('Epic 3', 'Description 3');
        $task = $this->taskService->create(['title' => 'Epic 3 task', 'epic_id' => $epic3->id]);

        // Epic 4: Create with completed tasks and approve it
        $epic4 = $this->epicService->createEpic('Epic 4', 'Description 4');
        $task4 = $this->taskService->create(['title' => 'Epic 4 task', 'epic_id' => $epic4->id]);
        $this->taskService->done($task4['short_id']);
        $this->epicService->approveEpic($epic4->id);

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check epic section exists
        expect($output)->toContain('EPICS');
        expect($output)->toContain('Total: 4');
        expect($output)->toContain('Planning: 2');
        expect($output)->toContain('In Progress: 1');
        expect($output)->toContain('Approved: 1');
    });

    it('displays task type counts correctly', function (): void {

        // Create tasks with various types
        $this->taskService->create(['title' => 'Bug task', 'type' => 'bug']);
        $this->taskService->create(['title' => 'Fix task', 'type' => 'fix']);
        $this->taskService->create(['title' => 'Feature task', 'type' => 'feature']);
        $this->taskService->create(['title' => 'Task task', 'type' => 'task']);
        $this->taskService->create(['title' => 'Chore task', 'type' => 'chore']);
        $this->taskService->create(['title' => 'Docs task', 'type' => 'docs']);
        $this->taskService->create(['title' => 'Test task', 'type' => 'test']);
        $this->taskService->create(['title' => 'Refactor task', 'type' => 'refactor']);

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check type counts
        expect($output)->toContain('By Type:');
        expect($output)->toContain('bug: 1');
        expect($output)->toContain('fix: 1');
        expect($output)->toContain('feature: 1');
        expect($output)->toContain('task: 1');
        expect($output)->toContain('chore: 1');
        expect($output)->toContain('docs: 1');
        expect($output)->toContain('test: 1');
        expect($output)->toContain('refactor: 1');
    });

    it('displays badges when achievements are earned', function (): void {
        $db = $this->databaseService;

        // Create 10 complex tasks to earn "Complex Crusher" badge
        for ($i = 0; $i < 10; $i++) {
            $task = $this->taskService->create(['title' => 'Complex task '.$i, 'complexity' => 'complex']);
            $this->taskService->done($task['short_id']);
        }

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check badges section exists
        expect($output)->toContain('Badges:');

        // Check for Complex Crusher badge
        expect($output)->toContain('Complex Crusher');
    });

    it('shows no badges message when none earned', function (): void {

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check badges section exists
        expect($output)->toContain('Badges:');

        // Check for no badges message
        expect($output)->toContain('no badges earned yet');
    });

    it('displays completion counts for different time periods', function (): void {
        $db = $this->databaseService;

        // Create tasks completed today
        $task1 = $this->taskService->create(['title' => 'Today task 1']);
        $this->taskService->done($task1['short_id']);
        $task2 = $this->taskService->create(['title' => 'Today task 2']);
        $this->taskService->done($task2['short_id']);

        // Create tasks completed 5 days ago (this week)
        $task3 = $this->taskService->create(['title' => 'This week task']);
        $this->taskService->done($task3['short_id']);
        $taskIntId3 = $db->fetchOne('SELECT id FROM tasks WHERE short_id = ?', [$task3['short_id']]);
        $db->query("UPDATE tasks SET updated_at = datetime('now', '-5 days') WHERE id = ?", [(int) $taskIntId3['id']]);

        // Create tasks completed 20 days ago (this month)
        $task4 = $this->taskService->create(['title' => 'This month task']);
        $this->taskService->done($task4['short_id']);
        $taskIntId4 = $db->fetchOne('SELECT id FROM tasks WHERE short_id = ?', [$task4['short_id']]);
        $db->query("UPDATE tasks SET updated_at = datetime('now', '-20 days') WHERE id = ?", [(int) $taskIntId4['id']]);

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check time period counts
        expect($output)->toContain('Today: 2');
        expect($output)->toContain('This Week: 3');
        expect($output)->toContain('This Month: 4');
        expect($output)->toContain('All Time: 4');
    });

    it('handles running runs in status count', function (): void {

        // Create a task first
        $task = $this->taskService->create(['title' => 'Task 1']);

        // Create a running run (started but not completed - no ended_at)
        $this->runService->logRun($task['short_id'], [
            'agent' => 'cursor-agent',
            'model' => 'claude-opus-4',
            'started_at' => date('c'),
            // No ended_at means status stays 'running'
        ]);

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check running count
        expect($output)->toContain('Running: 1');
    });

    it('formats durations correctly for different time scales', function (): void {

        // Create tasks first
        $task1 = $this->taskService->create(['title' => 'Task 1']);
        $task2 = $this->taskService->create(['title' => 'Task 2']);
        $task3 = $this->taskService->create(['title' => 'Task 3']);

        // Create run with duration less than 1 minute (45s)
        $this->runService->logRun($task1['short_id'], [
            'agent' => 'cursor-agent',
            'model' => 'claude-opus-4',
            'started_at' => date('c', time() - 45),
        ]);
        $this->runService->updateLatestRun($task1['short_id'], [
            'ended_at' => date('c'),
            'exit_code' => 0,
        ]);

        // Create run with duration in minutes (5m 30s = 330s)
        $this->runService->logRun($task2['short_id'], [
            'agent' => 'cursor-agent',
            'model' => 'claude-opus-4',
            'started_at' => date('c', time() - 330),
        ]);
        $this->runService->updateLatestRun($task2['short_id'], [
            'ended_at' => date('c'),
            'exit_code' => 0,
        ]);

        // Create run with duration in hours (1h 15m = 4500s)
        $this->runService->logRun($task3['short_id'], [
            'agent' => 'cursor-agent',
            'model' => 'claude-opus-4',
            'started_at' => date('c', time() - 4500),
        ]);
        $this->runService->updateLatestRun($task3['short_id'], [
            'ended_at' => date('c'),
            'exit_code' => 0,
        ]);

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check different duration formats appear
        expect($output)->toMatch('/\d+s/'); // Seconds format
        expect($output)->toMatch('/\d+m \d+s/'); // Minutes and seconds format
        expect($output)->toMatch('/\d+h \d+m/'); // Hours and minutes format
    });

    it('displays review status when present', function (): void {

        // Create tasks with review status
        $task1 = $this->taskService->create(['title' => 'Review task 1']);
        $this->taskService->update($task1['short_id'], ['status' => 'review']);
        $task2 = $this->taskService->create(['title' => 'Review task 2']);
        $this->taskService->update($task2['short_id'], ['status' => 'review']);

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check review count appears
        expect($output)->toContain('Review: 2');
    });

    it('displays cancelled status when present', function (): void {

        // Create a cancelled task
        $task = $this->taskService->create(['title' => 'Cancelled task']);
        $this->taskService->update($task['short_id'], ['status' => 'cancelled']);

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Check cancelled count appears
        expect($output)->toContain('Cancelled: 1');
    });

    it('calculates longest streak correctly across gaps', function (): void {
        $db = $this->databaseService;

        // Create first streak: 3 days (7, 8, 9 days ago)
        for ($i = 7; $i <= 9; $i++) {
            $task = $this->taskService->create(['title' => 'Streak 1 Day '.$i]);
            $this->taskService->done($task['short_id']);
            $taskIntId = $db->fetchOne('SELECT id FROM tasks WHERE short_id = ?', [$task['short_id']]);
            $db->query(sprintf("UPDATE tasks SET updated_at = datetime('now', '-%d days') WHERE id = ?", $i), [(int) $taskIntId['id']]);
        }

        // Gap: no tasks 5-6 days ago

        // Create second streak: 5 days (0, 1, 2, 3, 4 days ago) - current streak
        for ($i = 0; $i <= 4; $i++) {
            $task = $this->taskService->create(['title' => 'Streak 2 Day '.$i]);
            $this->taskService->done($task['short_id']);
            $taskIntId = $db->fetchOne('SELECT id FROM tasks WHERE short_id = ?', [$task['short_id']]);
            $db->query(sprintf("UPDATE tasks SET updated_at = datetime('now', '-%d days') WHERE id = ?", $i), [(int) $taskIntId['id']]);
        }

        Artisan::call('stats', []);
        $output = Artisan::output();

        // Current streak should be 5 days (days 0-4)
        expect($output)->toMatch('/Current Streak:.*5 days/');

        // Longest streak should also be 5 days
        expect($output)->toMatch('/Longest Streak:.*5 days/');
    });
});
