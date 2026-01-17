<?php

declare(strict_types=1);

use App\Daemon\TaskSpawner;
use App\Enums\MirrorStatus;
use App\Models\Epic;
use App\Models\Task;
use App\Process\AgentProcess;
use App\Process\SpawnResult;
use App\Services\ConfigService;
use App\Services\EpicService;
use App\Services\FuelContext;
use App\Services\ProcessManager;
use App\Services\RunService;
use App\Services\TaskService;

beforeEach(function () {
    // Create mocks for dependencies
    $this->taskService = \Mockery::mock(TaskService::class);
    $this->configService = \Mockery::mock(ConfigService::class);
    $this->runService = \Mockery::mock(RunService::class);
    $this->processManager = \Mockery::mock(ProcessManager::class);
    $this->fuelContext = \Mockery::mock(FuelContext::class);
    $this->epicService = \Mockery::mock(EpicService::class);

    // Set default project path
    $this->projectPath = '/path/to/project';
    $this->fuelContext->shouldReceive('getProjectPath')
        ->andReturn($this->projectPath)
        ->byDefault();

    // Create TaskSpawner instance with mocked dependencies
    $this->spawner = new TaskSpawner(
        $this->taskService,
        $this->configService,
        $this->runService,
        $this->processManager,
        $this->fuelContext,
        $this->epicService
    );

    // Set instance ID for the spawner
    $this->spawner->setInstanceId('test-instance');

    // Default mock for getAgentForComplexity (used by WorkAgentTask)
    $this->configService->shouldReceive('getAgentForComplexity')
        ->andReturnUsing(function ($complexity) {
            return match ($complexity) {
                'trivial' => 'haiku',
                'simple' => 'sonnet',
                'moderate' => 'claude',
                'complex' => 'opus',
                default => 'claude',
            };
        })
        ->byDefault();
});

afterEach(function () {
    \Mockery::close();
});

test('task with epic and Ready mirror uses mirror_path as cwd', function () {
    // Enable epic mirrors
    $this->configService->shouldReceive('getEpicMirrorsEnabled')->andReturn(true);

    // Create and save epic with Ready mirror
    $epic = new Epic;
    $epic->title = 'Test Epic';
    $epic->short_id = 'e-test01';
    $epic->mirror_status = MirrorStatus::Ready;
    $epic->mirror_path = '/home/.fuel/mirrors/project/e-xyz789';
    $epic->save();

    // Create task with epic
    $task = new Task;
    $task->title = 'Test Task';
    $task->short_id = 'f-abc123';
    $task->epic_id = $epic->id;
    $task->agent = 'claude';
    $task->complexity = 'moderate';
    $task->status = 'open';
    $task->save();

    // Set up expectations for successful spawn
    $this->configService->shouldReceive('getAgentDefinition')
        ->with('claude')
        ->andReturn(['model' => 'claude-3-sonnet']);

    $this->processManager->shouldReceive('canSpawn')
        ->with('claude')
        ->andReturn(true);

    // Create a mock Symfony Process
    $mockSymfonyProcess = \Mockery::mock(\Symfony\Component\Process\Process::class);
    $mockSymfonyProcess->shouldReceive('getPid')->andReturn(12345);

    // Create real AgentProcess with mocked Symfony Process
    $agentProcess = new AgentProcess(
        $mockSymfonyProcess,
        'f-abc123',
        'claude',
        time()
    );

    // Expect spawnAgentTask to be called with the mirror path as cwd
    $this->processManager->shouldReceive('spawnAgentTask')
        ->withArgs(function ($agentTask, $cwd, $runId) use ($epic) {
            return $cwd === $epic->mirror_path;
        })
        ->andReturn(SpawnResult::success($agentProcess));

    // Other required mocks for successful spawn
    $this->taskService->shouldReceive('start')->with('f-abc123');
    $this->taskService->shouldReceive('update')->with('f-abc123', ['consumed' => true]);
    $this->runService->shouldReceive('createRun')
        ->with('f-abc123', \Mockery::any())
        ->andReturn('run-123');
    $this->runService->shouldReceive('updateRun')->with('run-123', ['pid' => 12345]);

    // Try to spawn the task
    $result = $this->spawner->trySpawnTask($task, null);

    expect($result)->toBeTrue();
});

