<?php

declare(strict_types=1);

use App\Repositories\EpicRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\RunRepository;
use App\Repositories\TaskRepository;
use App\Services\DatabaseService;

beforeEach(function () {
    // Use isolated test database
    $this->testDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->testDir.'/.fuel', 0755, true);
    $this->dbPath = $this->testDir.'/.fuel/test.db';

    $this->db = new DatabaseService($this->dbPath);
    $this->db->initialize();

    // Initialize repositories
    $this->taskRepo = new TaskRepository($this->db);
    $this->epicRepo = new EpicRepository($this->db);
    $this->runRepo = new RunRepository($this->db);
    $this->reviewRepo = new ReviewRepository($this->db);
});

afterEach(function () {
    if (isset($this->testDir) && is_dir($this->testDir)) {
        exec('rm -rf '.escapeshellarg($this->testDir));
    }
});

test('task repository can perform basic CRUD operations', function () {
    // Create
    $taskId = $this->taskRepo->insert([
        'short_id' => 'f-test01',
        'title' => 'Test Task',
        'description' => 'Test Description',
        'status' => 'open',
        'type' => 'task',
        'priority' => 2,
        'complexity' => 'simple',
        'labels' => '[]',
        'blocked_by' => '[]',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ]);

    expect($taskId)->toBeGreaterThan(0);

    // Read by short ID
    $task = $this->taskRepo->findByShortId('f-test01');
    expect($task)->not->toBeNull();
    expect($task['title'])->toBe('Test Task');
    expect($task['description'])->toBe('Test Description');

    // Update by short ID
    $this->taskRepo->updateByShortId('f-test01', [
        'title' => 'Updated Task',
        'priority' => 1,
    ]);

    $updatedTask = $this->taskRepo->findByShortId('f-test01');
    expect($updatedTask['title'])->toBe('Updated Task');
    expect($updatedTask['priority'])->toBe(1);

    // Delete by short ID
    $this->taskRepo->deleteByShortId('f-test01');
    $deletedTask = $this->taskRepo->findByShortId('f-test01');
    expect($deletedTask)->toBeNull();
});

test('task repository can find tasks by status', function () {
    // Create tasks with different statuses
    $this->taskRepo->insert([
        'short_id' => 'f-open01',
        'title' => 'Open Task',
        'status' => 'open',
        'type' => 'task',
        'priority' => 2,
        'complexity' => 'simple',
        'labels' => '[]',
        'blocked_by' => '[]',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ]);

    $this->taskRepo->insert([
        'short_id' => 'f-closed',
        'title' => 'Closed Task',
        'status' => 'closed',
        'type' => 'task',
        'priority' => 2,
        'complexity' => 'simple',
        'labels' => '[]',
        'blocked_by' => '[]',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ]);

    $this->taskRepo->insert([
        'short_id' => 'f-open02',
        'title' => 'Another Open Task',
        'status' => 'open',
        'type' => 'task',
        'priority' => 1,
        'complexity' => 'simple',
        'labels' => '[]',
        'blocked_by' => '[]',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ]);

    // Find open tasks
    $openTasks = $this->taskRepo->getOpenTasks();
    expect(count($openTasks))->toBe(2);

    // Find closed tasks
    $closedTasks = $this->taskRepo->getClosedTasks();
    expect(count($closedTasks))->toBe(1);
    expect($closedTasks[0]['short_id'])->toBe('f-closed');
});

test('repository can handle partial ID matching', function () {
    $this->taskRepo->insert([
        'short_id' => 'f-abc123',
        'title' => 'Test Task',
        'status' => 'open',
        'type' => 'task',
        'priority' => 2,
        'complexity' => 'simple',
        'labels' => '[]',
        'blocked_by' => '[]',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ]);

    // Find with partial match
    $task = $this->taskRepo->findWithPartialMatch('abc1');
    expect($task)->not->toBeNull();
    expect($task['short_id'])->toBe('f-abc123');

    // Find with full ID
    $task = $this->taskRepo->findWithPartialMatch('f-abc123');
    expect($task)->not->toBeNull();
    expect($task['short_id'])->toBe('f-abc123');
});

test('epic repository operations work correctly', function () {
    // Create epic
    $epicId = $this->epicRepo->insert([
        'short_id' => 'e-epic01',
        'title' => 'Test Epic',
        'description' => 'Epic Description',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ]);

    expect($epicId)->toBeGreaterThan(0);

    // Find by short ID
    $epic = $this->epicRepo->findByShortId('e-epic01');
    expect($epic)->not->toBeNull();
    expect($epic['title'])->toBe('Test Epic');

    // Mark as reviewed
    $this->epicRepo->markAsReviewed('e-epic01');
    $reviewedEpic = $this->epicRepo->findByShortId('e-epic01');
    expect($reviewedEpic['reviewed_at'])->not->toBeNull();

    // Mark as approved
    $this->epicRepo->markAsApproved('e-epic01', 'tester');
    $approvedEpic = $this->epicRepo->findByShortId('e-epic01');
    expect($approvedEpic['approved_at'])->not->toBeNull();
    expect($approvedEpic['approved_by'])->toBe('tester');
});

