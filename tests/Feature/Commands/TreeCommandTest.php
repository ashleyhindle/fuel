<?php

use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

describe('tree command', function (): void {
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

        $this->app->singleton(EpicService::class, fn (): EpicService => makeEpicService($this->app->make(TaskService::class)));

        $this->app->singleton(RunService::class, fn (): RunService => makeRunService());

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

    it('shows empty message when no pending tasks', function (): void {

        $this->artisan('tree')
            ->expectsOutputToContain('No pending tasks.')
            ->assertExitCode(0);
    });

    it('shows tasks without dependencies as flat list', function (): void {
        $this->taskService->create(['title' => 'Task one', 'priority' => 1]);
        $this->taskService->create(['title' => 'Task two', 'priority' => 2]);

        $this->artisan('tree')
            ->expectsOutputToContain('Task one')
            ->expectsOutputToContain('Task two')
            ->assertExitCode(0);
    });

    it('shows blocking tasks with blocked tasks indented underneath', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

        Artisan::call('tree');
        $output = Artisan::output();

        expect($output)->toContain('Blocked task');
        expect($output)->toContain('Blocker task');
        expect($output)->toContain('blocked by this');
    });

    it('shows task with multiple blockers', function (): void {
        $blocker1 = $this->taskService->create(['title' => 'First blocker']);
        $blocker2 = $this->taskService->create(['title' => 'Second blocker']);
        $blocked = $this->taskService->create(['title' => 'Multi-blocked task']);
        $this->taskService->addDependency($blocked->short_id, $blocker1->short_id);
        $this->taskService->addDependency($blocked->short_id, $blocker2->short_id);

        $this->artisan('tree')
            ->expectsOutputToContain('Multi-blocked task')
            ->expectsOutputToContain('First blocker')
            ->expectsOutputToContain('Second blocker')
            ->assertExitCode(0);
    });

    it('excludes done tasks from tree', function (): void {
        $openTask = $this->taskService->create(['title' => 'Open task']);
        $closedTask = $this->taskService->create(['title' => 'Closed task']);
        $this->taskService->done($closedTask->short_id);

        $this->artisan('tree')
            ->expectsOutputToContain('Open task')
            ->doesntExpectOutputToContain('Closed task')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is provided', function (): void {
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($blocked->short_id, $blocker->short_id);

        Artisan::call('tree', ['--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveCount(2);

        // Find the blocker task entry - it should have the blocked task in its 'blocks' array
        $blockerEntry = collect($data)->first(fn ($item): bool => $item['task']['title'] === 'Blocker task');
        expect($blockerEntry)->not->toBeNull();
        expect($blockerEntry['blocks'])->toHaveCount(1);
        expect($blockerEntry['blocks'][0]['title'])->toBe('Blocked task');
    });

    it('returns empty array in JSON when no tasks', function (): void {

        Artisan::call('tree', ['--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toBeEmpty();
    });

    it('sorts tasks by priority then created_at', function (): void {
        $lowPriority = $this->taskService->create(['title' => 'Low priority', 'priority' => 3]);
        $highPriority = $this->taskService->create(['title' => 'High priority', 'priority' => 0]);

        Artisan::call('tree', ['--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data[0]['task']['title'])->toBe('High priority');
        expect($data[1]['task']['title'])->toBe('Low priority');
    });

    it('shows needs-human label with special indicator', function (): void {
        $this->taskService->create(['title' => 'Human task', 'labels' => ['needs-human']]);
        $this->taskService->create(['title' => 'Normal task']);

        Artisan::call('tree');
        $output = Artisan::output();

        expect($output)->toContain('Human task');
        expect($output)->toContain('needs human');
        expect($output)->toContain('Normal task');
    });

    it('filters tasks by epic ID', function (): void {
        $epicService = $this->app->make(EpicService::class);

        $epic1 = $epicService->createEpic('Epic One');
        $epic2 = $epicService->createEpic('Epic Two');

        $task1 = $this->taskService->create(['title' => 'Task in Epic 1', 'epic_id' => $epic1->id]);
        $task2 = $this->taskService->create(['title' => 'Task in Epic 2', 'epic_id' => $epic2->id]);
        $task3 = $this->taskService->create(['title' => 'Task no epic']);

        Artisan::call('tree', ['--epic' => $epic1->short_id]);
        $output = Artisan::output();

        expect($output)->toContain('Task in Epic 1');
        expect($output)->not->toContain('Task in Epic 2');
        expect($output)->not->toContain('Task no epic');
    });

    it('shows error when filtering by non-existent epic', function (): void {
        $this->taskService->create(['title' => 'Some task']);

        $exitCode = Artisan::call('tree', ['--epic' => 'e-nonexistent']);
        $output = Artisan::output();

        expect($output)->toContain('not found');
        expect($exitCode)->not->toBe(0);
    });

    it('shows JSON error when filtering by non-existent epic', function (): void {
        $this->taskService->create(['title' => 'Some task']);

        Artisan::call('tree', ['--epic' => 'e-nonexistent', '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveKey('error');
        expect($data['error'])->toContain('not found');
    });

    it('shows JSON output filtered by epic', function (): void {
        $epicService = $this->app->make(EpicService::class);

        $epic1 = $epicService->createEpic('Epic One');
        $epic2 = $epicService->createEpic('Epic Two');

        $task1 = $this->taskService->create(['title' => 'Task in Epic 1', 'epic_id' => $epic1->id]);
        $task2 = $this->taskService->create(['title' => 'Task in Epic 2', 'epic_id' => $epic2->id]);

        Artisan::call('tree', ['--epic' => $epic1->short_id, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toHaveCount(1);
        expect($data[0]['task']['title'])->toBe('Task in Epic 1');
    });
});
