<?php

use App\Services\DatabaseService;
use App\Services\EpicService;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/Concerns/CommandTestSetup.php';

beforeEach($beforeEach);

afterEach($afterEach);

// =============================================================================
// human Command Tests
// =============================================================================

describe('human command', function (): void {
    it('shows empty when no tasks with needs-human label', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Regular task']);

        $this->artisan('human', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No items need human attention.')
            ->assertExitCode(0);
    });

    it('shows open tasks with needs-human label', function (): void {
        $this->taskService->initialize();
        $humanTask = $this->taskService->create([
            'title' => 'Needs human task',
            'labels' => ['needs-human'],
        ]);
        $regularTask = $this->taskService->create(['title' => 'Regular task']);

        Artisan::call('human', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Needs human task');
        expect($output)->toContain($humanTask['id']);
        expect($output)->not->toContain('Regular task');
    });

    it('excludes closed tasks with needs-human label', function (): void {
        $this->taskService->initialize();
        $humanTask = $this->taskService->create([
            'title' => 'Closed human task',
            'labels' => ['needs-human'],
        ]);
        $this->taskService->done($humanTask['id']);

        $this->artisan('human', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No items need human attention.')
            ->doesntExpectOutputToContain('Closed human task')
            ->assertExitCode(0);
    });

    it('excludes in_progress tasks with needs-human label', function (): void {
        $this->taskService->initialize();
        $humanTask = $this->taskService->create([
            'title' => 'In progress human task',
            'labels' => ['needs-human'],
        ]);
        $this->taskService->start($humanTask['id']);

        $this->artisan('human', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No items need human attention.')
            ->doesntExpectOutputToContain('In progress human task')
            ->assertExitCode(0);
    });

    it('excludes tasks without needs-human label', function (): void {
        $this->taskService->initialize();
        $this->taskService->create([
            'title' => 'Task with other labels',
            'labels' => ['bug', 'urgent'],
        ]);
        $this->taskService->create(['title' => 'Task with no labels']);

        $this->artisan('human', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No items need human attention.')
            ->doesntExpectOutputToContain('Task with other labels')
            ->doesntExpectOutputToContain('Task with no labels')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is provided', function (): void {
        $this->taskService->initialize();
        $humanTask = $this->taskService->create([
            'title' => 'Needs human task',
            'labels' => ['needs-human'],
        ]);
        $regularTask = $this->taskService->create(['title' => 'Regular task']);

        Artisan::call('human', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toHaveKey('tasks');
        expect($data)->toHaveKey('epics');
        expect($data['tasks'])->toHaveCount(1);
        expect($data['tasks'][0]['id'])->toBe($humanTask['id']);
        expect($data['tasks'][0]['title'])->toBe('Needs human task');
        expect($data['tasks'][0]['status'])->toBe('open');
        expect($data['tasks'][0]['labels'])->toContain('needs-human');
        expect($data['epics'])->toBeArray();
    });

    it('outputs empty arrays as JSON when no human tasks', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Regular task']);

        Artisan::call('human', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toHaveKey('tasks');
        expect($data)->toHaveKey('epics');
        expect($data['tasks'])->toBeEmpty();
        expect($data['epics'])->toBeEmpty();
    });

    it('displays task description when present', function (): void {
        $this->taskService->initialize();
        $humanTask = $this->taskService->create([
            'title' => 'Needs human task',
            'description' => 'This task needs human attention',
            'labels' => ['needs-human'],
        ]);

        Artisan::call('human', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Needs human task');
        expect($output)->toContain('This task needs human attention');
        expect($output)->toContain($humanTask['id']);
    });

    it('shows count of human tasks', function (): void {
        $this->taskService->initialize();
        $this->taskService->create([
            'title' => 'First human task',
            'labels' => ['needs-human'],
        ]);
        $this->taskService->create([
            'title' => 'Second human task',
            'labels' => ['needs-human'],
        ]);

        Artisan::call('human', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Items needing human attention (2):');
    });

    it('sorts tasks by created_at', function (): void {
        $this->taskService->initialize();
        $task1 = $this->taskService->create([
            'title' => 'First task',
            'labels' => ['needs-human'],
        ]);
        sleep(1);
        $task2 = $this->taskService->create([
            'title' => 'Second task',
            'labels' => ['needs-human'],
        ]);

        Artisan::call('human', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toHaveKey('tasks');
        expect($data['tasks'])->toHaveCount(2);
        expect($data['tasks'][0]['id'])->toBe($task1['id']);
        expect($data['tasks'][1]['id'])->toBe($task2['id']);
    });

    it('shows epics with status review_pending', function (): void {
        $this->taskService->initialize();
        $dbService = app(DatabaseService::class);
        $epicService = new EpicService($dbService, $this->taskService);

        // Create an epic
        $epic = $epicService->createEpic('Test epic', 'Test description');

        // Create tasks linked to the epic and close them all
        $task1 = $this->taskService->create([
            'title' => 'Task 1',
            'epic_id' => $epic['id'],
        ]);
        $task2 = $this->taskService->create([
            'title' => 'Task 2',
            'epic_id' => $epic['id'],
        ]);

        // Close all tasks to make epic review_pending
        $this->taskService->done($task1['id']);
        $this->taskService->done($task2['id']);

        // Verify epic status is review_pending
        $epicStatus = $epicService->getEpicStatus($epic['id']);
        expect($epicStatus->value)->toBe('review_pending');

        // Check that human command shows the epic
        Artisan::call('human', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toHaveKey('epics');
        expect($data['epics'])->toHaveCount(1);
        expect($data['epics'][0]['id'])->toBe($epic['id']);
        expect($data['epics'][0]['status'])->toBe('review_pending');
        expect($data['epics'][0]['title'])->toBe('Test epic');

        // Also check non-JSON output
        Artisan::call('human', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Test epic');
        expect($output)->toContain($epic['id']);
    });
});
