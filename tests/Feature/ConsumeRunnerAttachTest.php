<?php

declare(strict_types=1);

use App\Daemon\CompletionHandler;
use App\Daemon\LifecycleManager;
use App\Daemon\TaskSpawner;
use App\Ipc\Events\HelloEvent;
use App\Ipc\Events\SnapshotEvent;
use App\Services\BackoffStrategy;
use App\Services\ConfigService;
use App\Services\ConsumeIpcProtocol;
use App\Services\ConsumeIpcServer;
use App\Services\ConsumeRunner;
use App\Services\FuelContext;
use App\Services\ProcessManager;
use App\Services\RunService;
use App\Services\TaskPromptBuilder;
use App\Services\TaskService;

beforeEach(function () {
    // Use isolated temp directory for tests
    $this->testDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->testDir.'/.fuel', 0755, true);

    // Change to test directory
    $this->originalDir = getcwd();
    chdir($this->testDir);
});

afterEach(function () {
    // Close Mockery
    Mockery::close();

    // Return to original directory
    chdir($this->originalDir);

    // Clean up test directory
    if (is_dir($this->testDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->testDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->testDir);
    }
});

describe('ConsumeRunner client attach', function () {
    test('client receives HelloEvent and SnapshotEvent via socket pair', function () {
        // Create protocol
        $protocol = new ConsumeIpcProtocol;

        // Create mock dependencies (not starting runner, just testing IPC protocol)
        $processManager = Mockery::mock(ProcessManager::class);
        $processManager->shouldReceive('getActiveProcesses')->andReturn([]);
        $processManager->shouldReceive('getAgentCount')->andReturn(0);

        $taskService = Mockery::mock(TaskService::class);
        $taskService->shouldReceive('ready')->andReturn(collect());
        $taskService->shouldReceive('inProgress')->andReturn(collect());
        $taskService->shouldReceive('blocked')->andReturn(collect());
        $taskService->shouldReceive('needsHuman')->andReturn(collect());

        $configService = Mockery::mock(ConfigService::class);
        $configService->shouldReceive('getAgentLimits')->andReturn([]);
        $configService->shouldReceive('getAgentMaxAttempts')->andReturn(3);

        $runService = Mockery::mock(RunService::class);
        $backoffStrategy = Mockery::mock(BackoffStrategy::class);
        $promptBuilder = Mockery::mock(TaskPromptBuilder::class);

        // Use isolated temp directory for tests
        $testDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
        mkdir($testDir.'/.fuel', 0755, true);

        // Create real FuelContext instance for LifecycleManager
        $fuelContext = new FuelContext($testDir.'/.fuel');

        // Create real LifecycleManager instance (it's a final class and cannot be mocked)
        $lifecycleManager = new \App\Daemon\LifecycleManager($fuelContext);

        // Mock health tracker for TaskSpawner
        $healthTracker = Mockery::mock(\App\Contracts\AgentHealthTrackerInterface::class);
        $healthTracker->shouldReceive('canSpawn')->andReturn(true)->byDefault();
        $healthTracker->shouldReceive('getCurrentUsage')->andReturn(0.0)->byDefault();
        $healthTracker->shouldReceive('getAllHealthStatus')->andReturn([])->byDefault();

        // Create real TaskSpawner instance (it's a final class and cannot be mocked)
        $taskSpawner = new \App\Daemon\TaskSpawner(
            $taskService,
            $configService,
            $runService,
            $processManager,
            $fuelContext,
            $healthTracker
        );

        // Create real CompletionHandler instance (it's a final class and cannot be mocked)
        $completionHandler = new \App\Daemon\CompletionHandler(
            $processManager,
            $taskService,
            $runService,
            $configService,
            $healthTracker,
            null  // reviewService
        );
        $ipcServer = new ConsumeIpcServer($protocol);

        // Create IpcCommandDispatcher
        $ipcCommandDispatcher = new \App\Daemon\IpcCommandDispatcher(
            $ipcServer,
            $lifecycleManager,
            $completionHandler,
            $configService
        );

        // Create SnapshotManager
        $snapshotManager = new \App\Daemon\SnapshotManager(
            $ipcServer,
            $taskService,
            $processManager,
            $healthTracker,
            $lifecycleManager
        );

        // Create ConsumeRunner (we won't start it, just use it for snapshot)
        $runner = new ConsumeRunner(
            $ipcServer,
            $processManager,
            $protocol,
            $taskService,
            $configService,
            $runService,
            $backoffStrategy,
            $promptBuilder,
            $fuelContext,
            $lifecycleManager,
            $taskSpawner,
            $completionHandler,
            $ipcCommandDispatcher,
            $snapshotManager,
            null, // reviewManager
            $healthTracker
        );

        // Simulate client connection using stream_socket_pair for testing
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        expect($sockets)->not->toBeFalse();

        [$clientSocket, $serverSocket] = $sockets;

        // Create HelloEvent and SnapshotEvent
        $helloEvent = new HelloEvent(
            version: '1.0.0',
            instanceId: $runner->getInstanceId()
        );

        $snapshot = $runner->getSnapshot();
        $snapshotEvent = new SnapshotEvent(
            snapshot: $snapshot,
            instanceId: $runner->getInstanceId()
        );

        // Write events to server socket (simulating runner broadcasting to client)
        fwrite($serverSocket, $protocol->encode($helloEvent));
        fwrite($serverSocket, $protocol->encode($snapshotEvent));

        // Read from client socket
        stream_set_blocking($clientSocket, true); // Use blocking for reliable reads
        $line1 = fgets($clientSocket);
        $line2 = fgets($clientSocket);

        expect($line1)->not->toBeFalse();
        expect($line2)->not->toBeFalse();

        // Parse first event (HelloEvent)
        $helloData = json_decode(trim($line1), true);
        expect($helloData)->toBeArray();
        expect($helloData['type'])->toBe('hello');
        expect($helloData['instance_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
        expect($helloData['version'])->toBe('1.0.0');

        // Parse second event (SnapshotEvent)
        $snapshotData = json_decode(trim($line2), true);
        expect($snapshotData)->toBeArray();
        expect($snapshotData['type'])->toBe('snapshot');
        expect($snapshotData['instance_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
        expect($snapshotData['snapshot'])->toBeArray();
        expect($snapshotData['snapshot']['board_state'])->toBeArray();

        // Clean up
        fclose($clientSocket);
        fclose($serverSocket);
    });

    test('SnapshotEvent contains board_state', function () {
        // Create protocol and IPC server
        $protocol = new ConsumeIpcProtocol;
        $ipcServer = new ConsumeIpcServer($protocol);

        // Mock dependencies with expected board data
        $processManager = Mockery::mock(ProcessManager::class);
        $processManager->shouldReceive('getActiveProcesses')->andReturn([]);
        $processManager->shouldReceive('getAgentCount')->andReturn(0);

        $taskService = Mockery::mock(TaskService::class);
        $taskService->shouldReceive('ready')->andReturn(collect());
        $taskService->shouldReceive('inProgress')->andReturn(collect());
        $taskService->shouldReceive('blocked')->andReturn(collect());
        $taskService->shouldReceive('needsHuman')->andReturn(collect());

        $configService = Mockery::mock(ConfigService::class);
        $configService->shouldReceive('getAgentLimits')->andReturn([]);
        $configService->shouldReceive('getAgentMaxAttempts')->andReturn(3);

        $runService = Mockery::mock(RunService::class);
        $backoffStrategy = Mockery::mock(BackoffStrategy::class);
        $promptBuilder = Mockery::mock(TaskPromptBuilder::class);

        // Use real FuelContext and LifecycleManager since LifecycleManager is final
        $fuelContext = new FuelContext($this->testDir.'/.fuel');
        $lifecycleManager = new LifecycleManager($fuelContext);

        // Mock health tracker for TaskSpawner
        $healthTracker = Mockery::mock(\App\Contracts\AgentHealthTrackerInterface::class);
        $healthTracker->shouldReceive('canSpawn')->andReturn(true);
        $healthTracker->shouldReceive('getCurrentUsage')->andReturn(0.0);
        $healthTracker->shouldReceive('getAllHealthStatus')->andReturn([]);

        // Create real TaskSpawner instance (it's a final class and cannot be mocked)
        $taskSpawner = new TaskSpawner(
            $taskService,
            $configService,
            $runService,
            $processManager,
            $fuelContext,
            $healthTracker
        );

        // Mock review service for CompletionHandler
        $reviewService = Mockery::mock(\App\Contracts\ReviewServiceInterface::class);

        $completionHandler = new \App\Daemon\CompletionHandler(
            $processManager,
            $taskService,
            $runService,
            $configService,
            $healthTracker,
            $reviewService
        );

        // Create IpcCommandDispatcher
        $ipcCommandDispatcher = new \App\Daemon\IpcCommandDispatcher(
            $ipcServer,
            $lifecycleManager,
            $completionHandler,
            $configService
        );

        // Create SnapshotManager
        $snapshotManager = new \App\Daemon\SnapshotManager(
            $ipcServer,
            $taskService,
            $processManager,
            $healthTracker,
            $lifecycleManager
        );

        // Create ConsumeRunner
        $runner = new ConsumeRunner(
            $ipcServer,
            $processManager,
            $protocol,
            $taskService,
            $configService,
            $runService,
            $backoffStrategy,
            $promptBuilder,
            $fuelContext,
            $lifecycleManager,
            $taskSpawner,
            $completionHandler,
            $ipcCommandDispatcher,
            $snapshotManager,
            null, // reviewManager
            $healthTracker
        );

        // Get snapshot and verify structure
        $snapshot = $runner->getSnapshot();
        $snapshotEvent = new SnapshotEvent(
            snapshot: $snapshot,
            instanceId: $runner->getInstanceId()
        );

        $eventArray = $snapshotEvent->toArray();

        expect($eventArray)->toHaveKey('snapshot');
        expect($eventArray['snapshot'])->toHaveKey('board_state');
        expect($eventArray['snapshot']['board_state'])->toBeArray();
        expect($eventArray['snapshot']['board_state'])->toHaveKeys([
            'ready',
            'in_progress',
            'review',
            'blocked',
            'human',
            'done',
        ]);
    });

    test('HelloEvent contains instance_id and version', function () {
        // Create protocol
        $protocol = new ConsumeIpcProtocol;

        // Create HelloEvent
        $instanceId = $protocol->generateInstanceId();
        $helloEvent = new HelloEvent(
            version: '1.0.0',
            instanceId: $instanceId
        );

        // Verify properties
        expect($helloEvent->type())->toBe('hello');
        expect($helloEvent->version())->toBe('1.0.0');

        // Verify serialization
        $eventArray = $helloEvent->toArray();
        expect($eventArray['type'])->toBe('hello');
        expect($eventArray['instance_id'])->toBe($instanceId);
        expect($eventArray['version'])->toBe('1.0.0');
        expect($eventArray['timestamp'])->toBeString();
    });
});
