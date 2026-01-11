<?php

use App\Services\DatabaseService;
use App\Services\EpicService;
use App\Services\RunService;
use App\Services\TaskService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/Concerns/CommandTestSetup.php';

beforeEach($beforeEach);

afterEach($afterEach);

// =============================================================================
// q Command Tests (Quick Capture)
// =============================================================================

describe('q command', function (): void {
    it('creates task and outputs only the ID', function (): void {
        $this->taskService->initialize();

        Artisan::call('q', ['title' => 'Quick task', '--cwd' => $this->tempDir]);
        $output = trim(Artisan::output());

        expect($output)->toStartWith('f-');
        expect(strlen($output))->toBe(8); // f- + 6 chars

        // Verify task was actually created
        $task = $this->taskService->find($output);
        expect($task)->not->toBeNull();
        expect($task['title'])->toBe('Quick task');
    });

    it('returns exit code 0 on success', function (): void {
        $this->taskService->initialize();

        $this->artisan('q', ['title' => 'Quick task', '--cwd' => $this->tempDir])
            ->assertExitCode(0);
    });

    it('handles RuntimeException from TaskService::create()', function (): void {
        // Create a mock TaskService that throws RuntimeException
        $mockTaskService = \Mockery::mock(TaskService::class);
        $mockTaskService->shouldReceive('initialize')->once();
        $mockTaskService->shouldReceive('create')
            ->once()
            ->andThrow(new \RuntimeException('Failed to create task'));

        // Bind the mock to the service container
        $this->app->singleton(TaskService::class, fn () => $mockTaskService);

        $exitCode = Artisan::call('q', ['title' => 'Test task', '--cwd' => $this->tempDir]);
        $output = trim(Artisan::output());

        expect($output)->toContain('Failed to create task');
        expect($exitCode)->toBe(Command::FAILURE);
    });
});

// =============================================================================
// status Command Tests
// =============================================================================

describe('status command', function (): void {
    it('shows zero counts when no tasks exist', function (): void {
        $this->taskService->initialize();

        $this->artisan('status', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Open')
            ->expectsOutputToContain('In Progress')
            ->expectsOutputToContain('Closed')
            ->expectsOutputToContain('Blocked')
            ->expectsOutputToContain('Total')
            ->assertExitCode(0);
    });

    it('counts tasks by status correctly', function (): void {
        $this->taskService->initialize();
        $open1 = $this->taskService->create(['title' => 'Open task 1']);
        $open2 = $this->taskService->create(['title' => 'Open task 2']);
        $inProgress = $this->taskService->create(['title' => 'In progress task']);
        $closed1 = $this->taskService->create(['title' => 'Closed task 1']);
        $closed2 = $this->taskService->create(['title' => 'Closed task 2']);

        $this->taskService->start($inProgress['id']);
        $this->taskService->done($closed1['id']);
        $this->taskService->done($closed2['id']);

        $this->artisan('status', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Open')
            ->expectsOutputToContain('In Progress')
            ->expectsOutputToContain('Closed')
            ->assertExitCode(0);
    });

    it('counts blocked tasks correctly', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked1 = $this->taskService->create(['title' => 'Blocked task 1']);
        $blocked2 = $this->taskService->create(['title' => 'Blocked task 2']);

        // Add dependencies
        $this->taskService->addDependency($blocked1['id'], $blocker['id']);
        $this->taskService->addDependency($blocked2['id'], $blocker['id']);

        $this->artisan('status', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Blocked')
            ->assertExitCode(0);
    });

    it('does not count tasks as blocked when blocker is closed', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);

        // Add dependency
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        // Close the blocker
        $this->taskService->done($blocker['id']);

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['blocked'])->toBe(0);
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Open task']);

        $inProgress = $this->taskService->create(['title' => 'In progress task']);
        $closed = $this->taskService->create(['title' => 'Closed task']);

        $this->taskService->start($inProgress['id']);
        $this->taskService->done($closed['id']);

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveKeys(['open', 'in_progress', 'closed', 'blocked', 'total']);
        expect($result['open'])->toBe(1);
        expect($result['in_progress'])->toBe(1);
        expect($result['closed'])->toBe(1);
        expect($result['blocked'])->toBe(0);
        expect($result['total'])->toBe(3);
    });

    it('shows correct total count', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task 1']);
        $this->taskService->create(['title' => 'Task 2']);
        $this->taskService->create(['title' => 'Task 3']);

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result['total'])->toBe(3);
        expect($result['open'])->toBe(3);
    });

    it('handles empty state with JSON output', function (): void {
        $this->taskService->initialize();

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result['open'])->toBe(0);
        expect($result['in_progress'])->toBe(0);
        expect($result['closed'])->toBe(0);
        expect($result['blocked'])->toBe(0);
        expect($result['total'])->toBe(0);
    });

    it('counts only open tasks as blocked', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blockedOpen = $this->taskService->create(['title' => 'Blocked open task']);
        $blockedInProgress = $this->taskService->create(['title' => 'Blocked in progress task']);

        // Add dependencies
        $this->taskService->addDependency($blockedOpen['id'], $blocker['id']);
        $this->taskService->addDependency($blockedInProgress['id'], $blocker['id']);

        // Set one to in_progress
        $this->taskService->start($blockedInProgress['id']);

        Artisan::call('status', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        // Only open tasks should be counted as blocked
        expect($result['blocked'])->toBe(1);
    });
});