test('task with epic and Pending mirror is skipped', function () {
    // Enable epic mirrors
    $this->configService->shouldReceive('getEpicMirrorsEnabled')->andReturn(true);

    // Create and save epic with Pending mirror
    $epic = new Epic;
    $epic->title = 'Test Epic';
    $epic->short_id = 'e-test02';
    $epic->mirror_status = MirrorStatus::Pending;
    $epic->save();

    // Create task with epic
    $task = new Task;
    $task->title = 'Test Task';
    $task->short_id = 'f-def456';
    $task->epic_id = $epic->id;
    $task->agent = 'claude';
    $task->status = 'open';
    $task->save();

    // Should NOT call any spawn methods since task should be skipped
    $this->processManager->shouldNotReceive('spawnAgentTask');
    $this->taskService->shouldNotReceive('start');
    $this->taskService->shouldNotReceive('update');
    $this->runService->shouldNotReceive('createRun');

    // Try to spawn the task
    $result = $this->spawner->trySpawnTask($task, null);

    expect($result)->toBeFalse();
});

test('task with epic and Creating mirror is skipped', function () {
    // Enable epic mirrors
    $this->configService->shouldReceive('getEpicMirrorsEnabled')->andReturn(true);

    // Create and save epic with Creating mirror
    $epic = new Epic;
    $epic->title = 'Test Epic';
    $epic->short_id = 'e-test03';
    $epic->mirror_status = MirrorStatus::Creating;
    $epic->save();

    // Create task with epic
    $task = new Task;
    $task->title = 'Test Task';
    $task->short_id = 'f-ghi789';
    $task->epic_id = $epic->id;
    $task->agent = 'sonnet';
    $task->status = 'open';
    $task->save();

    // Should NOT call any spawn methods since task should be skipped
    $this->processManager->shouldNotReceive('spawnAgentTask');
    $this->taskService->shouldNotReceive('start');
    $this->taskService->shouldNotReceive('update');
    $this->runService->shouldNotReceive('createRun');

    // Try to spawn the task
    $result = $this->spawner->trySpawnTask($task, null);

    expect($result)->toBeFalse();
});

test('task with epic and MergeFailed mirror is skipped', function () {
    // Enable epic mirrors
    $this->configService->shouldReceive('getEpicMirrorsEnabled')->andReturn(true);

    // Create and save epic with MergeFailed mirror
    $epic = new Epic;
    $epic->title = 'Test Epic';
    $epic->short_id = 'e-test04';
    $epic->mirror_status = MirrorStatus::MergeFailed;
    $epic->save();

    // Create task with epic
    $task = new Task;
    $task->title = 'Test Task';
    $task->short_id = 'f-jkl012';
    $task->epic_id = $epic->id;
    $task->agent = 'opus';
    $task->status = 'open';
    $task->save();

    // Should NOT call any spawn methods since task should be skipped
    $this->processManager->shouldNotReceive('spawnAgentTask');
    $this->taskService->shouldNotReceive('start');
    $this->taskService->shouldNotReceive('update');
    $this->runService->shouldNotReceive('createRun');

    // Try to spawn the task
    $result = $this->spawner->trySpawnTask($task, null);

    expect($result)->toBeFalse();
});

