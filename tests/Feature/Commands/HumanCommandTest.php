<?php

use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

// =============================================================================
// human Command Tests
// =============================================================================

describe('human command', function (): void {
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

        $taskService = makeTaskService();
        $this->app->singleton(TaskService::class, fn (): TaskService => $taskService);
        $this->app->singleton(EpicService::class, fn (): EpicService => makeEpicService($taskService));

        $this->app->singleton(RunService::class, fn (): RunService => makeRunService());

        $this->taskService = $taskService;
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

    it('shows empty when no tasks with needs-human label', function (): void {
        $this->taskService->create(['title' => 'Regular task']);

        $this->artisan('human', [])
            ->expectsOutputToContain('No items need human attention.')
            ->assertExitCode(0);
    });

    it('shows open tasks with needs-human label', function (): void {
        $humanTask = $this->taskService->create([
            'title' => 'Needs human task',
            'labels' => ['needs-human'],
        ]);
        $regularTask = $this->taskService->create(['title' => 'Regular task']);

        Artisan::call('human', []);
        $output = Artisan::output();

        expect($output)->toContain('Needs human task');
        expect($output)->toContain($humanTask->short_id);
        expect($output)->not->toContain('Regular task');
    });

    it('excludes done tasks with needs-human label', function (): void {
        $humanTask = $this->taskService->create([
            'title' => 'Closed human task',
            'labels' => ['needs-human'],
        ]);
        $this->taskService->done($humanTask->short_id);

        $this->artisan('human', [])
            ->expectsOutputToContain('No items need human attention.')
            ->doesntExpectOutputToContain('Closed human task')
            ->assertExitCode(0);
    });

    it('excludes in_progress tasks with needs-human label', function (): void {
        $humanTask = $this->taskService->create([
            'title' => 'In progress human task',
            'labels' => ['needs-human'],
        ]);
        $this->taskService->start($humanTask->short_id);

        $this->artisan('human', [])
            ->expectsOutputToContain('No items need human attention.')
            ->doesntExpectOutputToContain('In progress human task')
            ->assertExitCode(0);
    });

    it('excludes tasks without needs-human label', function (): void {
        $this->taskService->create([
            'title' => 'Task with other labels',
            'labels' => ['bug', 'urgent'],
        ]);
        $this->taskService->create(['title' => 'Task with no labels']);

        $this->artisan('human', [])
            ->expectsOutputToContain('No items need human attention.')
            ->doesntExpectOutputToContain('Task with other labels')
            ->doesntExpectOutputToContain('Task with no labels')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is provided', function (): void {
        $humanTask = $this->taskService->create([
            'title' => 'Needs human task',
            'labels' => ['needs-human'],
        ]);
        $regularTask = $this->taskService->create(['title' => 'Regular task']);

        Artisan::call('human', ['--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toHaveKey('tasks');
        expect($data)->toHaveKey('epics');
        expect($data['tasks'])->toHaveCount(1);
        expect($data['tasks'][0]['short_id'])->toBe($humanTask->short_id);
        expect($data['tasks'][0]['title'])->toBe('Needs human task');
        expect($data['tasks'][0]['status'])->toBe('open');
        expect($data['tasks'][0]['labels'])->toContain('needs-human');
        expect($data['epics'])->toBeArray();
    });

    it('outputs empty arrays as JSON when no human tasks', function (): void {
        $this->taskService->create(['title' => 'Regular task']);

        Artisan::call('human', ['--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toHaveKey('tasks');
        expect($data)->toHaveKey('epics');
        expect($data['tasks'])->toBeEmpty();
        expect($data['epics'])->toBeEmpty();
    });

    it('displays task description when present', function (): void {
        $humanTask = $this->taskService->create([
            'title' => 'Needs human task',
            'description' => 'This task needs human attention',
            'labels' => ['needs-human'],
        ]);

        Artisan::call('human', []);
        $output = Artisan::output();

        expect($output)->toContain('Needs human task');
        expect($output)->toContain('This task needs human attention');
        expect($output)->toContain($humanTask->short_id);
    });

    it('shows count of human tasks', function (): void {
        $this->taskService->create([
            'title' => 'First human task',
            'labels' => ['needs-human'],
        ]);
        $this->taskService->create([
            'title' => 'Second human task',
            'labels' => ['needs-human'],
        ]);

        Artisan::call('human', []);
        $output = Artisan::output();

        expect($output)->toContain('Items needing human attention (2):');
    });

    it('sorts tasks by created_at', function (): void {
        $task1 = $this->taskService->create([
            'title' => 'First task',
            'labels' => ['needs-human'],
        ]);
        sleep(1);
        $task2 = $this->taskService->create([
            'title' => 'Second task',
            'labels' => ['needs-human'],
        ]);

        Artisan::call('human', ['--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toHaveKey('tasks');
        expect($data['tasks'])->toHaveCount(2);
        expect($data['tasks'][0]['short_id'])->toBe($task1->short_id);
        expect($data['tasks'][1]['short_id'])->toBe($task2->short_id);
    });

    it('shows epics with status review_pending', function (): void {
        $epicService = $this->app->make(EpicService::class);

        // Create an epic
        $epic = $epicService->createEpic('Test epic', 'Test description');

        // Create tasks linked to the epic and close them all
        $task1 = $this->taskService->create([
            'title' => 'Task 1',
            'epic_id' => $epic->short_id,
        ]);
        $task2 = $this->taskService->create([
            'title' => 'Task 2',
            'epic_id' => $epic->short_id,
        ]);

        // Close all tasks to make epic review_pending
        $this->taskService->done($task1->short_id);
        $this->taskService->done($task2->short_id);

        // Verify epic status is review_pending
        $epicStatus = $epicService->getEpicStatus($epic->short_id);
        expect($epicStatus->value)->toBe('review_pending');

        // Check that human command shows the epic
        Artisan::call('human', ['--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toHaveKey('epics');
        expect($data['epics'])->toHaveCount(1);
        expect($data['epics'][0]['short_id'])->toBe($epic->short_id);
        expect($data['epics'][0]['status'])->toBe('review_pending');
        expect($data['epics'][0]['title'])->toBe('Test epic');

        // Also check non-JSON output
        Artisan::call('human', []);
        $output = Artisan::output();

        expect($output)->toContain('Test epic');
        expect($output)->toContain($epic->short_id);
    });
});