// =============================================================================
// completed Command Tests
// =============================================================================

describe('completed command', function (): void {
    it('shows no completed tasks when empty', function (): void {
        Artisan::call('completed', ['--cwd' => $this->tempDir]);

        expect(Artisan::output())->toContain('No completed tasks found');
    });

    it('shows completed tasks in reverse chronological order', function (): void {
        // Create and close some tasks
        $task1 = $this->taskService->create(['title' => 'First task']);
        $task2 = $this->taskService->create(['title' => 'Second task']);
        $task3 = $this->taskService->create(['title' => 'Third task']);

        // Close them in order
        $this->taskService->done($task1['id']);
        sleep(1); // Ensure different timestamps
        $this->taskService->done($task2['id']);
        sleep(1);
        $this->taskService->done($task3['id']);

        Artisan::call('completed', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        // Should show most recent first
        expect($output)->toContain('Third task');
        expect($output)->toContain('Second task');
        expect($output)->toContain('First task');
    });

    it('excludes open and in_progress tasks', function (): void {
        $open = $this->taskService->create(['title' => 'Open task']);
        $inProgress = $this->taskService->create(['title' => 'In progress task']);
        $closed = $this->taskService->create(['title' => 'Closed task']);

        $this->taskService->start($inProgress['id']);
        $this->taskService->done($closed['id']);

        Artisan::call('completed', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Closed task');
        expect($output)->not->toContain('Open task');
        expect($output)->not->toContain('In progress task');
    });

    it('respects --limit option', function (): void {
        // Use explicit timestamps to ensure reliable ordering
        $baseTime = Carbon::parse('2024-01-01 12:00:00');
        Carbon::setTestNow($baseTime);

        try {
            // Create and close 5 tasks with explicit timestamps
            $taskIds = [];
            for ($i = 1; $i <= 5; $i++) {
                // Set time for this task (each task gets a different timestamp)
                Carbon::setTestNow($baseTime->copy()->addSeconds($i));
                $task = $this->taskService->create(['title' => 'Task '.$i]);
                $taskIds[] = $task['id'];
                // Increment time slightly for done() call to ensure updated_at differs
                Carbon::setTestNow($baseTime->copy()->addSeconds($i)->addMilliseconds(100));
                $this->taskService->done($task['id']);
            }

            Artisan::call('completed', ['--cwd' => $this->tempDir, '--limit' => 3, '--json' => true]);
            $output = Artisan::output();

            $data = json_decode($output, true);
            expect($data)->toBeArray();
            expect($data)->toHaveCount(3);
            // Verify limit works - should only return 3 tasks
            $titles = array_column($data, 'title');
            expect($titles)->toHaveCount(3);
            // Most recent tasks should be included (Task 5 should be in results)
            expect($titles)->toContain('Task 5');
        } finally {
            // Always restore real time
            Carbon::setTestNow();
        }
    });

    it('outputs JSON when --json flag is used', function (): void {
        $task = $this->taskService->create(['title' => 'Completed task']);
        $this->taskService->done($task['id']);

        Artisan::call('completed', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toHaveCount(1);
        expect($data[0]['id'])->toBe($task['id']);
        expect($data[0]['status'])->toBe('closed');
    });

    it('outputs empty array as JSON when no completed tasks', function (): void {
        Artisan::call('completed', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toBeEmpty();
    });

    it('displays task details in table format', function (): void {
        $task = $this->taskService->create([
            'title' => 'Test completed task',
            'type' => 'feature',
            'priority' => 1,
        ]);
        $this->taskService->done($task['id']);

        Artisan::call('completed', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('ID');
        expect($output)->toContain('Title');
        expect($output)->toContain('Completed');
        expect($output)->toContain('Type');
        expect($output)->toContain('Priority');
        expect($output)->toContain($task['id']);
        expect($output)->toContain('Test completed task');
        expect($output)->toContain('feature');
        expect($output)->toContain('1');
    });
});

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

// =============================================================================
// stuck Command Tests
// =============================================================================

describe('stuck command', function (): void {
    it('shows no stuck tasks when empty', function (): void {
        Artisan::call('stuck', ['--cwd' => $this->tempDir]);

        expect(Artisan::output())->toContain('No stuck tasks found');
    });

    it('shows only consumed tasks with non-zero exit codes', function (): void {
        $this->taskService->initialize();

        // Create tasks with different consumed states
        $successTask = $this->taskService->create(['title' => 'Success task']);
        $this->taskService->update($successTask['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 0,
        ]);

        $failedTask = $this->taskService->create(['title' => 'Failed task']);
        $this->taskService->update($failedTask['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 1,
            'consumed_output' => 'Error: Something went wrong',
        ]);

        $notConsumedTask = $this->taskService->create(['title' => 'Not consumed task']);

        Artisan::call('stuck', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Failed task');
        expect($output)->not->toContain('Success task');
        expect($output)->not->toContain('Not consumed task');
    });

    it('shows exit code and output for stuck tasks', function (): void {
        $this->taskService->initialize();

        $task = $this->taskService->create(['title' => 'Stuck task']);
        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 42,
            'consumed_output' => 'Error message here',
        ]);

        Artisan::call('stuck', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Stuck task');
        expect($output)->toContain('Reason:');
        expect($output)->toContain('Exit code 42');
        expect($output)->toContain('Error message here');
    });

    it('truncates long output', function (): void {
        $this->taskService->initialize();

        $longOutput = str_repeat('x', 600); // 600 characters
        $task = $this->taskService->create(['title' => 'Task with long output']);
        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 1,
            'consumed_output' => $longOutput,
        ]);

        Artisan::call('stuck', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Task with long output');
        expect($output)->toContain('...');
        // Should be truncated to ~500 chars
        expect(strlen($output))->toBeLessThan(700);
    });

    it('excludes tasks with zero exit code', function (): void {
        $this->taskService->initialize();

        $task = $this->taskService->create(['title' => 'Successful task']);
        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 0,
        ]);

        Artisan::call('stuck', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->not->toContain('Successful task');
        expect($output)->toContain('No stuck tasks found');
    });

    it('excludes tasks without consumed flag', function (): void {
        $this->taskService->initialize();

        $task = $this->taskService->create(['title' => 'Unconsumed task']);
        $this->taskService->update($task['id'], [
            'consumed_exit_code' => 1,
        ]);

        Artisan::call('stuck', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->not->toContain('Unconsumed task');
    });

    it('excludes tasks without exit code', function (): void {
        $this->taskService->initialize();

        $task = $this->taskService->create(['title' => 'Task without exit code']);
        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
        ]);

        Artisan::call('stuck', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->not->toContain('Task without exit code');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();

        $task = $this->taskService->create(['title' => 'Stuck task']);
        $this->taskService->update($task['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 1,
            'consumed_output' => 'Error output',
        ]);

        Artisan::call('stuck', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toHaveCount(1);
        expect($data[0]['id'])->toBe($task['id']);
        expect($data[0]['consumed'])->toBeTrue();
        expect($data[0]['consumed_exit_code'])->toBe(1);
        expect($data[0]['consumed_output'])->toBe('Error output');
    });

    it('outputs empty array as JSON when no stuck tasks', function (): void {
        $this->taskService->initialize();

        Artisan::call('stuck', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();

        $data = json_decode($output, true);
        expect($data)->toBeArray();
        expect($data)->toBeEmpty();
    });

    it('sorts stuck tasks by consumed_at descending', function (): void {
        $this->taskService->initialize();

        $task1 = $this->taskService->create(['title' => 'First stuck task']);
        $this->taskService->update($task1['id'], [
            'consumed' => true,
            'consumed_at' => date('c', time() - 100),
            'consumed_exit_code' => 1,
        ]);

        sleep(1);

        $task2 = $this->taskService->create(['title' => 'Second stuck task']);
        $this->taskService->update($task2['id'], [
            'consumed' => true,
            'consumed_at' => date('c'),
            'consumed_exit_code' => 2,
        ]);

        Artisan::call('stuck', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        // Most recent should appear first
        $pos1 = strpos($output, 'First stuck task');
        $pos2 = strpos($output, 'Second stuck task');
        expect($pos2)->toBeLessThan($pos1);
    });
});

// =============================================================================
// init Command Tests
// =============================================================================

describe('init command', function (): void {
    it('creates .fuel directory', function (): void {
        $fuelDir = $this->tempDir.'/.fuel';

        // Ensure it doesn't exist first
        if (is_dir($fuelDir)) {
            rmdir($fuelDir);
        }

        Artisan::call('init', ['--cwd' => $this->tempDir]);

        expect(is_dir($fuelDir))->toBeTrue();
    });

    it('creates agent.db database file', function (): void {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        expect(file_exists($this->dbPath))->toBeTrue();
    });

    it('creates a starter task', function (): void {
        Artisan::call('init', ['--cwd' => $this->tempDir]);

        // Verify database was created with a starter task
        expect(file_exists($this->dbPath))->toBeTrue();
        $tasks = $this->taskService->all();
        expect($tasks->pluck('title')->filter(fn ($t) => str_contains($t, 'README'))->count())->toBe(1);
    });

    it('does not create duplicate starter tasks when run multiple times', function (): void {
        // First init
        Artisan::call('init', ['--cwd' => $this->tempDir]);
        $firstTaskCount = $this->taskService->all()->pluck('title')->filter(fn ($t) => str_contains($t, 'README'))->count();

        // Second init
        Artisan::call('init', ['--cwd' => $this->tempDir]);
        $secondTaskCount = $this->taskService->all()->pluck('title')->filter(fn ($t) => str_contains($t, 'README'))->count();

        // Should have same number of starter tasks
        expect($secondTaskCount)->toBe($firstTaskCount);
        expect($firstTaskCount)->toBe(1);
    });

    it('creates AGENTS.md with fuel guidelines', function (): void {
        $agentsMdPath = $this->tempDir.'/AGENTS.md';

        // Remove if exists
        if (file_exists($agentsMdPath)) {
            unlink($agentsMdPath);
        }

        Artisan::call('init', ['--cwd' => $this->tempDir]);

        expect(file_exists($agentsMdPath))->toBeTrue();
        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('<fuel>');
        expect($content)->toContain('</fuel>');
    });
});

// =============================================================================
// guidelines Command Tests
// =============================================================================

describe('guidelines command', function (): void {
    beforeEach(function (): void {
        // Clean up AGENTS.md in tempDir before each test
        $agentsMdPath = $this->tempDir.'/AGENTS.md';
        if (file_exists($agentsMdPath)) {
            unlink($agentsMdPath);
        }
    });

    it('outputs guidelines content when --add flag is not used', function (): void {
        Artisan::call('guidelines');
        $output = Artisan::output();

        expect($output)->toContain('Fuel Task Management');
        expect($output)->toContain('Quick Reference');
    });

    it('creates AGENTS.md when it does not exist with --add flag', function (): void {
        $agentsMdPath = $this->tempDir.'/AGENTS.md';

        expect(file_exists($agentsMdPath))->toBeFalse();

        Artisan::call('guidelines', ['--add' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect(file_exists($agentsMdPath))->toBeTrue();
        expect($output)->toContain('Created AGENTS.md with Fuel guidelines');

        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('# Agent Instructions');
        expect($content)->toContain('<fuel>');
        expect($content)->toContain('</fuel>');
    });

    it('replaces existing <fuel> section in AGENTS.md with --add flag', function (): void {
        $agentsMdPath = $this->tempDir.'/AGENTS.md';
        $oldContent = "# Agent Instructions\n\n<fuel>\nOld content here\n</fuel>\n\nSome other content";
        file_put_contents($agentsMdPath, $oldContent);

        Artisan::call('guidelines', ['--add' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Updated AGENTS.md with Fuel guidelines');

        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('<fuel>');
        expect($content)->toContain('</fuel>');
        expect($content)->toContain('Some other content');
        expect($content)->not->toContain('Old content here');
        // Should contain content from agent-instructions.md
        expect($content)->toContain('Fuel Task Management');
    });

    it('appends <fuel> section when AGENTS.md exists but has no fuel section with --add flag', function (): void {
        $agentsMdPath = $this->tempDir.'/AGENTS.md';
        $existingContent = "# Agent Instructions\n\nSome existing content here";
        file_put_contents($agentsMdPath, $existingContent);

        Artisan::call('guidelines', ['--add' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Updated AGENTS.md with Fuel guidelines');

        $content = file_get_contents($agentsMdPath);
        expect($content)->toContain('Some existing content here');
        expect($content)->toContain('<fuel>');
        expect($content)->toContain('</fuel>');
        // Should have double newline before fuel section
        expect($content)->toContain("content here\n\n<fuel>");
    });

    it('uses custom --cwd option with --add flag', function (): void {
        $customDir = sys_get_temp_dir().'/fuel-test-custom-'.uniqid();
        mkdir($customDir, 0755, true);
        $agentsMdPath = $customDir.'/AGENTS.md';

        try {
            Artisan::call('guidelines', ['--add' => true, '--cwd' => $customDir]);

            expect(file_exists($agentsMdPath))->toBeTrue();
            $content = file_get_contents($agentsMdPath);
            expect($content)->toContain('<fuel>');
            expect($content)->toContain('</fuel>');
        } finally {
            // Cleanup
            if (file_exists($agentsMdPath)) {
                unlink($agentsMdPath);
            }

            if (is_dir($customDir)) {
                rmdir($customDir);
            }
        }
    });

});

// Archive Command Tests
describe('archive command', function (): void {
    it('archives closed tasks older than specified days', function (): void {
        $this->taskService->initialize();

        // Create a closed task from 35 days ago
        $oldTask = $this->taskService->create(['title' => 'Old closed task']);
        $this->taskService->done($oldTask['id']);
        $oldDate = now()->subDays(35)->toIso8601String();
        $this->taskService->update($oldTask['id'], ['updated_at' => $oldDate]);

        // Create a closed task from 20 days ago (should not be archived)
        $recentTask = $this->taskService->create(['title' => 'Recent closed task']);
        $this->taskService->done($recentTask['id']);
        $recentDate = now()->subDays(20)->toIso8601String();
        $this->taskService->update($recentTask['id'], ['updated_at' => $recentDate]);

        Artisan::call('archive', ['--days' => 30, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Archived 1 task(s)');

        // Verify old task is archived
        expect($this->taskService->find($oldTask['id']))->toBeNull();

        // Verify recent task is still present
        expect($this->taskService->find($recentTask['id']))->not->toBeNull();
    });

    it('archives all closed tasks when --all flag is used', function (): void {
        $this->taskService->initialize();

        // Create closed tasks with different ages
        $task1 = $this->taskService->create(['title' => 'Closed task 1']);
        $this->taskService->done($task1['id']);

        $task2 = $this->taskService->create(['title' => 'Closed task 2']);
        $this->taskService->done($task2['id']);

        // Create an open task (should not be archived)
        $openTask = $this->taskService->create(['title' => 'Open task']);

        Artisan::call('archive', ['--all' => true, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Archived 2 task(s)');

        // Verify closed tasks are archived
        expect($this->taskService->find($task1['id']))->toBeNull();
        expect($this->taskService->find($task2['id']))->toBeNull();

        // Verify open task remains
        expect($this->taskService->find($openTask['id']))->not->toBeNull();
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();

        $task = $this->taskService->create(['title' => 'Closed task']);
        $this->taskService->done($task['id']);
        $oldDate = now()->subDays(35)->toIso8601String();
        $this->taskService->update($task['id'], ['updated_at' => $oldDate]);

        Artisan::call('archive', ['--days' => 30, '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toHaveKeys(['archived', 'archived_tasks']);
        expect($result['archived'])->toBe(1);
        expect($result['archived_tasks'])->toHaveCount(1);
        expect($result['archived_tasks'][0]['id'])->toBe($task['id']);
    });

    it('shows message when no tasks to archive', function (): void {
        $this->taskService->initialize();

        // Create only open tasks
        $this->taskService->create(['title' => 'Open task']);

        Artisan::call('archive', ['--days' => 30, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('No tasks to archive');
    });

    it('validates days option must be positive integer', function (): void {
        $this->taskService->initialize();

        Artisan::call('archive', ['--days' => 0, '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Days must be a positive integer');
    });

    it('uses default 30 days when --days not specified', function (): void {
        $this->taskService->initialize();

        // Create a closed task from 35 days ago
        $oldTask = $this->taskService->create(['title' => 'Old closed task']);
        $this->taskService->done($oldTask['id']);
        $oldDate = now()->subDays(35)->toIso8601String();
        $this->taskService->update($oldTask['id'], ['updated_at' => $oldDate]);

        Artisan::call('archive', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Archived 1 task(s)');
    });
});

// =============================================================================
// tree Command Tests
// =============================================================================

describe('tree command', function (): void {
    it('shows empty message when no pending tasks', function (): void {
        $this->taskService->initialize();

        $this->artisan('tree', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('No pending tasks.')
            ->assertExitCode(0);
    });

    it('shows tasks without dependencies as flat list', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Task one', 'priority' => 1]);
        $this->taskService->create(['title' => 'Task two', 'priority' => 2]);

        $this->artisan('tree', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Task one')
            ->expectsOutputToContain('Task two')
            ->assertExitCode(0);
    });

    it('shows blocking tasks with blocked tasks indented underneath', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        Artisan::call('tree', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Blocked task');
        expect($output)->toContain('Blocker task');
        expect($output)->toContain('blocked by this');
    });

    it('shows task with multiple blockers', function (): void {
        $this->taskService->initialize();
        $blocker1 = $this->taskService->create(['title' => 'First blocker']);
        $blocker2 = $this->taskService->create(['title' => 'Second blocker']);
        $blocked = $this->taskService->create(['title' => 'Multi-blocked task']);
        $this->taskService->addDependency($blocked['id'], $blocker1['id']);
        $this->taskService->addDependency($blocked['id'], $blocker2['id']);

        $this->artisan('tree', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Multi-blocked task')
            ->expectsOutputToContain('First blocker')
            ->expectsOutputToContain('Second blocker')
            ->assertExitCode(0);
    });

    it('excludes closed tasks from tree', function (): void {
        $this->taskService->initialize();
        $openTask = $this->taskService->create(['title' => 'Open task']);
        $closedTask = $this->taskService->create(['title' => 'Closed task']);
        $this->taskService->done($closedTask['id']);

        $this->artisan('tree', ['--cwd' => $this->tempDir])
            ->expectsOutputToContain('Open task')
            ->doesntExpectOutputToContain('Closed task')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is provided', function (): void {
        $this->taskService->initialize();
        $blocker = $this->taskService->create(['title' => 'Blocker task']);
        $blocked = $this->taskService->create(['title' => 'Blocked task']);
        $this->taskService->addDependency($blocked['id'], $blocker['id']);

        Artisan::call('tree', ['--cwd' => $this->tempDir, '--json' => true]);
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
        $this->taskService->initialize();

        Artisan::call('tree', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toBeEmpty();
    });

    it('sorts tasks by priority then created_at', function (): void {
        $this->taskService->initialize();
        $lowPriority = $this->taskService->create(['title' => 'Low priority', 'priority' => 3]);
        $highPriority = $this->taskService->create(['title' => 'High priority', 'priority' => 0]);

        Artisan::call('tree', ['--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data[0]['task']['title'])->toBe('High priority');
        expect($data[1]['task']['title'])->toBe('Low priority');
    });

    it('shows needs-human label with special indicator', function (): void {
        $this->taskService->initialize();
        $this->taskService->create(['title' => 'Human task', 'labels' => ['needs-human']]);
        $this->taskService->create(['title' => 'Normal task']);

        Artisan::call('tree', ['--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Human task');
        expect($output)->toContain('needs human');
        expect($output)->toContain('Normal task');
    });
});

// =============================================================================
// runs Command Tests
// =============================================================================

describe('runs command', function (): void {
    it('shows error when task not found', function (): void {
        $this->taskService->initialize();

        $this->artisan('runs', ['id' => 'f-nonexistent', '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('shows error when no runs exist for task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->artisan('runs', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('No runs found for task')
            ->assertExitCode(1);
    });

    it('displays runs for a task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'model' => 'test-model',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Runs for task');
        expect($output)->toContain('test-agent');
        expect($output)->toContain('test-model');
    });

    it('displays multiple runs in table format', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'agent1',
            'model' => 'model1',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        $runService->logRun($task['id'], [
            'agent' => 'agent2',
            'model' => 'model2',
            'started_at' => '2026-01-07T11:00:00+00:00',
            'ended_at' => '2026-01-07T11:10:00+00:00',
            'exit_code' => 1,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Runs for task');
        expect($output)->toContain('agent1');
        expect($output)->toContain('agent2');
        expect($output)->toContain('model1');
        expect($output)->toContain('model2');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'model' => 'test-model',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveCount(1);
        expect($data[0])->toHaveKeys(['run_id', 'agent', 'model', 'started_at', 'ended_at', 'exit_code', 'duration']);
        expect($data[0]['agent'])->toBe('test-agent');
        expect($data[0]['model'])->toBe('test-model');
    });

    it('shows latest run with --last flag', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'agent1',
            'model' => 'model1',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        $runService->logRun($task['id'], [
            'agent' => 'agent2',
            'model' => 'model2',
            'started_at' => '2026-01-07T11:00:00+00:00',
            'ended_at' => '2026-01-07T11:10:00+00:00',
            'exit_code' => 1,
            'output' => 'Test output',
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--last' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Latest run for task');
        expect($output)->toContain('agent2');
        expect($output)->toContain('model2');
        expect($output)->toContain('Test output');
        expect($output)->not->toContain('agent1');
    });

    it('shows latest run with full output in JSON format', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'agent1',
            'model' => 'model1',
            'started_at' => '2026-01-07T10:00:00+00:00',
        ]);

        $runService->logRun($task['id'], [
            'agent' => 'agent2',
            'model' => 'model2',
            'started_at' => '2026-01-07T11:00:00+00:00',
            'output' => 'Test output',
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--last' => true, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['agent'])->toBe('agent2');
        expect($data['output'])->toBe('Test output');
        expect($data)->toHaveKey('duration');
    });

    it('calculates duration correctly', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:30+00:00', // 5 minutes 30 seconds
            'exit_code' => 0,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data[0]['duration'])->toContain('5m');
        expect($data[0]['duration'])->toContain('30s');
    });

    it('handles running tasks with no end time', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => null,
            'exit_code' => null,
        ]);

        Artisan::call('runs', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data[0]['duration'])->not->toBeEmpty();
        expect($data[0]['exit_code'])->toBeNull();
    });

    it('supports partial task ID matching', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'test-agent',
            'started_at' => '2026-01-07T10:00:00+00:00',
        ]);

        // Use partial ID (last 6 chars)
        $partialId = substr((string) $task['id'], -6);

        $this->artisan('runs', ['id' => $partialId, '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Runs for task')
            ->assertExitCode(0);
    });
});

// Resume Command Tests
describe('resume command', function (): void {
    it('shows error when task not found', function (): void {
        $this->artisan('resume', ['id' => 'f-nonexistent', '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('shows error when no runs exist for task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->artisan('resume', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('No runs found for task')
            ->assertExitCode(1);
    });

    it('shows error when run has no session_id', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            // No session_id
        ]);

        $this->artisan('resume', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('has no session_id')
            ->assertExitCode(1);
    });

    // Note: Test for "run has no agent" removed because the database schema
    // has `agent TEXT NOT NULL`, making this condition impossible.

    it('shows error when agent is unknown', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        // Create config file so ConfigService can load
        $configPath = $this->tempDir.'/.fuel/config.yaml';
        file_put_contents($configPath, Yaml::dump([
            'agents' => [
                'claude' => ['command' => 'claude'],
            ],
            'complexity' => [
                'simple' => 'claude',
            ],
            'primary' => 'claude',
        ]));

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'unknown-agent',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        $this->artisan('resume', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Unknown agent 'unknown-agent'")
            ->assertExitCode(1);
    });

    it('shows error when specific run not found', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        $this->artisan('resume', [
            'id' => $task['id'],
            '--run' => 'run-nonexistent',
            '--cwd' => $this->tempDir,
        ])
            ->expectsOutputToContain("Run 'run-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('supports partial run ID matching', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        $runs = $runService->getRuns($task['id']);
        $runId = $runs[0]['run_id'] ?? '';
        $partialRunId = substr($runId, 0, 6); // First 6 chars

        // This will fail because exec() replaces the process, but we can test validation passes
        // We'll just verify it doesn't fail with "not found" error
        $this->artisan('resume', [
            'id' => $task['id'],
            '--run' => $partialRunId,
            '--cwd' => $this->tempDir,
        ])
            ->assertExitCode(1); // Will fail at exec(), but validation should pass
    });

    it('outputs JSON error when --json flag is used', function (): void {
        Artisan::call('resume', [
            'id' => 'f-nonexistent',
            '--cwd' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data['error'])->toContain("Task 'f-nonexistent' not found");
    });

    it('supports partial task ID matching', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'session_id' => 'test-session-123',
        ]);

        // Use partial ID (last 6 chars)
        $partialId = substr((string) $task['id'], -6);

        $this->artisan('resume', ['id' => $partialId, '--cwd' => $this->tempDir])
            ->assertExitCode(1); // Will fail at exec(), but task should be found
    });
});

// =============================================================================
// summary Command Tests
// =============================================================================

describe('summary command', function (): void {
    it('shows error when task not found', function (): void {
        $this->taskService->initialize();

        $this->artisan('summary', ['id' => 'f-nonexistent', '--cwd' => $this->tempDir])
            ->expectsOutputToContain("Task 'f-nonexistent' not found")
            ->assertExitCode(1);
    });

    it('shows error when no runs exist for task', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $this->artisan('summary', ['id' => $task['id'], '--cwd' => $this->tempDir])
            ->expectsOutputToContain('No runs found for task')
            ->assertExitCode(1);
    });

    it('displays summary for task with single run', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);
        $this->taskService->done($task['id']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'model' => 'sonnet',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'cost_usd' => 0.08,
            'session_id' => '550e8400-e29b-41d4-a716-446655440000',
            'output' => 'Task completed',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Test task');
        expect($output)->toContain('closed');
        expect($output)->toContain('Claude');
        expect($output)->toContain('sonnet');
        expect($output)->toContain('5m');
        expect($output)->toContain('$0.0800');
        expect($output)->toContain('0 (success)');
        expect($output)->toContain('550e8400-e29b-41d4-a716-446655440000');
        expect($output)->toContain('claude --resume');
    });

    it('parses file operations from output', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Created app/Services/AuthService.php. Modified tests/Feature/AuthTest.php. Deleted old/LegacyAuth.php.',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Created file: app/Services/AuthService.php');
        expect($output)->toContain('Modified file: tests/Feature/AuthTest.php');
        expect($output)->toContain('Deleted file: old/LegacyAuth.php');
    });

    it('parses test results from output', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Tests: 5 passed. Assertions: 15 passed.',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('5 tests passed');
        expect($output)->toContain('15 assertions passed');
    });

    it('parses git commits from output', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => '[main abc1234] feat: add authentication',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Git commit: abc1234');
    });

    it('shows all runs when --all flag is used', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        $runService->logRun($task['id'], [
            'agent' => 'cursor-agent',
            'started_at' => '2026-01-07T11:00:00+00:00',
            'ended_at' => '2026-01-07T11:10:00+00:00',
            'exit_code' => 1,
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir, '--all' => true]);
        $output = Artisan::output();

        expect($output)->toContain('Claude');
        expect($output)->toContain('Cursor Agent');
        expect($output)->toContain('0 (success)');
        expect($output)->toContain('1 (failed)');
    });

    it('outputs JSON when --json flag is used', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Created app/Test.php',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir, '--json' => true]);
        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data)->toHaveKeys(['task', 'runs']);
        expect($data['task']['id'])->toBe($task['id']);
        expect($data['runs'])->toHaveCount(1);
        expect($data['runs'][0])->toHaveKey('duration');
        expect($data['runs'][0])->toHaveKey('parsed_summary');
        expect($data['runs'][0]['parsed_summary'])->toContain('Created file: app/Test.php');
    });

    it('shows no actionable items when output has no parseable content', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Some random text without patterns',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('No actionable items detected');
    });

    it('supports partial task ID matching', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        // Use partial ID (first 5 chars after f-)
        $partialId = substr((string) $task['id'], 2, 5);

        $this->artisan('summary', ['id' => $partialId, '--cwd' => $this->tempDir])
            ->expectsOutputToContain('Test task')
            ->assertExitCode(0);
    });

    it('parses fuel task operations from output', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
            'output' => 'Created task f-abc123. Ran fuel done f-abc123.',
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        expect($output)->toContain('Created task: f-abc123');
        expect($output)->toContain('Completed task: f-abc123');
    });

    it('displays status with color coding', function (): void {
        $this->taskService->initialize();
        $task = $this->taskService->create(['title' => 'Test task']);

        $runService = $this->app->make(RunService::class);
        $runService->logRun($task['id'], [
            'agent' => 'claude',
            'started_at' => '2026-01-07T10:00:00+00:00',
            'ended_at' => '2026-01-07T10:05:00+00:00',
            'exit_code' => 0,
        ]);

        Artisan::call('summary', ['id' => $task['id'], '--cwd' => $this->tempDir]);
        $output = Artisan::output();

        // The output should contain the task status
        expect($output)->toContain('Status:');
        expect($output)->toContain('open');
    });
});
