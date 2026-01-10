<?php

declare(strict_types=1);

use App\Contracts\ProcessManagerInterface;
use App\Process\Process;
use App\Process\ProcessOutput;
use App\Process\ProcessStatus;
use App\Prompts\ReviewPrompt;
use App\Services\ConfigService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\ReviewService;
use App\Services\RunService;
use App\Services\TaskService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    // Create FuelContext for test directory
    $this->context = new FuelContext($this->testDir.'/.fuel');

    // Create config file in test directory
    $this->configPath = $this->context->getConfigPath();
    $configContent = <<<'YAML'
primary: test-agent

agents:
  test-agent:
    command: echo
    prompt_args: ["-p"]
    model: test-model
    args: []
    env: {}
    resume_args: []
    max_concurrent: 2
    max_attempts: 3

  review-agent:
    command: echo
    prompt_args: ["-p"]
    model: review-model
    args: []
    env: {}
    resume_args: []
    max_concurrent: 2
    max_attempts: 3

complexity:
  trivial: test-agent
  simple: test-agent
  moderate: test-agent
  complex: test-agent

review_agent: review-agent
YAML;
    file_put_contents($this->configPath, $configContent);

    // Create database service for test directory and initialize it
    $this->databaseService = new DatabaseService($this->context->getDatabasePath());
    $this->databaseService->initialize();

    $this->taskService = new TaskService($this->databaseService);
    $this->configService = new ConfigService($this->context);
    $this->reviewPrompt = new ReviewPrompt;
    $this->processManager = Mockery::mock(ProcessManagerInterface::class);
    $this->runService = new RunService($this->context->getRunsPath());
});

afterEach(function (): void {
    Mockery::close();
});

it('triggers a review by spawning a process', function (): void {
    // Create a task to review
    $task = $this->taskService->create([
        'title' => 'Test task',
        'description' => 'A task to test review',
    ]);
    $taskId = $task['id'];

    // Set expectation on process manager to spawn a review process
    $this->processManager
        ->shouldReceive('spawn')
        ->once()
        ->withArgs(function ($reviewTaskId, $agent, $command, $cwd) use ($taskId) {
            return $reviewTaskId === 'review-'.$taskId
                && $agent === 'review-agent'
                && str_contains($command, 'echo')
                && $cwd === getcwd();
        })
        ->andReturn(new Process(
            id: 'p-test01',
            taskId: 'review-'.$taskId,
            agent: 'review-agent',
            command: 'echo test',
            cwd: getcwd(),
            pid: 12345,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable
        ));

    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    $reviewService->triggerReview($taskId, 'test-agent');

    // Verify task status was updated to review
    $updatedTask = $this->taskService->find($taskId);
    expect($updatedTask['status'])->toBe('review');
});

it('updates task status to review when triggering review', function (): void {
    // Create a task
    $task = $this->taskService->create([
        'title' => 'Status test task',
    ]);
    $taskId = $task['id'];

    // Start the task (simulate work being done)
    $this->taskService->start($taskId);
    $startedTask = $this->taskService->find($taskId);
    expect($startedTask['status'])->toBe('in_progress');

    // Mock process manager
    $this->processManager
        ->shouldReceive('spawn')
        ->once()
        ->andReturn(new Process(
            id: 'p-test01',
            taskId: 'review-'.$taskId,
            agent: 'review-agent',
            command: 'echo test',
            cwd: getcwd(),
            pid: 12345,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable
        ));

    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    $reviewService->triggerReview($taskId, 'test-agent');

    // Verify status is now 'review'
    $reviewedTask = $this->taskService->find($taskId);
    expect($reviewedTask['status'])->toBe('review');
});

