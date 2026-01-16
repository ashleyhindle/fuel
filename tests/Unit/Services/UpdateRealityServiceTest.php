<?php

declare(strict_types=1);

use App\Agents\Tasks\UpdateRealityAgentTask;
use App\Contracts\ProcessManagerInterface;
use App\Process\AgentProcess;
use App\Process\ProcessType;
use App\Process\SpawnResult;
use App\Services\ConfigService;
use App\Services\DatabaseService;
use App\Services\FuelContext;
use App\Services\UpdateRealityService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(function (): void {
    // Create FuelContext for test directory
    $this->context = new FuelContext($this->testDir.'/.fuel');

    // Create config file in test directory (driver-based format)
    $this->configPath = $this->context->getConfigPath();
    $configContent = <<<'YAML'
primary: test-agent

agents:
  test-agent:
    driver: claude
    model: test-model
    max_concurrent: 2
    max_attempts: 3

  reality-agent:
    driver: claude
    model: reality-model
    max_concurrent: 2
    max_attempts: 3

complexity:
  trivial: test-agent
  simple: test-agent
  moderate: test-agent
  complex: test-agent

reality: reality-agent
YAML;
    file_put_contents($this->configPath, $configContent);

    // Create database service for test directory and initialize it
    $this->databaseService = new DatabaseService($this->context->getDatabasePath());
    $this->context->configureDatabase();
    Artisan::call('migrate', ['--force' => true]);

    $this->taskService = makeTaskService();
    $this->epicService = makeEpicService($this->taskService);
    $this->runService = makeRunService();
    $this->configService = new ConfigService($this->context);
    $this->processManager = Mockery::mock(ProcessManagerInterface::class);
});

afterEach(function (): void {
    Mockery::close();
});

it('triggers update for completed task', function (): void {
    // Create a task
    $task = $this->taskService->create([
        'title' => 'Test task',
        'description' => 'A task to test reality update',
    ]);

    // Create a mock Symfony Process for AgentProcess
    $symfonyProcess = Mockery::mock(SymfonyProcess::class);
    $symfonyProcess->shouldReceive('getPid')->andReturn(12345);
    $symfonyProcess->shouldReceive('isRunning')->andReturn(true);

    // Create AgentProcess for SpawnResult
    $agentProcess = new AgentProcess(
        $symfonyProcess,
        'f-abc123',
        'reality-agent',
        time(),
        null,
        null,
        ProcessType::Task,
        'reality-model'
    );

    // Expect spawnAgentTask to be called with UpdateRealityAgentTask
    $this->processManager
        ->shouldReceive('spawnAgentTask')
        ->once()
        ->withArgs(fn ($agentTask, $cwd, $runId): bool => $agentTask instanceof UpdateRealityAgentTask
            && str_starts_with($agentTask->getTaskId(), 'f-')
            && ($agentTask->getTask()->type ?? null) === 'reality'
            && $cwd === $this->context->getProjectPath()
            && is_string($runId)
            && str_starts_with($runId, 'run-'))
        ->andReturn(SpawnResult::success($agentProcess));

    $updateRealityService = new UpdateRealityService(
        $this->configService,
        $this->context,
        $this->processManager,
        $this->runService
    );

    // Should not throw
    $updateRealityService->triggerUpdate($task);
});

it('triggers update for approved epic', function (): void {
    // Create an epic
    $epic = $this->epicService->createEpic('Test epic', 'An epic to test reality update');

    // Create a mock Symfony Process for AgentProcess
    $symfonyProcess = Mockery::mock(SymfonyProcess::class);
    $symfonyProcess->shouldReceive('getPid')->andReturn(12345);
    $symfonyProcess->shouldReceive('isRunning')->andReturn(true);

    // Create AgentProcess for SpawnResult
    $agentProcess = new AgentProcess(
        $symfonyProcess,
        'f-def456',
        'reality-agent',
        time(),
        null,
        null,
        ProcessType::Task,
        'reality-model'
    );

    // Expect spawnAgentTask to be called with UpdateRealityAgentTask from epic
    $this->processManager
        ->shouldReceive('spawnAgentTask')
        ->once()
        ->withArgs(fn ($agentTask, $cwd, $runId): bool => $agentTask instanceof UpdateRealityAgentTask
            && str_starts_with($agentTask->getTaskId(), 'f-')
            && ($agentTask->getTask()->type ?? null) === 'reality'
            && $cwd === $this->context->getProjectPath()
            && is_string($runId)
            && str_starts_with($runId, 'run-'))
        ->andReturn(SpawnResult::success($agentProcess));

    $updateRealityService = new UpdateRealityService(
        $this->configService,
        $this->context,
        $this->processManager,
        $this->runService
    );

    // Should not throw
    $updateRealityService->triggerUpdate(null, $epic);
});

it('does nothing when neither task nor epic provided', function (): void {
    // Expect spawnAgentTask to NOT be called
    $this->processManager
        ->shouldNotReceive('spawnAgentTask');

    $updateRealityService = new UpdateRealityService(
        $this->configService,
        $this->context,
        $this->processManager,
        $this->runService
    );

    // Should not throw
    $updateRealityService->triggerUpdate();
});

