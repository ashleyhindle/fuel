<?php

declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->tempDir.'/.fuel', 0755, true);

    // Create FuelContext pointing to test directory
    $context = new FuelContext($this->tempDir.'/.fuel');
    $this->app->singleton(FuelContext::class, fn (): FuelContext => $context);

    // Bind our test service instances
    $context->configureDatabase();
    $databaseService = new DatabaseService($context->getDatabasePath());
    $this->app->singleton(DatabaseService::class, fn (): DatabaseService => $databaseService);
    Artisan::call('migrate', ['--force' => true]);
    $this->app->singleton(TaskService::class, fn (): TaskService => makeTaskService());
    $this->app->singleton(EpicService::class, fn (): EpicService => makeEpicService(
        $this->app->make(TaskService::class)
    ));

    $this->databaseService = $this->app->make(DatabaseService::class);
});

afterEach(function (): void {
    // Recursively delete temp directory
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
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    };

    $deleteDir($this->tempDir);
});

describe('epic:approve command', function (): void {
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

    it('approves a single epic', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic', 'Test Description');

        Artisan::call('epic:approve', ['ids' => [$epic->short_id]]);
        $output = Artisan::output();

        expect($output)->toContain(sprintf('Epic %s approved', $epic->short_id));

        // Verify the epic was actually approved
        $updatedEpic = $epicService->getEpic($epic->short_id);
        expect($updatedEpic->approved_at)->not->toBeNull();
        expect($updatedEpic->approved_by)->toBe('human');
    });

    it('approves multiple epics', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic1 = $epicService->createEpic('Epic 1', 'Description 1');
        $epic2 = $epicService->createEpic('Epic 2', 'Description 2');
        $epic3 = $epicService->createEpic('Epic 3', 'Description 3');

        Artisan::call('epic:approve', ['ids' => [$epic1->short_id, $epic2->short_id, $epic3->short_id]]);
        $output = Artisan::output();

        expect($output)->toContain(sprintf('Epic %s approved', $epic1->short_id));
        expect($output)->toContain(sprintf('Epic %s approved', $epic2->short_id));
        expect($output)->toContain(sprintf('Epic %s approved', $epic3->short_id));

        // Verify all epics were approved
        $updatedEpic1 = $epicService->getEpic($epic1->short_id);
        $updatedEpic2 = $epicService->getEpic($epic2->short_id);
        $updatedEpic3 = $epicService->getEpic($epic3->short_id);

        expect($updatedEpic1->approved_at)->not->toBeNull();
        expect($updatedEpic2->approved_at)->not->toBeNull();
        expect($updatedEpic3->approved_at)->not->toBeNull();
    });

    it('shows error when epic not found', function (): void {
        Artisan::call('epic:approve', ['ids' => ['e-nonexistent']]);
        $output = Artisan::output();

        expect($output)->toContain("Epic 'e-nonexistent' not found");
    });

    it('outputs JSON for single epic when --json flag is used', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('JSON Epic', 'JSON Description');

        Artisan::call('epic:approve', ['ids' => [$epic->short_id], '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['short_id'])->toBe($epic->short_id);
        expect($data['title'])->toBe('JSON Epic');
        expect($data['approved_at'])->not->toBeNull();
        expect($data['approved_at'])->toBeString();
        expect($data['approved_by'])->toBe('human');
    });

    it('outputs JSON array for multiple epics when --json flag is used', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic1 = $epicService->createEpic('Epic 1', 'Description 1');
        $epic2 = $epicService->createEpic('Epic 2', 'Description 2');

        Artisan::call('epic:approve', ['ids' => [$epic1->short_id, $epic2->short_id], '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveCount(2);
        expect($data[0]['short_id'])->toBe($epic1->short_id);
        expect($data[1]['short_id'])->toBe($epic2->short_id);
        expect($data[0]['approved_at'])->not->toBeNull();
        expect($data[1]['approved_at'])->not->toBeNull();
    });

    it('supports partial ID matching', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Partial ID Epic');

        // Use partial ID (without e- prefix)
        $partialId = substr((string) $epic->short_id, 2);

        Artisan::call('epic:approve', ['ids' => [$partialId]]);
        $output = Artisan::output();

        expect($output)->toContain(sprintf('Epic %s approved', $epic->short_id));

        // Verify the epic was actually approved
        $updatedEpic = $epicService->getEpic($epic->short_id);
        expect($updatedEpic->approved_at)->not->toBeNull();
    });

    it('uses custom approver when --by option is provided', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic');

        Artisan::call('epic:approve', ['ids' => [$epic->short_id], '--by' => 'admin']);
        $output = Artisan::output();

        expect($output)->toContain(sprintf('Epic %s approved', $epic->short_id));
        expect($output)->toContain('Approved by: admin');

        // Verify the epic was approved by the specified user
        $updatedEpic = $epicService->getEpic($epic->short_id);
        expect($updatedEpic->approved_by)->toBe('admin');
    });

    it('handles mix of valid and invalid epic IDs', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic1 = $epicService->createEpic('Epic 1');

        Artisan::call('epic:approve', ['ids' => [$epic1->short_id, 'e-invalid']]);
        $output = Artisan::output();

        // Should approve the valid one
        expect($output)->toContain(sprintf('Epic %s approved', $epic1->short_id));
        // Should show error for the invalid one
        expect($output)->toContain("Epic 'e-invalid'");

        // Verify the valid epic was approved
        $updatedEpic1 = $epicService->getEpic($epic1->short_id);
        expect($updatedEpic1->approved_at)->not->toBeNull();
    });

    it('clears changes_requested_at when approving', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $epic = $epicService->createEpic('Test Epic');

        // First reject to set changes_requested_at
        $epicService->rejectEpic($epic->short_id, 'Needs work');

        // Verify changes were requested
        $rejectedEpic = $epicService->getEpic($epic->short_id);
        expect($rejectedEpic->changes_requested_at)->not->toBeNull();

        // Now approve
        Artisan::call('epic:approve', ['ids' => [$epic->short_id]]);

        // Verify changes_requested_at is cleared and approved_at is set
        $approvedEpic = $epicService->getEpic($epic->short_id);
        expect($approvedEpic->approved_at)->not->toBeNull();
        expect($approvedEpic->changes_requested_at)->toBeNull();
    });

    it('creates commit task when epic with completed tasks is approved', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->taskService;

        // Create epic and tasks
        $epic = $epicService->createEpic('Feature Epic', 'A feature to implement');
        $task = $taskService->create([
            'title' => 'Task in epic',
            'epic_id' => $epic->short_id,
        ]);

        // Complete the task
        $taskService->done($task->short_id);

        // Approve the epic
        Artisan::call('epic:approve', ['ids' => [$epic->short_id]]);
        $output = Artisan::output();

        // Should show commit task was created
        expect($output)->toContain('Commit task:');

        // Find the commit task
        $allTasks = $taskService->all();
        $commitTask = $allTasks->first(function ($t) {
            $labels = $t->labels ?? [];

            return is_array($labels) && in_array('epic-commit', $labels, true);
        });

        expect($commitTask)->not->toBeNull();
        expect($commitTask->title)->toContain('Commit:');
        expect($commitTask->title)->toContain('Feature Epic');
        expect($commitTask->type)->toBe('chore');
        expect($commitTask->priority)->toBe(0);
        expect($commitTask->complexity)->toBe('moderate');
        expect($commitTask->epic_id)->toBe($epic->id);
    });

    it('does not create commit task when epic has no completed tasks', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->taskService;

        // Create epic without tasks
        $epic = $epicService->createEpic('Empty Epic', 'No tasks');

        // Approve the epic
        Artisan::call('epic:approve', ['ids' => [$epic->short_id]]);
        $output = Artisan::output();

        // Should NOT show commit task
        expect($output)->not->toContain('Commit task:');

        // Verify no commit task was created
        $allTasks = $taskService->all();
        $commitTask = $allTasks->first(function ($t) {
            $labels = $t->labels ?? [];

            return is_array($labels) && in_array('epic-commit', $labels, true);
        });

        expect($commitTask)->toBeNull();
    });

    it('does not create commit task when epic has only open tasks', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->taskService;

        // Create epic with task that is still open
        $epic = $epicService->createEpic('Open Tasks Epic', 'Has open task');
        $taskService->create([
            'title' => 'Open task',
            'epic_id' => $epic->short_id,
        ]);

        // Approve the epic (task still open)
        Artisan::call('epic:approve', ['ids' => [$epic->short_id]]);

        // Verify no commit task was created
        $allTasks = $taskService->all();
        $commitTask = $allTasks->first(function ($t) {
            $labels = $t->labels ?? [];

            return is_array($labels) && in_array('epic-commit', $labels, true);
        });

        expect($commitTask)->toBeNull();
    });

    it('includes commit task info in JSON output', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->taskService;

        // Create epic with completed task
        $epic = $epicService->createEpic('JSON Commit Epic', 'Description');
        $task = $taskService->create([
            'title' => 'Task to complete',
            'epic_id' => $epic->short_id,
        ]);
        $taskService->done($task->short_id);

        // Approve with JSON output
        Artisan::call('epic:approve', ['ids' => [$epic->short_id], '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveKey('commit_task');
        expect($data['commit_task'])->toHaveKey('short_id');
        expect($data['commit_task'])->toHaveKey('title');
        expect($data['commit_task']['title'])->toContain('Commit:');
    });

    it('creates commit task description with task list', function (): void {
        $epicService = $this->app->make(EpicService::class);
        $taskService = $this->taskService;

        // Create epic with multiple completed tasks
        $epic = $epicService->createEpic('Multi-task Epic', 'Description');
        $task1 = $taskService->create([
            'title' => 'First completed task',
            'epic_id' => $epic->short_id,
        ]);
        $task2 = $taskService->create([
            'title' => 'Second completed task',
            'epic_id' => $epic->short_id,
        ]);
        $taskService->done($task1->short_id);
        $taskService->done($task2->short_id);

        // Approve the epic
        Artisan::call('epic:approve', ['ids' => [$epic->short_id]]);

        // Find the commit task
        $allTasks = $taskService->all();
        $commitTask = $allTasks->first(function ($t) {
            $labels = $t->labels ?? [];

            return is_array($labels) && in_array('epic-commit', $labels, true);
        });

        expect($commitTask)->not->toBeNull();
        expect($commitTask->description)->toContain('First completed task');
        expect($commitTask->description)->toContain('Second completed task');
        expect($commitTask->description)->toContain('Multi-task Epic');
    });
});