it('returns correct pending reviews', function (): void {
    // Create two tasks
    $task1 = $this->taskService->create(['title' => 'Task 1']);
    $task2 = $this->taskService->create(['title' => 'Task 2']);

    // Mock process manager for both spawns
    $this->processManager
        ->shouldReceive('spawn')
        ->twice()
        ->andReturn(new Process(
            id: 'p-test',
            taskId: 'review-test',
            agent: 'review-agent',
            command: 'echo test',
            cwd: getcwd(),
            pid: 12345,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable
        ));

    // Mock isRunning for pending review checks
    $this->processManager
        ->shouldReceive('isRunning')
        ->andReturn(true);

    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    // Initially no pending reviews
    expect($reviewService->getPendingReviews())->toBeEmpty();

    // Trigger reviews for both tasks
    $reviewService->triggerReview($task1['id'], 'test-agent');
    $reviewService->triggerReview($task2['id'], 'test-agent');

    // Should have both tasks pending
    $pending = $reviewService->getPendingReviews();
    expect($pending)->toHaveCount(2);
    expect($pending)->toContain($task1['id']);
    expect($pending)->toContain($task2['id']);
});

it('returns true for isReviewComplete when process finished', function (): void {
    // Create a task
    $task = $this->taskService->create(['title' => 'Completion test task']);
    $taskId = $task['id'];

    // Mock spawn
    $this->processManager
        ->shouldReceive('spawn')
        ->once()
        ->andReturn(new Process(
            id: 'p-test01',
            taskId: 'review-'.$taskId,
            agent: 'review-agent',
            command: 'echo test',
            cwd: getcwd(),
            pid: 12345,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable
        ));

    // First call: process still running
    // Second call: process completed
    $this->processManager
        ->shouldReceive('isRunning')
        ->with('review-'.$taskId)
        ->andReturn(true, false);

    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    $reviewService->triggerReview($taskId, 'test-agent');

    // First check - still running
    expect($reviewService->isReviewComplete($taskId))->toBeFalse();

    // Second check - completed
    expect($reviewService->isReviewComplete($taskId))->toBeTrue();
});

it('returns false for isReviewComplete when task not in pending reviews', function (): void {
    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    // Task that was never reviewed
    expect($reviewService->isReviewComplete('f-unknown'))->toBeFalse();
});

it('uses configured review agent instead of completing agent', function (): void {
    // Create a task
    $task = $this->taskService->create(['title' => 'Review agent test']);
    $taskId = $task['id'];

    // Expect spawn to be called with review-agent (from config), not test-agent
    $this->processManager
        ->shouldReceive('spawn')
        ->once()
        ->withArgs(function ($reviewTaskId, $agent, $command, $cwd) {
            return $agent === 'review-agent'; // Should use config's review_agent
        })
        ->andReturn(new Process(
            id: 'p-test',
            taskId: 'review-'.$taskId,
            agent: 'review-agent',
            command: 'echo test',
            cwd: getcwd(),
            pid: 12345,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable
        ));

    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    // Trigger review with 'test-agent', but should use 'review-agent' from config
    $reviewService->triggerReview($taskId, 'test-agent');
});

it('uses completing agent when no review agent configured', function (): void {
    // Create config without review_agent
    $configContent = <<<'YAML'
primary: test-agent

agents:
  test-agent:
    command: echo
    prompt_args: ["-p"]
    model: test-model
    args: []
    env: {}
    resume_args: []
    max_concurrent: 2
    max_attempts: 3

complexity:
  trivial: test-agent
  simple: test-agent
  moderate: test-agent
  complex: test-agent
YAML;
    file_put_contents($this->configPath, $configContent);
    $configService = new ConfigService($this->context);

    // Create a task
    $task = $this->taskService->create(['title' => 'No review agent test']);
    $taskId = $task['id'];

    // Expect spawn to be called with test-agent (completing agent)
    $this->processManager
        ->shouldReceive('spawn')
        ->once()
        ->withArgs(function ($reviewTaskId, $agent, $command, $cwd) {
            return $agent === 'test-agent'; // Should use completing agent
        })
        ->andReturn(new Process(
            id: 'p-test',
            taskId: 'review-'.$taskId,
            agent: 'test-agent',
            command: 'echo test',
            cwd: getcwd(),
            pid: 12345,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable
        ));

    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    $reviewService->triggerReview($taskId, 'test-agent');
});

it('throws exception when task not found during trigger', function (): void {
    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    expect(fn () => $reviewService->triggerReview('f-nonexistent', 'test-agent'))
        ->toThrow(RuntimeException::class, "Task 'f-nonexistent' not found");
});

