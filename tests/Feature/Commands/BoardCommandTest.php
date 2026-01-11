<?php

use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

uses()->group('commands');

describe('board command', function (): void {
    beforeEach(function (): void {
        $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($this->tempDir.'/.fuel', 0755, true);

        $context = new FuelContext($this->tempDir.'/.fuel');
        $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

        $this->dbPath = $context->getDatabasePath();

        $databaseService = new DatabaseService($context->getDatabasePath());
        $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);

        $this->app->singleton(TaskService::class, fn (): TaskService => makeTaskService($databaseService));

        $this->app->singleton(RunService::class, fn (): RunService => makeRunService($databaseService));

        $this->taskService = $this->app->make(TaskService::class);
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

    it('shows empty board when no tasks', function (): void {
        $this->taskService->initialize();

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Ready');
        expect($output)->toContain('In Progress');
        expect($output)->toContain('Review');
        expect($output)->toContain('Blocked');
        expect($output)->toContain('No tasks');
    });

    it('shows ready tasks in Ready column', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Ready task']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Ready task');
    });

    it('shows blocked tasks in Blocked column', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        // Titles may be truncated, so check for short IDs with complexity char
        $blockerShortId = substr((string) $blocker['id'], 2, 6);
        $blockedShortId = substr((string) $blocked['id'], 2, 6);
        expect($output)->toContain(sprintf('[%s ·s]', $blockerShortId));
        expect($output)->toContain(sprintf('[%s ·s]', $blockedShortId));
    });

    it('shows in progress tasks in In Progress column', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'In progress task']);
        $this->taskService->start($task['id']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        // Title may be truncated, so check for short ID with complexity char
        $shortId = substr((string) $task['id'], 2, 6);
        expect($output)->toContain(sprintf('[%s ·s]', $shortId));
    });

    it('shows done tasks in Done column', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Completed task']);
        $this->taskService->done($task['id']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        // Done tasks appear in "Done" column
        $shortId = substr((string) $task['id'], 2, 6);
        expect($output)->toContain('Done (1)');
        expect($output)->toContain(sprintf('[%s ·s]', $shortId));
    });

    it('shows review tasks in Review column', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Review task']);
        $this->taskService->update($task['id'], ['status' => 'review']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        // Review tasks appear in "Review" column
        $shortId = substr((string) $task['id'], 2, 6);
        expect($output)->toContain('Review (1)');
        expect($output)->toContain(sprintf('[%s ·s]', $shortId));
    });

    it('does not show review tasks in other columns', function (): void {
        $this->taskService->initialize();
        $reviewTask = $this->taskService->create(['title' => 'Review task']);
        $this->taskService->update($reviewTask['id'], ['status' => 'review']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        $reviewShortId = substr((string) $reviewTask['id'], 2, 6);

        // Review task should appear in Review column
        expect($output)->toContain('Review (1)');
        expect($output)->toContain(sprintf('[%s ·s]', $reviewShortId));

        // Review task should NOT appear in other columns
        // Check that it doesn't appear in Ready, In Progress, Blocked, or Done sections
        // We'll check by ensuring the count for those columns is 0
        expect($output)->toContain('Ready (0)');
        expect($output)->toContain('In Progress (0)');
        expect($output)->toContain('Blocked (0)');
        expect($output)->toContain('Done (0)');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Test task']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain('"ready":');
        expect($output)->toContain('"in_progress":');
        expect($output)->toContain('"review":');
        expect($output)->toContain('"blocked":');
        expect($output)->toContain('"human":');
        expect($output)->toContain('"done":');
        expect($output)->toContain('Test task');
    });

    it('returns all done tasks in JSON output', function (): void {
        $this->taskService->initialize();

        // Create and close 12 tasks
        for ($i = 1; $i <= 12; $i++) {
            $task = $this->taskService->create(['title' => 'Done task '.$i]);
            $this->taskService->done($task['id']);
        }

        Artisan::call('board', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        // JSON output returns all done tasks (display limits to 3)
        expect($data['done'])->toHaveCount(12);
    });

    it('shows failed icon for consumed tasks with non-zero exit code', function (): void {
        $this->taskService->initialize();
        $stuckTask = $this->taskService->create(['title' => 'Stuck task']);
        $this->taskService->update($stuckTask['id'], [
            'consumed' => true,
            'consumed_exit_code' => 1,
        ]);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        $shortId = substr((string) $stuckTask['id'], 2, 6);
        expect($output)->toContain('🪫');
        expect($output)->toContain(sprintf('[%s ·s]', $shortId));
    });

    it('does not show failed icon for consumed tasks with zero exit code', function (): void {
        $this->taskService->initialize();
        $successTask = $this->taskService->create(['title' => 'Success task']);
        $this->taskService->update($successTask['id'], [
            'consumed' => true,
            'consumed_exit_code' => 0,
        ]);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        $shortId = substr((string) $successTask['id'], 2, 6);
        expect($output)->toContain(sprintf('[%s ·s]', $shortId));
        expect($output)->not->toContain('🪫');
    });

    it('shows stuck emoji for in_progress tasks with non-running PID', function (): void {
        $this->taskService->initialize();
        $stuckTask = $this->taskService->create(['title' => 'Stuck PID task']);
        $this->taskService->update($stuckTask['id'], [
            'status' => 'in_progress',
            'consume_pid' => 99999, // Non-existent PID
        ]);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        $shortId = substr((string) $stuckTask['id'], 2, 6);
        expect($output)->toContain('🪫');
        expect($output)->toContain(sprintf('[%s ·s]', $shortId));
    });

    it('does not show stuck emoji for in_progress tasks with running PID', function (): void {
        $this->taskService->initialize();
        $runningTask = $this->taskService->create(['title' => 'Running task']);
        // Use current process PID which should be running
        $currentPid = getmypid();
        $this->taskService->update($runningTask['id'], [
            'status' => 'in_progress',
            'consume_pid' => $currentPid,
        ]);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        $shortId = substr((string) $runningTask['id'], 2, 6);
        expect($output)->toContain(sprintf('[%s ·s]', $shortId));
        expect($output)->not->toContain('🪫');
    });

    it('shows complexity character in task display', function (): void {
        $this->taskService->initialize();
        $trivialTask = $this->taskService->create(['title' => 'Trivial task', 'complexity' => 'trivial']);
        $simpleTask = $this->taskService->create(['title' => 'Simple task', 'complexity' => 'simple']);
        $moderateTask = $this->taskService->create(['title' => 'Moderate task', 'complexity' => 'moderate']);
        $complexTask = $this->taskService->create(['title' => 'Complex task', 'complexity' => 'complex']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        $trivialShortId = substr((string) $trivialTask['id'], 2, 6);
        $simpleShortId = substr((string) $simpleTask['id'], 2, 6);
        $moderateShortId = substr((string) $moderateTask['id'], 2, 6);
        $complexShortId = substr((string) $complexTask['id'], 2, 6);

        expect($output)->toContain(sprintf('[%s ·t]', $trivialShortId));
        expect($output)->toContain(sprintf('[%s ·s]', $simpleShortId));
        expect($output)->toContain(sprintf('[%s ·m]', $moderateShortId));
        expect($output)->toContain(sprintf('[%s ·c]', $complexShortId));
    });

    it('defaults to simple complexity when complexity is missing', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Task without complexity']);

        Artisan::call('board', ['--cwd' => $this->tempDir, '--once' => true]);
        $output = Artisan::output();

        $shortId = substr((string) $task['id'], 2, 6);
        expect($output)->toContain(sprintf('[%s ·s]', $shortId));
    });
});
