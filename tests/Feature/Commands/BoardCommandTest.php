<?php

use App\Services\ConfigService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

uses()->group('commands');

describe('consume --once kanban board', function (): void {
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

        $this->taskService = $this->app->make(TaskService::class);

        // Create minimal config file
        $minimalConfig = <<<'YAML'
primary: test-agent
complexity:
  trivial: test-agent
  simple: test-agent
  moderate: test-agent
  complex: test-agent
agents:
  test-agent:
    command: echo
YAML;
        file_put_contents($this->tempDir.'/.fuel/config.yaml', $minimalConfig);

        // Rebind ConfigService to use the test FuelContext
        $this->app->singleton(ConfigService::class, fn (): ConfigService => new ConfigService($context));
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
        Artisan::call('consume', ['--once' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Ready');
        expect($output)->toContain('In Progress');
        expect($output)->toContain('No tasks');
    });

    it('shows ready tasks in Ready column', function (): void {
        $this->taskService->create(['title' => 'Ready task']);

        Artisan::call('consume', ['--once' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Ready task');
    });

    it('shows blocked task count in footer', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

        Artisan::call('consume', ['--once' => true]);
        $output = Artisan::output();

        // Blocked tasks are in a modal, but count shows in footer
        expect($output)->toContain('b: blocked (1)');
        // Blocker task should be in Ready column with its title
        expect($output)->toContain('Blocker task');
    });

    it('shows in progress tasks in In Progress column', function (): void {
        $task = $this->taskService->create(['title' => 'In progress task']);
        $this->taskService->start($task->short_id);

        Artisan::call('consume', ['--once' => true]);
        $output = Artisan::output();

        // Check for In Progress column and task title
        expect($output)->toContain('In Progress (1)');
        expect($output)->toContain('In progress task');
    });

    it('shows done task count in footer', function (): void {
        $task = $this->taskService->create(['title' => 'Completed task']);
        $this->taskService->done($task->short_id);

        Artisan::call('consume', ['--once' => true]);
        $output = Artisan::output();

        // Done tasks are in a modal, but count shows in footer
        expect($output)->toContain('d: done (1)');
    });

    it('shows review tasks in Review column', function (): void {
        $task = $this->taskService->create(['title' => 'Review task']);
        $this->taskService->update($task->short_id, ['status' => 'review']);

        Artisan::call('consume', ['--once' => true]);
        $output = Artisan::output();

        // Review tasks appear in "Review" column with title
        expect($output)->toContain('Review (1)');
        expect($output)->toContain('Review task');
    });

    it('does not show review tasks in other columns', function (): void {
        $reviewTask = $this->taskService->create(['title' => 'Review task']);
        $this->taskService->update($reviewTask->short_id, ['status' => 'review']);

        Artisan::call('consume', ['--once' => true]);
        $output = Artisan::output();

        // Review task should appear in Review column
        expect($output)->toContain('Review (1)');
        expect($output)->toContain('Review task');

        // Check that Ready and In Progress have 0 tasks
        expect($output)->toContain('Ready (0)');
        expect($output)->toContain('In Progress (0)');
    });

    it('shows failed icon for consumed tasks with non-zero exit code', function (): void {
        $stuckTask = $this->taskService->create(['title' => 'Stuck task']);
        $this->taskService->update($stuckTask->short_id, [
            'consumed' => true,
            'consumed_exit_code' => 1,
        ]);

        Artisan::call('consume', ['--once' => true]);
        $output = Artisan::output();

        expect($output)->toContain('🪫');
        expect($output)->toContain('Stuck task');
    });

    it('does not show failed icon for consumed tasks with zero exit code', function (): void {
        $successTask = $this->taskService->create(['title' => 'Success task']);
        $this->taskService->update($successTask->short_id, [
            'consumed' => true,
            'consumed_exit_code' => 0,
        ]);

        Artisan::call('consume', ['--once' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Success task');
        expect($output)->not->toContain('🪫');
    });

    it('shows stuck emoji for in_progress tasks with non-running PID', function (): void {
        $stuckTask = $this->taskService->create(['title' => 'Stuck PID task']);
        $this->taskService->update($stuckTask->short_id, [
            'status' => 'in_progress',
            'consume_pid' => 99999, // Non-existent PID
        ]);

        Artisan::call('consume', ['--once' => true]);
        $output = Artisan::output();

        expect($output)->toContain('🪫');
        expect($output)->toContain('Stuck PID task');
    });

    it('does not show stuck emoji for in_progress tasks with running PID', function (): void {
        $runningTask = $this->taskService->create(['title' => 'Running task']);
        // Use current process PID which should be running
        $currentPid = getmypid();
        $this->taskService->update($runningTask->short_id, [
            'status' => 'in_progress',
            'consume_pid' => $currentPid,
        ]);

        Artisan::call('consume', ['--once' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Running task');
        expect($output)->not->toContain('🪫');
    });

    it('shows complexity character in task display', function (): void {
        $trivialTask = $this->taskService->create(['title' => 'Trivial task', 'complexity' => 'trivial']);
        $simpleTask = $this->taskService->create(['title' => 'Simple task', 'complexity' => 'simple']);
        $moderateTask = $this->taskService->create(['title' => 'Moderate task', 'complexity' => 'moderate']);
        $complexTask = $this->taskService->create(['title' => 'Complex task', 'complexity' => 'complex']);

        Artisan::call('consume', ['--once' => true]);
        $output = Artisan::output();

        // Card footer format: ╰───────── t ─╯ (complexity char before closing)
        expect($output)->toContain('Trivial task');
        expect($output)->toContain('Simple task');
        expect($output)->toContain('Moderate task');
        expect($output)->toContain('Complex task');
        // Check complexity chars appear in card footers
        expect($output)->toContain(' t ─╯');
        expect($output)->toContain(' s ─╯');
        expect($output)->toContain(' m ─╯');
        expect($output)->toContain(' c ─╯');
    });

    it('defaults to simple complexity when complexity is missing', function (): void {
        $task = $this->taskService->create(['title' => 'Task without complexity']);

        Artisan::call('consume', ['--once' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Task without complexity');
        // Default complexity is simple ('s')
        expect($output)->toContain(' s ─╯');
    });

    it('shows footer with keyboard shortcuts', function (): void {
        Artisan::call('consume', ['--once' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Shift+Tab');
        expect($output)->toContain('b: blocked');
        expect($output)->toContain('d: done');
        expect($output)->toContain('q: exit');
    });
});