it('gets review result when review is complete', function (): void {
    // Create main task
    $task = $this->taskService->create(['title' => 'Review result test']);
    $taskId = $task['id'];

    // Mock spawn
    $this->processManager
        ->shouldReceive('spawn')
        ->once()
        ->andReturn(new Process(
            id: 'p-test01',
            taskId: 'review-'.$taskId,
            agent: 'review-agent',
            command: 'echo test',
            cwd: getcwd(),
            pid: 12345,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable
        ));

    // Process completed
    $this->processManager
        ->shouldReceive('isRunning')
        ->with('review-'.$taskId)
        ->andReturn(false);

    // Mock output
    $this->processManager
        ->shouldReceive('getOutput')
        ->with('review-'.$taskId)
        ->andReturn(new ProcessOutput(
            stdout: 'Review completed successfully',
            stderr: '',
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        ));

    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    $reviewService->triggerReview($taskId, 'test-agent');

    $result = $reviewService->getReviewResult($taskId);

    expect($result)->not->toBeNull();
    expect($result->taskId)->toBe($taskId);
    expect($result->passed)->toBeTrue();
    expect($result->issues)->toBeEmpty();
    expect($result->followUpTaskIds)->toBeEmpty();
    expect($result->completedAt)->not->toBeEmpty();
});

it('detects follow-up tasks created by reviewer', function (): void {
    // Create main task
    $task = $this->taskService->create(['title' => 'Main task']);
    $taskId = $task['id'];

    // Create a follow-up task that is blocked by the main task and has review-fix label
    $followUpTask = $this->taskService->create([
        'title' => 'Fix failing tests from '.$taskId,
        'labels' => ['review-fix'],
        'blocked_by' => [$taskId],
    ]);

    // Mock spawn
    $this->processManager
        ->shouldReceive('spawn')
        ->once()
        ->andReturn(new Process(
            id: 'p-test01',
            taskId: 'review-'.$taskId,
            agent: 'review-agent',
            command: 'echo test',
            cwd: getcwd(),
            pid: 12345,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable
        ));

    // Process completed
    $this->processManager
        ->shouldReceive('isRunning')
        ->with('review-'.$taskId)
        ->andReturn(false);

    // Mock output with test failure message
    $this->processManager
        ->shouldReceive('getOutput')
        ->with('review-'.$taskId)
        ->andReturn(new ProcessOutput(
            stdout: 'Tests failed during review',
            stderr: '',
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        ));

    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    $reviewService->triggerReview($taskId, 'test-agent');

    $result = $reviewService->getReviewResult($taskId);

    expect($result)->not->toBeNull();
    expect($result->passed)->toBeFalse();
    expect($result->followUpTaskIds)->toContain($followUpTask['id']);
    expect($result->issues)->toContain('tests_failing');
});

it('returns null for getReviewResult when review not complete', function (): void {
    // Create a task
    $task = $this->taskService->create(['title' => 'Not complete test']);
    $taskId = $task['id'];

    // Mock spawn
    $this->processManager
        ->shouldReceive('spawn')
        ->once()
        ->andReturn(new Process(
            id: 'p-test01',
            taskId: 'review-'.$taskId,
            agent: 'review-agent',
            command: 'echo test',
            cwd: getcwd(),
            pid: 12345,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable
        ));

    // Process still running
    $this->processManager
        ->shouldReceive('isRunning')
        ->with('review-'.$taskId)
        ->andReturn(true);

    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    $reviewService->triggerReview($taskId, 'test-agent');

    $result = $reviewService->getReviewResult($taskId);

    expect($result)->toBeNull();
});

it('generates review prompt via getReviewPrompt', function (): void {
    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    $task = [
        'id' => 'f-test123',
        'title' => 'Test task',
        'description' => 'A test description',
    ];

    $prompt = $reviewService->getReviewPrompt($task, 'diff content', 'M file.txt');

    expect($prompt)->toContain('f-test123');
    expect($prompt)->toContain('Test task');
    expect($prompt)->toContain('A test description');
    expect($prompt)->toContain('diff content');
    expect($prompt)->toContain('M file.txt');
});