test('standalone task during active merge is skipped', function () {
    // Enable epic mirrors
    $this->configService->shouldReceive('getEpicMirrorsEnabled')->andReturn(true);

    // Create standalone task (no epic_id)
    $task = new Task;
    $task->title = 'Standalone Task';
    $task->short_id = 'f-mno345';
    $task->epic_id = null; // Standalone task
    $task->agent = 'claude';
    $task->status = 'open';
    $task->save();

    // Mock hasActiveMerge to return true (an epic is merging)
    $this->epicService->shouldReceive('hasActiveMerge')->andReturn(true);

    // Should NOT call any spawn methods since task should be skipped
    $this->processManager->shouldNotReceive('spawnAgentTask');
    $this->taskService->shouldNotReceive('start');
    $this->taskService->shouldNotReceive('update');
    $this->runService->shouldNotReceive('createRun');

    // Try to spawn the task
    $result = $this->spawner->trySpawnTask($task, null);

    expect($result)->toBeFalse();
});

test('standalone task with no active merge uses project path', function () {
    // Enable epic mirrors
    $this->configService->shouldReceive('getEpicMirrorsEnabled')->andReturn(true);

    // Create standalone task (no epic_id)
    $task = new Task;
    $task->title = 'Standalone Task';
    $task->short_id = 'f-pqr678';
    $task->epic_id = null; // Standalone task
    $task->agent = 'sonnet';
    $task->complexity = 'simple';
    $task->status = 'open';
    $task->save();

    // Mock hasActiveMerge to return false (no epic is merging)
    $this->epicService->shouldReceive('hasActiveMerge')->andReturn(false);

    // Set up expectations for successful spawn
    $this->configService->shouldReceive('getAgentDefinition')
        ->with('sonnet')
        ->andReturn(['model' => 'claude-3-sonnet']);

    $this->processManager->shouldReceive('canSpawn')
        ->with('sonnet')
        ->andReturn(true);

    // Create a mock Symfony Process
    $mockSymfonyProcess = \Mockery::mock(\Symfony\Component\Process\Process::class);
    $mockSymfonyProcess->shouldReceive('getPid')->andReturn(67890);

    // Create real AgentProcess with mocked Symfony Process
    $agentProcess = new AgentProcess(
        $mockSymfonyProcess,
        'f-pqr678',
        'sonnet',
        time()
    );

    // Expect spawnAgentTask to be called with the project path as cwd
    $this->processManager->shouldReceive('spawnAgentTask')
        ->withArgs(function ($agentTask, $cwd, $runId) {
            return $cwd === $this->projectPath;
        })
        ->andReturn(SpawnResult::success($agentProcess));

    // Other required mocks for successful spawn
    $this->taskService->shouldReceive('start')->with('f-pqr678');
    $this->taskService->shouldReceive('update')->with('f-pqr678', ['consumed' => true]);
    $this->runService->shouldReceive('createRun')
        ->with('f-pqr678', \Mockery::any())
        ->andReturn('run-456');
    $this->runService->shouldReceive('updateRun')->with('run-456', ['pid' => 67890]);

    // Try to spawn the task
    $result = $this->spawner->trySpawnTask($task, null);

    expect($result)->toBeTrue();
});

test('when epic_mirrors config is false, all tasks use project path', function () {
    // Disable epic mirrors
    $this->configService->shouldReceive('getEpicMirrorsEnabled')->andReturn(false);

    // Create and save epic (even though mirrors are disabled)
    $epic = new Epic;
    $epic->title = 'Test Epic';
    $epic->short_id = 'e-test05';
    $epic->mirror_status = MirrorStatus::Ready;
    $epic->mirror_path = '/home/.fuel/mirrors/project/e-test05';
    $epic->save();

    // Create task with epic
    $task = new Task;
    $task->title = 'Test Task';
    $task->short_id = 'f-stu901';
    $task->epic_id = $epic->id;
    $task->agent = 'opus';
    $task->complexity = 'complex';
    $task->status = 'open';
    $task->save();

    // Set up expectations for successful spawn
    $this->configService->shouldReceive('getAgentDefinition')
        ->with('opus')
        ->andReturn(['model' => 'claude-3-opus']);

    $this->processManager->shouldReceive('canSpawn')
        ->with('opus')
        ->andReturn(true);

    // Create a mock Symfony Process
    $mockSymfonyProcess = \Mockery::mock(\Symfony\Component\Process\Process::class);
    $mockSymfonyProcess->shouldReceive('getPid')->andReturn(11111);

    // Create real AgentProcess with mocked Symfony Process
    $agentProcess = new AgentProcess(
        $mockSymfonyProcess,
        'f-stu901',
        'opus',
        time()
    );

    // Expect spawnAgentTask to be called with the PROJECT path (not mirror path)
    $this->processManager->shouldReceive('spawnAgentTask')
        ->withArgs(function ($agentTask, $cwd, $runId) {
            return $cwd === $this->projectPath;
        })
        ->andReturn(SpawnResult::success($agentProcess));

    // Other required mocks for successful spawn
    $this->taskService->shouldReceive('start')->with('f-stu901');
    $this->taskService->shouldReceive('update')->with('f-stu901', ['consumed' => true]);
    $this->runService->shouldReceive('createRun')
        ->with('f-stu901', \Mockery::any())
        ->andReturn('run-789');
    $this->runService->shouldReceive('updateRun')->with('run-789', ['pid' => 11111]);

    // Try to spawn the task
    $result = $this->spawner->trySpawnTask($task, null);

    expect($result)->toBeTrue();
});