it('does nothing when reality agent is not configured', function (): void {
    // Create config without reality agent
    $configContent = <<<'YAML'
agents:
  test-agent:
    driver: claude
    model: test-model
    max_concurrent: 2
    max_attempts: 3

complexity:
  trivial: test-agent
  simple: test-agent
  moderate: test-agent
  complex: test-agent
YAML;
    file_put_contents($this->configPath, $configContent);

    // Expect spawnAgentTask to NOT be called - config will throw before we get there
    $this->processManager
        ->shouldNotReceive('spawnAgentTask');

    $configService = new ConfigService($this->context);

    // getRealityAgent throws because 'primary' is required
    expect(fn (): ?string => $configService->getRealityAgent())
        ->toThrow(RuntimeException::class, "Config must have 'primary' key");
});

it('falls back to primary agent when no reality agent configured', function (): void {
    // Create config without reality (but with primary)
    $configContent = <<<'YAML'
primary: test-agent

agents:
  test-agent:
    driver: claude
    model: test-model
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
    $task = $this->taskService->create(['title' => 'Fallback test task']);

    // Create a mock Symfony Process for AgentProcess
    $symfonyProcess = Mockery::mock(SymfonyProcess::class);
    $symfonyProcess->shouldReceive('getPid')->andReturn(12345);
    $symfonyProcess->shouldReceive('isRunning')->andReturn(true);

    // Create AgentProcess for SpawnResult
    $agentProcess = new AgentProcess(
        $symfonyProcess,
        'f-ghi789',
        'test-agent',
        time(),
        null,
        null,
        ProcessType::Task
    );

    // Expect spawnAgentTask to be called (will use primary as fallback)
    $this->processManager
        ->shouldReceive('spawnAgentTask')
        ->once()
        ->andReturn(SpawnResult::success($agentProcess));

    $updateRealityService = new UpdateRealityService(
        $configService,
        $this->context,
        $this->processManager,
        $this->runService
    );

    // Should not throw, falls back to primary
    $updateRealityService->triggerUpdate($task);
});

it('prefers epic over task when both provided', function (): void {
    // Create both task and epic
    $task = $this->taskService->create(['title' => 'Test task']);
    $epic = $this->epicService->createEpic('Test epic');

    // Create a mock Symfony Process for AgentProcess
    $symfonyProcess = Mockery::mock(SymfonyProcess::class);
    $symfonyProcess->shouldReceive('getPid')->andReturn(12345);
    $symfonyProcess->shouldReceive('isRunning')->andReturn(true);

    // Create AgentProcess for SpawnResult
    $agentProcess = new AgentProcess(
        $symfonyProcess,
        'f-jkl012',
        'reality-agent',
        time(),
        null,
        null,
        ProcessType::Task
    );

    // Expect spawnAgentTask with epic's task ID, not task's
    $this->processManager
        ->shouldReceive('spawnAgentTask')
        ->once()
        ->withArgs(fn ($agentTask, $cwd, $runId): bool => $agentTask instanceof UpdateRealityAgentTask
            && str_starts_with($agentTask->getTaskId(), 'f-')
            && is_string($runId)
            && str_starts_with($runId, 'run-'))
        ->andReturn(SpawnResult::success($agentProcess));

    $updateRealityService = new UpdateRealityService(
        $this->configService,
        $this->context,
        $this->processManager,
        $this->runService
    );

    // Epic should take precedence
    $updateRealityService->triggerUpdate($task, $epic);
});

it('is fire-and-forget - does not wait for spawn result', function (): void {
    // Create a task
    $task = $this->taskService->create(['title' => 'Fire and forget task']);

    // Create a mock Symfony Process for AgentProcess
    $symfonyProcess = Mockery::mock(SymfonyProcess::class);
    $symfonyProcess->shouldReceive('getPid')->andReturn(12345);
    $symfonyProcess->shouldReceive('isRunning')->andReturn(true);

    // Create AgentProcess for SpawnResult
    $agentProcess = new AgentProcess(
        $symfonyProcess,
        'f-mno345',
        'reality-agent',
        time(),
        null,
        null,
        ProcessType::Task
    );

    // spawnAgentTask is called but we don't track the result
    $this->processManager
        ->shouldReceive('spawnAgentTask')
        ->once()
        ->andReturn(SpawnResult::success($agentProcess));

    // Crucially, we should NOT expect any waitForAny, poll, or similar calls
    $this->processManager
        ->shouldNotReceive('waitForAny');
    $this->processManager
        ->shouldNotReceive('poll');
    $this->processManager
        ->shouldNotReceive('isRunning');

    $updateRealityService = new UpdateRealityService(
        $this->configService,
        $this->context,
        $this->processManager,
        $this->runService
    );

    $updateRealityService->triggerUpdate($task);
});

it('silently handles spawn failures', function (): void {
    // Create a task
    $task = $this->taskService->create(['title' => 'Failing spawn task']);

    // spawnAgentTask returns failure, but triggerUpdate should NOT throw
    $this->processManager
        ->shouldReceive('spawnAgentTask')
        ->once()
        ->andReturn(SpawnResult::atCapacity('reality-agent'));

    $updateRealityService = new UpdateRealityService(
        $this->configService,
        $this->context,
        $this->processManager,
        $this->runService
    );

    // Should not throw even on spawn failure
    $updateRealityService->triggerUpdate($task);
});

it('can be resolved from container', function (): void {
    // Temporarily bind the mock process manager
    app()->instance(ProcessManagerInterface::class, $this->processManager);

    $service = app(UpdateRealityService::class);

    expect($service)->toBeInstanceOf(UpdateRealityService::class);
});