it('detects uncommitted changes issue from output', function (): void {
    // Create main task
    $task = $this->taskService->create(['title' => 'Uncommitted changes test']);
    $taskId = $task['id'];

    // Create follow-up task
    $this->taskService->create([
        'title' => 'Commit uncommitted changes',
        'labels' => ['review-fix'],
        'blocked_by' => [$taskId],
    ]);

    // Mock spawn
    $this->processManager
        ->shouldReceive('spawn')
        ->once()
        ->andReturn(new Process(
            id: 'p-test01',
            taskId: 'review-'.$taskId,
            agent: 'review-agent',
            command: 'echo test',
            cwd: getcwd(),
            pid: 12345,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable
        ));

    $this->processManager
        ->shouldReceive('isRunning')
        ->with('review-'.$taskId)
        ->andReturn(false);

    $this->processManager
        ->shouldReceive('getOutput')
        ->with('review-'.$taskId)
        ->andReturn(new ProcessOutput(
            stdout: 'Found uncommitted changes in working directory',
            stderr: '',
            stdoutPath: '/tmp/stdout.log',
            stderrPath: '/tmp/stderr.log'
        ));

    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    $reviewService->triggerReview($taskId, 'test-agent');

    $result = $reviewService->getReviewResult($taskId);

    expect($result->issues)->toContain('uncommitted_changes');
});

it('recovers stuck reviews for tasks in review status with no active process', function (): void {
    // Create a task and set it to review status manually (simulating crash)
    $task = $this->taskService->create(['title' => 'Stuck review task']);
    $taskId = $task['id'];

    // Set status to 'review' to simulate a stuck task
    $this->taskService->update($taskId, ['status' => 'review']);

    // Create a run record for this task so we have agent info
    $this->runService->logRun($taskId, [
        'agent' => 'test-agent',
        'started_at' => date('c'),
    ]);

    // Mock: no active review process running for this task
    $this->processManager
        ->shouldReceive('isRunning')
        ->with('review-'.$taskId)
        ->andReturn(false);

    // Mock: spawn will be called to re-trigger review
    $this->processManager
        ->shouldReceive('spawn')
        ->once()
        ->withArgs(function ($reviewTaskId, $agent, $command, $cwd) use ($taskId) {
            return $reviewTaskId === 'review-'.$taskId;
        })
        ->andReturn(new Process(
            id: 'p-recover',
            taskId: 'review-'.$taskId,
            agent: 'review-agent',
            command: 'echo test',
            cwd: getcwd(),
            pid: 99999,
            status: ProcessStatus::Running,
            exitCode: null,
            startedAt: new DateTimeImmutable
        ));

    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    $recovered = $reviewService->recoverStuckReviews();

    expect($recovered)->toContain($taskId);
});

it('does not recover reviews for tasks with active review process', function (): void {
    // Create a task in review status
    $task = $this->taskService->create(['title' => 'Active review task']);
    $taskId = $task['id'];

    $this->taskService->update($taskId, ['status' => 'review']);

    // Create a run record
    $this->runService->logRun($taskId, [
        'agent' => 'test-agent',
        'started_at' => date('c'),
    ]);

    // Mock: review process IS running
    $this->processManager
        ->shouldReceive('isRunning')
        ->with('review-'.$taskId)
        ->andReturn(true);

    // spawn should NOT be called
    $this->processManager
        ->shouldNotReceive('spawn');

    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    $recovered = $reviewService->recoverStuckReviews();

    expect($recovered)->toBeEmpty();
});

it('skips stuck review tasks with no run history', function (): void {
    // Create a task in review status but with no run history
    $task = $this->taskService->create(['title' => 'No history task']);
    $taskId = $task['id'];

    $this->taskService->update($taskId, ['status' => 'review']);

    // Mock: no active review process
    $this->processManager
        ->shouldReceive('isRunning')
        ->with('review-'.$taskId)
        ->andReturn(false);

    // spawn should NOT be called (no agent info available)
    $this->processManager
        ->shouldNotReceive('spawn');

    $reviewService = new ReviewService(
        $this->processManager,
        $this->taskService,
        $this->configService,
        $this->reviewPrompt,
        $this->databaseService,
        $this->runService
    );

    $recovered = $reviewService->recoverStuckReviews();

    expect($recovered)->toBeEmpty();
});