test('task with epic and None mirror status uses project path', function () {
    // Enable epic mirrors
    $this->configService->shouldReceive('getEpicMirrorsEnabled')->andReturn(true);

    // Create and save epic with None mirror status
    $epic = new Epic;
    $epic->title = 'Test Epic';
    $epic->short_id = 'e-test06';
    $epic->mirror_status = MirrorStatus::None;
    $epic->save();

    // Create task with epic
    $task = new Task;
    $task->title = 'Test Task';
    $task->short_id = 'f-vwx234';
    $task->epic_id = $epic->id;
    $task->agent = 'claude';
    $task->complexity = 'moderate';
    $task->status = 'open';
    $task->save();

    // Set up expectations for successful spawn
    $this->configService->shouldReceive('getAgentDefinition')
        ->with('claude')
        ->andReturn(['model' => 'claude-3-sonnet']);

    $this->processManager->shouldReceive('canSpawn')
        ->with('claude')
        ->andReturn(true);

    // Create a mock Symfony Process
    $mockSymfonyProcess = \Mockery::mock(\Symfony\Component\Process\Process::class);
    $mockSymfonyProcess->shouldReceive('getPid')->andReturn(22222);

    // Create real AgentProcess with mocked Symfony Process
    $agentProcess = new AgentProcess(
        $mockSymfonyProcess,
        'f-vwx234',
        'claude',
        time()
    );

    // Expect spawnAgentTask to be called with the PROJECT path (not a mirror path)
    $this->processManager->shouldReceive('spawnAgentTask')
        ->withArgs(function ($agentTask, $cwd, $runId) {
            return $cwd === $this->projectPath;
        })
        ->andReturn(SpawnResult::success($agentProcess));

    // Other required mocks for successful spawn
    $this->taskService->shouldReceive('start')->with('f-vwx234');
    $this->taskService->shouldReceive('update')->with('f-vwx234', ['consumed' => true]);
    $this->runService->shouldReceive('createRun')
        ->with('f-vwx234', \Mockery::any())
        ->andReturn('run-999');
    $this->runService->shouldReceive('updateRun')->with('run-999', ['pid' => 22222]);

    // Try to spawn the task
    $result = $this->spawner->trySpawnTask($task, null);

    expect($result)->toBeTrue();
});

