<?php

use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// Show Command Tests
describe('show command', function (): void {
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

    it('shows task details with all fields', function (): void {
        $task = $this->taskService->create([
            'title' => 'Test task',
            'description' => 'Test description',
            'type' => 'feature',
            'priority' => 3,
            'labels' => ['frontend', 'backend'],
        ]);

        $this->artisan('show', ['id' => $task->short_id])
            ->expectsOutputToContain('Task: '.$task->short_id)
            ->expectsOutputToContain('Title: Test task')
            ->expectsOutputToContain('Status: open')
            ->expectsOutputToContain('Description: Test description')
            ->expectsOutputToContain('Type: feature')
            ->expectsOutputToContain('Priority: 3')
            ->expectsOutputToContain('Labels: frontend, backend')
            ->assertExitCode(0);
    });

    it('shows task with blockers in blocked_by array', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker']);
        $task = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($task->short_id, $blocker->short_id);

        $this->artisan('show', ['id' => $task->short_id])
            ->expectsOutputToContain('Blocked by: '.$blocker->short_id)
            ->assertExitCode(0);
    });

    it('shows task with reason if present', function (): void {
        $task = $this->taskService->create(['title' => 'Completed task']);
        $this->taskService->done($task->short_id, 'Fixed the issue');

        $this->artisan('show', ['id' => $task->short_id])
            ->expectsOutputToContain('Reason: Fixed the issue')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $task = $this->taskService->create([
            'title' => 'JSON task',
            'description' => 'JSON description',
            'type' => 'bug',
            'priority' => 4,
            'labels' => ['critical'],
        ]);

        Artisan::call('show', ['id' => $task->short_id, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['short_id'])->toBe($task->short_id);
        expect($result['title'])->toBe('JSON task');
        expect($result['description'])->toBe('JSON description');
        expect($result['type'])->toBe('bug');
        expect($result['priority'])->toBe(4);
        expect($result['labels'])->toBe(['critical']);
    });

    it('shows error for non-existent task', function (): void {

        $this->artisan('show', ['id' => 'nonexistent'])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('supports partial ID matching', function (): void {
        $task = $this->taskService->create(['title' => 'Partial ID task']);
        $partialId = substr((string) $task->short_id, 2, 3);

        $this->artisan('show', ['id' => $partialId])
            ->expectsOutputToContain('Task: '.$task->short_id)
            ->assertExitCode(0);
    });

    it('shows epic information when task has epic_id', function (): void {

        // Initialize database for epics
        $dbService = new DatabaseService($this->tempDir.'/.fuel/agent.db');

        $epicService = makeEpicService($dbService, $this->taskService);
        $epic = $epicService->createEpic('Test Epic', 'Epic description');

        $task = $this->taskService->create([
            'title' => 'Task with epic',
            'epic_id' => $epic->short_id,
        ]);

        Artisan::call('show', ['id' => $task->short_id]);
        $output = Artisan::output();

        // Verify task has epic_id
        $taskData = $this->taskService->find($task->short_id);
        expect($taskData->epic)->not->toBeNull();
        expect($taskData->epic->short_id)->toBe($epic->short_id);

        expect($output)->toContain('Epic: '.$epic->short_id);
        expect($output)->toContain('Test Epic');
        expect($output)->toContain('in_progress'); // Epic status is in_progress because task is open
    });

    it('includes epic information in JSON output when task has epic_id', function (): void {

        // Initialize database for epics
        $dbService = new DatabaseService($this->tempDir.'/.fuel/agent.db');

        $epicService = makeEpicService($dbService, $this->taskService);
        $epic = $epicService->createEpic('JSON Epic', 'Epic description');

        $task = $this->taskService->create([
            'title' => 'Task with epic',
            'epic_id' => $epic->short_id,
        ]);

        Artisan::call('show', ['id' => $task->short_id, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['epic'])->toBeArray();
        expect($result['epic']['short_id'])->toBe($epic->short_id);
        expect($result['epic']['title'])->toBe('JSON Epic');
        expect($result['epic']['status'])->toBe('in_progress'); // Epic status is in_progress because task is open
    });

    it('shows live output from stdout.log when task is in_progress', function (): void {
        $task = $this->taskService->create(['title' => 'In progress task']);
        $this->taskService->start($task->short_id);

        $runService = $this->app->make(RunService::class);
        $runShortId = $runService->createRun($task->short_id, [
            'agent' => 'test-agent',
        ]);

        // Create processes directory and stdout.log with some content
        $processDir = $this->tempDir.'/.fuel/processes/'.$runShortId;
        mkdir($processDir, 0755, true);
        $stdoutPath = $processDir.'/stdout.log';
        file_put_contents($stdoutPath, "Line 1\nLine 2\nLine 3\n");

        Artisan::call('show', ['id' => $task->short_id, '--raw' => true]);
        $output = Artisan::output();

        expect($output)->toContain('(live output)');
        expect($output)->toContain('Showing live output (tail)...');
        expect($output)->toContain('Line 1');
        expect($output)->toContain('Line 2');
        expect($output)->toContain('Line 3');
    });

    it('shows last 50 lines from stdout.log when file has more lines', function (): void {
        $task = $this->taskService->create(['title' => 'In progress task']);
        $this->taskService->start($task->short_id);

        $runService = $this->app->make(RunService::class);
        $runShortId = $runService->createRun($task->short_id, [
            'agent' => 'test-agent',
        ]);

        // Create processes directory and stdout.log with 60 lines
        $processDir = $this->tempDir.'/.fuel/processes/'.$runShortId;
        mkdir($processDir, 0755, true);
        $stdoutPath = $processDir.'/stdout.log';
        $lines = [];
        for ($i = 1; $i <= 60; $i++) {
            $lines[] = 'Line '.$i;
        }

        file_put_contents($stdoutPath, implode("\n", $lines)."\n");

        Artisan::call('show', ['id' => $task->short_id, '--raw' => true]);
        $output = Artisan::output();

        expect($output)->toContain('(live output)');
        expect($output)->toContain('Showing live output (tail)...');
        // Should contain last 50 lines (11-60)
        expect($output)->toContain('Line 11');
        expect($output)->toContain('Line 60');
        // Should not contain first 10 lines (check for exact line matches)
        expect($output)->not->toContain("\n    Line 1\n");
        expect($output)->not->toContain("\n    Line 10\n");
    });

    it('shows regular run output when task is not in_progress', function (): void {
        $task = $this->taskService->create(['title' => 'Completed task']);
        $this->taskService->start($task->short_id);

        // Create a run with output
        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'output' => 'Run output content',
        ]);

        // Mark task as done
        $this->taskService->done($task->short_id);

        // Create stdout.log (should be ignored for done tasks)
        $databaseService = $this->app->make(DatabaseService::class);
        $run = $databaseService->fetchOne(
            'SELECT short_id FROM runs WHERE task_id = (SELECT id FROM tasks WHERE short_id = ?) ORDER BY id DESC LIMIT 1',
            [$task->short_id]
        );
        $runShortId = $run['short_id'];

        $processDir = $this->tempDir.'/.fuel/processes/'.$runShortId;
        mkdir($processDir, 0755, true);
        $stdoutPath = $processDir.'/stdout.log';
        file_put_contents($stdoutPath, "Live output\n");

        Artisan::call('show', ['id' => $task->short_id, '--raw' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Run Output');
        expect($output)->not->toContain('Run Output (live)');
        expect($output)->not->toContain('Showing live output (tail)...');
        expect($output)->toContain('Run output content');
        expect($output)->not->toContain('Live output');
    });

    it('shows regular run output when stdout.log does not exist', function (): void {
        $task = $this->taskService->create(['title' => 'In progress task']);
        $this->taskService->start($task->short_id);

        // Create a run with output
        $runService = $this->app->make(RunService::class);
        $runService->logRun($task->short_id, [
            'agent' => 'test-agent',
            'output' => 'Run output content',
        ]);

        Artisan::call('show', ['id' => $task->short_id, '--raw' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Run Output');
        expect($output)->not->toContain('Run Output (live)');
        expect($output)->toContain('Run output content');
    });

    it('shows --tail flag in help output', function (): void {
        Artisan::call('show', ['--help' => true]);
        $output = Artisan::output();

        expect($output)->toContain('--tail');
        expect($output)->toContain('Continuously tail the live output');
    });
});