test('run repository can track run status', function () {
    // First create a task
    $taskId = $this->taskRepo->insert([
        'short_id' => 'f-task01',
        'title' => 'Test Task',
        'status' => 'open',
        'type' => 'task',
        'priority' => 2,
        'complexity' => 'simple',
        'labels' => '[]',
        'blocked_by' => '[]',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ]);

    // Create a run
    $runId = $this->runRepo->insert([
        'short_id' => 'run-test1',
        'task_id' => $taskId,
        'agent' => 'test-agent',
        'status' => 'running',
        'started_at' => date('c'),
    ]);

    expect($runId)->toBeGreaterThan(0);

    // Find by task ID
    $runs = $this->runRepo->findByTaskId($taskId);
    expect(count($runs))->toBe(1);
    expect($runs[0]['short_id'])->toBe('run-test1');

    // Update run as completed
    $this->runRepo->markAsCompleted($runId, [
        'ended_at' => date('c'),
        'exit_code' => 0,
        'output' => 'Success',
        'duration_seconds' => 10,
    ]);

    $completedRun = $this->runRepo->find($runId);
    expect($completedRun['status'])->toBe('completed');
    expect($completedRun['exit_code'])->toBe(0);
});

test('review repository operations work correctly', function () {
    // Create task
    $taskId = $this->taskRepo->insert([
        'short_id' => 'f-review1',
        'title' => 'Task to Review',
        'status' => 'closed',
        'type' => 'task',
        'priority' => 2,
        'complexity' => 'simple',
        'labels' => '[]',
        'blocked_by' => '[]',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ]);

    // Create review
    $reviewId = $this->reviewRepo->createReview('r-test01', $taskId, 'reviewer-agent');
    expect($reviewId)->toBeGreaterThan(0);

    // Get pending reviews
    $pendingReviews = $this->reviewRepo->getPendingReviews();
    expect(count($pendingReviews))->toBe(1);

    // Mark as completed
    $this->reviewRepo->markAsCompleted('r-test01', true, ['All tests pass']);

    // Check it's passed
    $passedReviews = $this->reviewRepo->getPassedReviews();
    expect(count($passedReviews))->toBe(1);
    expect($passedReviews[0]['short_id'])->toBe('r-test01');
});

test('repositories provide consistent interface across entities', function () {
    // All repositories should have the same basic interface
    $repositories = [
        'task' => $this->taskRepo,
        'epic' => $this->epicRepo,
        'run' => $this->runRepo,
        'review' => $this->reviewRepo,
    ];

    foreach ($repositories as $name => $repo) {
        // All should have these basic methods from BaseRepository
        expect(method_exists($repo, 'find'))->toBeTrue();
        expect(method_exists($repo, 'findByShortId'))->toBeTrue();
        expect(method_exists($repo, 'all'))->toBeTrue();
        expect(method_exists($repo, 'insert'))->toBeTrue();
        expect(method_exists($repo, 'update'))->toBeTrue();
        expect(method_exists($repo, 'updateByShortId'))->toBeTrue();
        expect(method_exists($repo, 'delete'))->toBeTrue();
        expect(method_exists($repo, 'deleteByShortId'))->toBeTrue();
        expect(method_exists($repo, 'exists'))->toBeTrue();
        expect(method_exists($repo, 'existsByShortId'))->toBeTrue();
        expect(method_exists($repo, 'count'))->toBeTrue();
    }
});

test('repositories can be resolved via dependency injection', function () {
    // Test that all repositories can be resolved from the container
    // This verifies they're registered in AppServiceProvider and can be injected
    $taskRepo = app(TaskRepository::class);
    expect($taskRepo)->toBeInstanceOf(TaskRepository::class);
    expect($taskRepo)->toHaveProperty('db');

    $epicRepo = app(EpicRepository::class);
    expect($epicRepo)->toBeInstanceOf(EpicRepository::class);
    expect($epicRepo)->toHaveProperty('db');

    $runRepo = app(RunRepository::class);
    expect($runRepo)->toBeInstanceOf(RunRepository::class);
    expect($runRepo)->toHaveProperty('db');

    $reviewRepo = app(ReviewRepository::class);
    expect($reviewRepo)->toBeInstanceOf(ReviewRepository::class);
    expect($reviewRepo)->toHaveProperty('db');

    // Verify they can be used for basic operations
    expect(method_exists($taskRepo, 'all'))->toBeTrue();
    expect(method_exists($epicRepo, 'all'))->toBeTrue();
    expect(method_exists($runRepo, 'all'))->toBeTrue();
    expect(method_exists($reviewRepo, 'all'))->toBeTrue();
});