test('task with epic and Merging mirror status uses project path', function () {
    // Enable epic mirrors
    $this->configService->shouldReceive('getEpicMirrorsEnabled')->andReturn(true);

    // Create and save epic with Merging mirror status
    $epic = new Epic;
    $epic->title = 'Test Epic';
    $epic->short_id = 'e-test07';
    $epic->mirror_status = MirrorStatus::Merging;
    $epic->save();

    // Create task with epic
    $task = new Task;
    $task->title = 'Test Task';
    $task->short_id = 'f-yz0567';
    $task->epic_id = $epic->id;
    $task->agent = 'sonnet';
    $task->complexity = 'simple';
    $task->status = 'open';
    $task->save();

    // Set up expectations for successful spawn
    $this->configService->shouldReceive('getAgentDefinition')
        ->with('sonnet')
        ->andReturn(['model' => 'claude-3-sonnet']);

    $this->processManager->shouldReceive('canSpawn')
        ->with('sonnet')
        ->andReturn(true);

    // Create a mock Symfony Process
    $mockSymfonyProcess = \Mockery::mock(\Symfony\Component\Process\Process::class);
    $mockSymfonyProcess->shouldReceive('getPid')->andReturn(33333);

    // Create real AgentProcess with mocked Symfony Process
    $agentProcess = new AgentProcess(
        $mockSymfonyProcess,
        'f-yz0567',
        'sonnet',
        time()
    );

    // Expect spawnAgentTask to be called with the PROJECT path (not a mirror path)
    $this->processManager->shouldReceive('spawnAgentTask')
        ->withArgs(function ($agentTask, $cwd, $runId) {
            return $cwd === $this->projectPath;
        })
        ->andReturn(SpawnResult::success($agentProcess));

    // Other required mocks for successful spawn
    $this->taskService->shouldReceive('start')->with('f-yz0567');
    $this->taskService->shouldReceive('update')->with('f-yz0567', ['consumed' => true]);
    $this->runService->shouldReceive('createRun')
        ->with('f-yz0567', \Mockery::any())
        ->andReturn('run-111');
    $this->runService->shouldReceive('updateRun')->with('run-111', ['pid' => 33333]);

    // Try to spawn the task
    $result = $this->spawner->trySpawnTask($task, null);

    expect($result)->toBeTrue();
});

test('task with epic but null mirror_status uses project path', function () {
    // Enable epic mirrors
    $this->configService->shouldReceive('getEpicMirrorsEnabled')->andReturn(true);

    // Create and save epic with default mirror_status (which is 'none')
    $epic = new Epic;
    $epic->title = 'Test Epic';
    $epic->short_id = 'e-test08';
    // Don't set mirror_status, let it use the default
    $epic->save();

    // Create task with epic
    $task = new Task;
    $task->title = 'Test Task';
    $task->short_id = 'f-abc890';
    $task->epic_id = $epic->id;
    $task->agent = 'opus';
    $task->complexity = 'complex';
    $task->status = 'open';
    $task->save();

    // Set up expectations for successful spawn
    $this->configService->shouldReceive('getAgentDefinition')
        ->with('opus')
        ->andReturn(['model' => 'claude-3-opus']);

    $this->processManager->shouldReceive('canSpawn')
        ->with('opus')
        ->andReturn(true);

    // Create a mock Symfony Process
    $mockSymfonyProcess = \Mockery::mock(\Symfony\Component\Process\Process::class);
    $mockSymfonyProcess->shouldReceive('getPid')->andReturn(44444);

    // Create real AgentProcess with mocked Symfony Process
    $agentProcess = new AgentProcess(
        $mockSymfonyProcess,
        'f-abc890',
        'opus',
        time()
    );

    // Expect spawnAgentTask to be called with the PROJECT path (not a mirror path)
    $this->processManager->shouldReceive('spawnAgentTask')
        ->withArgs(function ($agentTask, $cwd, $runId) {
            return $cwd === $this->projectPath;
        })
        ->andReturn(SpawnResult::success($agentProcess));

    // Other required mocks for successful spawn
    $this->taskService->shouldReceive('start')->with('f-abc890');
    $this->taskService->shouldReceive('update')->with('f-abc890', ['consumed' => true]);
    $this->runService->shouldReceive('createRun')
        ->with('f-abc890', \Mockery::any())
        ->andReturn('run-222');
    $this->runService->shouldReceive('updateRun')->with('run-222', ['pid' => 44444]);

    // Try to spawn the task
    $result = $this->spawner->trySpawnTask($task, null);

    expect($result)->toBeTrue();
});
