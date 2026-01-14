<?php

declare(strict_types=1);

use App\Contracts\AgentHealthTrackerInterface;
use App\Contracts\ReviewServiceInterface;
use App\Daemon\BrowserCommandHandler;
use App\Daemon\CompletionHandler;
use App\Daemon\IpcCommandDispatcher;
use App\Daemon\LifecycleManager;
use App\Daemon\SnapshotManager;
use App\Daemon\TaskSpawner;
use App\Services\BackoffStrategy;
use App\Services\BrowserDaemonManager;
use App\Services\ConfigService;
use App\Services\ConsumeIpcProtocol;
use App\Services\ConsumeIpcServer;
use App\Services\ConsumeRunner;
use App\Services\FuelContext;
use App\Services\ProcessManager;
use App\Services\RunService;
use App\Services\TaskPromptBuilder;
use App\Services\TaskService;

beforeEach(function (): void {
    // Use isolated temp directory for tests
    $this->testDir = sys_get_temp_dir().'/fuel-test-'.uniqid();
    mkdir($this->testDir.'/.fuel', 0755, true);

    // Change to test directory
    $this->originalDir = getcwd();
    chdir($this->testDir);

    // Use a random port in the ephemeral range
    $this->testPort = random_int(49152, 65535);
});

afterEach(function (): void {
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

describe('ConsumeRunner PID file handling', function (): void {
    test('stale PID file with dead process is deleted on start', function (): void {
        // Create a stale PID file with a dead PID
        $stalePid = 99999; // PID that doesn't exist
        $stalePidData = [
            'pid' => $stalePid,
            'started_at' => '2024-01-01T00:00:00+00:00',
            'instance_id' => 'stale-instance-id',
            'port' => 9981,
        ];

        file_put_contents('.fuel/consume.pid', json_encode($stalePidData));
        expect(file_exists('.fuel/consume.pid'))->toBeTrue();

        // Create dependencies
        $protocol = new ConsumeIpcProtocol;

        $configService = Mockery::mock(ConfigService::class);
        $configService->shouldReceive('getConsumePort')->andReturn($this->testPort);
        $configService->shouldReceive('getAgentMaxAttempts')->andReturn(3);
        $configService->shouldReceive('getAgentLimits')->andReturn([]);
        $configService->shouldReceive('getAgentConfigs')->andReturn([]);

        // Bind mock to container so app(ConfigService::class) uses it
        app()->instance(ConfigService::class, $configService);

        $ipcServer = new ConsumeIpcServer($protocol, $configService);

        $processManager = Mockery::mock(ProcessManager::class);
        $processManager->shouldReceive('registerSignalHandlers')->once();
        $processManager->shouldReceive('setOutputCallback')->once();
        // Return false once (loop runs), then true (loop exits without cleanup)
        $processManager->shouldReceive('isShuttingDown')->andReturn(false, true);
        $processManager->shouldReceive('poll')->andReturn([]);
        $processManager->shouldReceive('getActiveProcesses')->andReturn([]);
        $processManager->shouldReceive('getAgentCount')->andReturn(0);

        $taskService = Mockery::mock(TaskService::class);
        $taskService->shouldReceive('ready')->andReturn(collect());
        $taskService->shouldReceive('all')->andReturn(collect());
        $taskService->shouldReceive('blocked')->andReturn(collect());
        $taskService->shouldReceive('find')->andReturn(null);

        $runService = Mockery::mock(RunService::class);
        $backoffStrategy = Mockery::mock(BackoffStrategy::class);
        $promptBuilder = Mockery::mock(TaskPromptBuilder::class);

        // Use real FuelContext and LifecycleManager since LifecycleManager is final
        $fuelContext = new FuelContext($this->testDir.'/.fuel');
        $lifecycleManager = new LifecycleManager($fuelContext);

        // Mock health tracker for TaskSpawner
        $healthTracker = Mockery::mock(AgentHealthTrackerInterface::class);
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

        // Create CompletionHandler (can't mock as it's final)
        // Mock review service for CompletionHandler
        $reviewService = Mockery::mock(ReviewServiceInterface::class);

        $completionHandler = new CompletionHandler(
            $processManager,
            $taskService,
            $runService,
            $configService,
            $healthTracker,
            $reviewService
        );

        // Mock BrowserDaemonManager for BrowserCommandHandler
        $browserManager = Mockery::mock(BrowserDaemonManager::class);
        $browserManager->shouldReceive('start')->once();
        $browserManager->shouldReceive('stop')->zeroOrMoreTimes();

        // Create real BrowserCommandHandler instance (it's a final class and cannot be mocked)
        $browserCommandHandler = new BrowserCommandHandler(
            $browserManager,
            $ipcServer,
            $lifecycleManager
        );

        // Create IpcCommandDispatcher
        $ipcCommandDispatcher = new IpcCommandDispatcher(
            $ipcServer,
            $lifecycleManager,
            $completionHandler,
            $configService,
            $browserCommandHandler
        );

        // Create SnapshotManager
        $snapshotManager = new SnapshotManager(
            $ipcServer,
            $taskService,
            $processManager,
            $healthTracker,
            $lifecycleManager
        );

        // Create runner
        $runner = new ConsumeRunner(
            $ipcServer,
            $processManager,
            $taskService,
            $configService,
            $runService,
            $lifecycleManager,
            $taskSpawner,
            $completionHandler,
            $ipcCommandDispatcher,
            $snapshotManager,
            $browserManager,
        );

        // Skip cleanup for this test (we're just testing PID file creation)
        $runner->setSkipCleanup(true);

        // Start runner (will exit immediately due to isShuttingDown=true)
        $runner->start();

        // Verify stale PID file was deleted and new one was created
        expect(file_exists('.fuel/consume.pid'))->toBeTrue();

        // Read new PID file
        $newPidData = json_decode(file_get_contents('.fuel/consume.pid'), true);
        expect($newPidData['pid'])->not->toBe($stalePid);
        expect($newPidData['pid'])->toBe(getmypid());
        expect($newPidData['instance_id'])->not->toBe('stale-instance-id');

        // Clean up
        $ipcServer->stop();
    });

    test('runner creates PID file on start', function (): void {
        // Ensure no PID file exists
        expect(file_exists('.fuel/consume.pid'))->toBeFalse();

        // Create dependencies
        $protocol = new ConsumeIpcProtocol;

        $configService = Mockery::mock(ConfigService::class);
        $configService->shouldReceive('getConsumePort')->andReturn($this->testPort);
        $configService->shouldReceive('getAgentMaxAttempts')->andReturn(3);
        $configService->shouldReceive('getAgentLimits')->andReturn([]);
        $configService->shouldReceive('getAgentConfigs')->andReturn([]);

        // Bind mock to container so app(ConfigService::class) uses it
        app()->instance(ConfigService::class, $configService);

        $ipcServer = new ConsumeIpcServer($protocol, $configService);

        $processManager = Mockery::mock(ProcessManager::class);
        $processManager->shouldReceive('registerSignalHandlers')->once();
        $processManager->shouldReceive('setOutputCallback')->once();
        // Return false once (loop runs), then true (loop exits without cleanup)
        $processManager->shouldReceive('isShuttingDown')->andReturn(false, true);
        $processManager->shouldReceive('poll')->andReturn([]);
        $processManager->shouldReceive('getActiveProcesses')->andReturn([]);
        $processManager->shouldReceive('getAgentCount')->andReturn(0);

        $taskService = Mockery::mock(TaskService::class);
        $taskService->shouldReceive('ready')->andReturn(collect());
        $taskService->shouldReceive('all')->andReturn(collect());
        $taskService->shouldReceive('blocked')->andReturn(collect());
        $taskService->shouldReceive('find')->andReturn(null);

        $runService = Mockery::mock(RunService::class);
        $backoffStrategy = Mockery::mock(BackoffStrategy::class);
        $promptBuilder = Mockery::mock(TaskPromptBuilder::class);

        // Use real FuelContext and LifecycleManager since LifecycleManager is final
        $fuelContext = new FuelContext($this->testDir.'/.fuel');
        $lifecycleManager = new LifecycleManager($fuelContext);

        // Mock health tracker for TaskSpawner
        $healthTracker = Mockery::mock(AgentHealthTrackerInterface::class);
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

        // Create CompletionHandler (can't mock as it's final)
        // Mock review service for CompletionHandler
        $reviewService = Mockery::mock(ReviewServiceInterface::class);

        $completionHandler = new CompletionHandler(
            $processManager,
            $taskService,
            $runService,
            $configService,
            $healthTracker,
            $reviewService
        );

        // Mock BrowserDaemonManager for BrowserCommandHandler
        $browserManager = Mockery::mock(BrowserDaemonManager::class);
        $browserManager->shouldReceive('start')->once();
        $browserManager->shouldReceive('stop')->zeroOrMoreTimes();

        // Create real BrowserCommandHandler instance (it's a final class and cannot be mocked)
        $browserCommandHandler = new BrowserCommandHandler(
            $browserManager,
            $ipcServer,
            $lifecycleManager
        );

        // Create IpcCommandDispatcher
        $ipcCommandDispatcher = new IpcCommandDispatcher(
            $ipcServer,
            $lifecycleManager,
            $completionHandler,
            $configService,
            $browserCommandHandler
        );

        // Create SnapshotManager
        $snapshotManager = new SnapshotManager(
            $ipcServer,
            $taskService,
            $processManager,
            $healthTracker,
            $lifecycleManager
        );

        // Create runner
        $runner = new ConsumeRunner(
            $ipcServer,
            $processManager,
            $taskService,
            $configService,
            $runService,
            $lifecycleManager,
            $taskSpawner,
            $completionHandler,
            $ipcCommandDispatcher,
            $snapshotManager,
            $browserManager,
        );

        // Skip cleanup for this test (we're just testing PID file creation)
        $runner->setSkipCleanup(true);

        // Start runner
        $runner->start();

        // Verify PID file was created
        expect(file_exists('.fuel/consume.pid'))->toBeTrue();

        // Read PID file
        $pidData = json_decode(file_get_contents('.fuel/consume.pid'), true);
        expect($pidData)->toBeArray();
        expect($pidData['pid'])->toBe(getmypid());
        expect($pidData['started_at'])->toBeString();
        expect($pidData['instance_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
        expect($pidData['port'])->toBe($this->testPort);

        // Clean up
        $ipcServer->stop();
    });

    test('runner deletes PID file on stop', function (): void {
        // Create dependencies
        $protocol = new ConsumeIpcProtocol;

        $configService = Mockery::mock(ConfigService::class);
        $configService->shouldReceive('getConsumePort')->andReturn($this->testPort);
        $configService->shouldReceive('getAgentMaxAttempts')->andReturn(3);
        $configService->shouldReceive('getAgentLimits')->andReturn([]);
        $configService->shouldReceive('getAgentConfigs')->andReturn([]);

        // Bind mock to container so app(ConfigService::class) uses it
        app()->instance(ConfigService::class, $configService);

        $ipcServer = new ConsumeIpcServer($protocol, $configService);

        $processManager = Mockery::mock(ProcessManager::class);
        $processManager->shouldReceive('registerSignalHandlers')->once();
        $processManager->shouldReceive('setOutputCallback')->once();
        // First call: false (loop continues), second call: true (loop exits and cleanup runs)
        $processManager->shouldReceive('isShuttingDown')->andReturn(false, false, true);
        $processManager->shouldReceive('poll')->andReturn([]);
        $processManager->shouldReceive('getActiveProcesses')->andReturn([]);
        $processManager->shouldReceive('getAgentCount')->andReturn(0);
        $processManager->shouldReceive('shutdown')->once(); // Cleanup after loop exits

        $taskService = Mockery::mock(TaskService::class);
        $taskService->shouldReceive('ready')->andReturn(collect());
        $taskService->shouldReceive('all')->andReturn(collect());
        $taskService->shouldReceive('blocked')->andReturn(collect());
        $taskService->shouldReceive('find')->andReturn(null);

        $runService = Mockery::mock(RunService::class);
        $backoffStrategy = Mockery::mock(BackoffStrategy::class);
        $promptBuilder = Mockery::mock(TaskPromptBuilder::class);

        // Use real FuelContext and LifecycleManager since LifecycleManager is final
        $fuelContext = new FuelContext($this->testDir.'/.fuel');
        $lifecycleManager = new LifecycleManager($fuelContext);

        // Mock health tracker for TaskSpawner
        $healthTracker = Mockery::mock(AgentHealthTrackerInterface::class);
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

        // Create CompletionHandler (can't mock as it's final)
        // Mock review service for CompletionHandler
        $reviewService = Mockery::mock(ReviewServiceInterface::class);

        $completionHandler = new CompletionHandler(
            $processManager,
            $taskService,
            $runService,
            $configService,
            $healthTracker,
            $reviewService
        );

        // Mock BrowserDaemonManager for BrowserCommandHandler
        $browserManager = Mockery::mock(BrowserDaemonManager::class);
        $browserManager->shouldReceive('start')->once();
        $browserManager->shouldReceive('stop')->zeroOrMoreTimes();

        // Create real BrowserCommandHandler instance (it's a final class and cannot be mocked)
        $browserCommandHandler = new BrowserCommandHandler(
            $browserManager,
            $ipcServer,
            $lifecycleManager
        );

        // Create IpcCommandDispatcher
        $ipcCommandDispatcher = new IpcCommandDispatcher(
            $ipcServer,
            $lifecycleManager,
            $completionHandler,
            $configService,
            $browserCommandHandler
        );

        // Create SnapshotManager
        $snapshotManager = new SnapshotManager(
            $ipcServer,
            $taskService,
            $processManager,
            $healthTracker,
            $lifecycleManager
        );

        // Create runner
        $runner = new ConsumeRunner(
            $ipcServer,
            $processManager,
            $taskService,
            $configService,
            $runService,
            $lifecycleManager,
            $taskSpawner,
            $completionHandler,
            $ipcCommandDispatcher,
            $snapshotManager,
            $browserManager,
        );

        // Call stop() before starting to simulate IPC stop command
        // This sets the shutdown flag
        $runner->stop(graceful: true);

        // Start runner - will detect shutdown flag and cleanup
        $runner->start();

        // Verify PID file was deleted by cleanup
        expect(file_exists('.fuel/consume.pid'))->toBeFalse();

        // Clean up IPC server
        $ipcServer->stop();
    });

    test('malformed PID file is deleted on start', function (): void {
        // Create a malformed PID file (invalid JSON)
        file_put_contents('.fuel/consume.pid', 'invalid json{');
        expect(file_exists('.fuel/consume.pid'))->toBeTrue();

        // Create dependencies
        $protocol = new ConsumeIpcProtocol;

        $configService = Mockery::mock(ConfigService::class);
        $configService->shouldReceive('getConsumePort')->andReturn($this->testPort);
        $configService->shouldReceive('getAgentMaxAttempts')->andReturn(3);
        $configService->shouldReceive('getAgentLimits')->andReturn([]);
        $configService->shouldReceive('getAgentConfigs')->andReturn([]);

        // Bind mock to container so app(ConfigService::class) uses it
        app()->instance(ConfigService::class, $configService);

        $ipcServer = new ConsumeIpcServer($protocol, $configService);

        $processManager = Mockery::mock(ProcessManager::class);
        $processManager->shouldReceive('registerSignalHandlers')->once();
        $processManager->shouldReceive('setOutputCallback')->once();
        // Return false once (loop runs), then true (loop exits without cleanup)
        $processManager->shouldReceive('isShuttingDown')->andReturn(false, true);
        $processManager->shouldReceive('poll')->andReturn([]);
        $processManager->shouldReceive('getActiveProcesses')->andReturn([]);
        $processManager->shouldReceive('getAgentCount')->andReturn(0);

        $taskService = Mockery::mock(TaskService::class);
        $taskService->shouldReceive('ready')->andReturn(collect());
        $taskService->shouldReceive('all')->andReturn(collect());
        $taskService->shouldReceive('blocked')->andReturn(collect());
        $taskService->shouldReceive('find')->andReturn(null);

        $runService = Mockery::mock(RunService::class);
        $backoffStrategy = Mockery::mock(BackoffStrategy::class);
        $promptBuilder = Mockery::mock(TaskPromptBuilder::class);

        // Use real FuelContext and LifecycleManager since LifecycleManager is final
        $fuelContext = new FuelContext($this->testDir.'/.fuel');
        $lifecycleManager = new LifecycleManager($fuelContext);

        // Mock health tracker for TaskSpawner
        $healthTracker = Mockery::mock(AgentHealthTrackerInterface::class);
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

        // Create CompletionHandler (can't mock as it's final)
        // Mock review service for CompletionHandler
        $reviewService = Mockery::mock(ReviewServiceInterface::class);

        $completionHandler = new CompletionHandler(
            $processManager,
            $taskService,
            $runService,
            $configService,
            $healthTracker,
            $reviewService
        );

        // Mock BrowserDaemonManager for BrowserCommandHandler
        $browserManager = Mockery::mock(BrowserDaemonManager::class);
        $browserManager->shouldReceive('start')->once();
        $browserManager->shouldReceive('stop')->zeroOrMoreTimes();

        // Create real BrowserCommandHandler instance (it's a final class and cannot be mocked)
        $browserCommandHandler = new BrowserCommandHandler(
            $browserManager,
            $ipcServer,
            $lifecycleManager
        );

        // Create IpcCommandDispatcher
        $ipcCommandDispatcher = new IpcCommandDispatcher(
            $ipcServer,
            $lifecycleManager,
            $completionHandler,
            $configService,
            $browserCommandHandler
        );

        // Create SnapshotManager
        $snapshotManager = new SnapshotManager(
            $ipcServer,
            $taskService,
            $processManager,
            $healthTracker,
            $lifecycleManager
        );

        // Create runner
        $runner = new ConsumeRunner(
            $ipcServer,
            $processManager,
            $taskService,
            $configService,
            $runService,
            $lifecycleManager,
            $taskSpawner,
            $completionHandler,
            $ipcCommandDispatcher,
            $snapshotManager,
            $browserManager,
        );

        // Skip cleanup for this test (we're just testing PID file creation)
        $runner->setSkipCleanup(true);

        // Start runner
        $runner->start();

        // Verify new PID file was created with valid JSON
        expect(file_exists('.fuel/consume.pid'))->toBeTrue();
        $pidData = json_decode(file_get_contents('.fuel/consume.pid'), true);
        expect($pidData)->toBeArray();
        expect($pidData['pid'])->toBe(getmypid());

        // Clean up
        $ipcServer->stop();
    });

    test('PID file without pid field is deleted on start', function (): void {
        // Create a PID file without the required 'pid' field
        $invalidPidData = [
            'started_at' => '2024-01-01T00:00:00+00:00',
            'instance_id' => 'invalid-instance-id',
        ];
        file_put_contents('.fuel/consume.pid', json_encode($invalidPidData));
        expect(file_exists('.fuel/consume.pid'))->toBeTrue();

        // Create dependencies
        $protocol = new ConsumeIpcProtocol;

        $configService = Mockery::mock(ConfigService::class);
        $configService->shouldReceive('getConsumePort')->andReturn($this->testPort);
        $configService->shouldReceive('getAgentMaxAttempts')->andReturn(3);
        $configService->shouldReceive('getAgentLimits')->andReturn([]);
        $configService->shouldReceive('getAgentConfigs')->andReturn([]);

        // Bind mock to container so app(ConfigService::class) uses it
        app()->instance(ConfigService::class, $configService);

        $ipcServer = new ConsumeIpcServer($protocol, $configService);

        $processManager = Mockery::mock(ProcessManager::class);
        $processManager->shouldReceive('registerSignalHandlers')->once();
        $processManager->shouldReceive('setOutputCallback')->once();
        // Return false once (loop runs), then true (loop exits without cleanup)
        $processManager->shouldReceive('isShuttingDown')->andReturn(false, true);
        $processManager->shouldReceive('poll')->andReturn([]);
        $processManager->shouldReceive('getActiveProcesses')->andReturn([]);
        $processManager->shouldReceive('getAgentCount')->andReturn(0);

        $taskService = Mockery::mock(TaskService::class);
        $taskService->shouldReceive('ready')->andReturn(collect());
        $taskService->shouldReceive('all')->andReturn(collect());
        $taskService->shouldReceive('blocked')->andReturn(collect());
        $taskService->shouldReceive('find')->andReturn(null);

        $runService = Mockery::mock(RunService::class);
        $backoffStrategy = Mockery::mock(BackoffStrategy::class);
        $promptBuilder = Mockery::mock(TaskPromptBuilder::class);

        // Use real FuelContext and LifecycleManager since LifecycleManager is final
        $fuelContext = new FuelContext($this->testDir.'/.fuel');
        $lifecycleManager = new LifecycleManager($fuelContext);

        // Mock health tracker for TaskSpawner
        $healthTracker = Mockery::mock(AgentHealthTrackerInterface::class);
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

        // Create CompletionHandler (can't mock as it's final)
        // Mock review service for CompletionHandler
        $reviewService = Mockery::mock(ReviewServiceInterface::class);

        $completionHandler = new CompletionHandler(
            $processManager,
            $taskService,
            $runService,
            $configService,
            $healthTracker,
            $reviewService
        );

        // Mock BrowserDaemonManager for BrowserCommandHandler
        $browserManager = Mockery::mock(BrowserDaemonManager::class);
        $browserManager->shouldReceive('start')->once();
        $browserManager->shouldReceive('stop')->zeroOrMoreTimes();

        // Create real BrowserCommandHandler instance (it's a final class and cannot be mocked)
        $browserCommandHandler = new BrowserCommandHandler(
            $browserManager,
            $ipcServer,
            $lifecycleManager
        );

        // Create IpcCommandDispatcher
        $ipcCommandDispatcher = new IpcCommandDispatcher(
            $ipcServer,
            $lifecycleManager,
            $completionHandler,
            $configService,
            $browserCommandHandler
        );

        // Create SnapshotManager
        $snapshotManager = new SnapshotManager(
            $ipcServer,
            $taskService,
            $processManager,
            $healthTracker,
            $lifecycleManager
        );

        // Create runner
        $runner = new ConsumeRunner(
            $ipcServer,
            $processManager,
            $taskService,
            $configService,
            $runService,
            $lifecycleManager,
            $taskSpawner,
            $completionHandler,
            $ipcCommandDispatcher,
            $snapshotManager,
            $browserManager,
        );

        // Skip cleanup for this test (we're just testing PID file creation)
        $runner->setSkipCleanup(true);

        // Start runner
        $runner->start();

        // Verify new PID file was created with valid data
        expect(file_exists('.fuel/consume.pid'))->toBeTrue();
        $pidData = json_decode(file_get_contents('.fuel/consume.pid'), true);
        expect($pidData)->toBeArray();
        expect($pidData)->toHaveKey('pid');
        expect($pidData['pid'])->toBe(getmypid());

        // Clean up
        $ipcServer->stop();
    });

    test('runner starts successfully after cleaning stale PID', function (): void {
        // Create a stale PID file
        $stalePidData = [
            'pid' => 99999,
            'started_at' => '2024-01-01T00:00:00+00:00',
            'instance_id' => 'old-instance',
            'port' => 9981,
        ];
        file_put_contents('.fuel/consume.pid', json_encode($stalePidData));

        // Create dependencies
        $protocol = new ConsumeIpcProtocol;

        $configService = Mockery::mock(ConfigService::class);
        $configService->shouldReceive('getConsumePort')->andReturn($this->testPort);
        $configService->shouldReceive('getAgentMaxAttempts')->andReturn(3);
        $configService->shouldReceive('getAgentLimits')->andReturn([]);
        $configService->shouldReceive('getAgentConfigs')->andReturn([]);

        // Bind mock to container so app(ConfigService::class) uses it
        app()->instance(ConfigService::class, $configService);

        $ipcServer = new ConsumeIpcServer($protocol, $configService);

        $processManager = Mockery::mock(ProcessManager::class);
        $processManager->shouldReceive('registerSignalHandlers')->once();
        $processManager->shouldReceive('setOutputCallback')->once();
        $processManager->shouldReceive('isShuttingDown')->andReturn(true);
        $processManager->shouldReceive('getActiveProcesses')->andReturn([]);
        $processManager->shouldReceive('getAgentCount')->andReturn(0);

        $taskService = Mockery::mock(TaskService::class);
        $taskService->shouldReceive('ready')->andReturn(collect());
        $taskService->shouldReceive('all')->andReturn(collect());
        $taskService->shouldReceive('blocked')->andReturn(collect());
        $taskService->shouldReceive('find')->andReturn(null);

        $runService = Mockery::mock(RunService::class);
        $backoffStrategy = Mockery::mock(BackoffStrategy::class);
        $promptBuilder = Mockery::mock(TaskPromptBuilder::class);

        // Use real FuelContext and LifecycleManager since LifecycleManager is final
        $fuelContext = new FuelContext($this->testDir.'/.fuel');
        $lifecycleManager = new LifecycleManager($fuelContext);

        // Mock health tracker for TaskSpawner
        $healthTracker = Mockery::mock(AgentHealthTrackerInterface::class);
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

        // Create CompletionHandler (can't mock as it's final)
        // Mock review service for CompletionHandler
        $reviewService = Mockery::mock(ReviewServiceInterface::class);

        $completionHandler = new CompletionHandler(
            $processManager,
            $taskService,
            $runService,
            $configService,
            $healthTracker,
            $reviewService
        );

        // Mock BrowserDaemonManager for BrowserCommandHandler
        $browserManager = Mockery::mock(BrowserDaemonManager::class);
        $browserManager->shouldReceive('start')->once();
        $browserManager->shouldReceive('stop')->zeroOrMoreTimes();

        // Create real BrowserCommandHandler instance (it's a final class and cannot be mocked)
        $browserCommandHandler = new BrowserCommandHandler(
            $browserManager,
            $ipcServer,
            $lifecycleManager
        );

        // Create IpcCommandDispatcher
        $ipcCommandDispatcher = new IpcCommandDispatcher(
            $ipcServer,
            $lifecycleManager,
            $completionHandler,
            $configService,
            $browserCommandHandler
        );

        // Create SnapshotManager
        $snapshotManager = new SnapshotManager(
            $ipcServer,
            $taskService,
            $processManager,
            $healthTracker,
            $lifecycleManager
        );

        // Create runner
        $runner = new ConsumeRunner(
            $ipcServer,
            $processManager,
            $taskService,
            $configService,
            $runService,
            $lifecycleManager,
            $taskSpawner,
            $completionHandler,
            $ipcCommandDispatcher,
            $snapshotManager,
            $browserManager,
        );

        // Skip cleanup for this test
        $runner->setSkipCleanup(true);

        // Start should succeed (not throw exception)
        expect(fn () => $runner->start())->not->toThrow(Exception::class);

        // Verify runner is not shutting down initially (before ProcessManager says so)
        expect($runner->isShuttingDown())->toBeTrue(); // It will be true because we mocked isShuttingDown

        // Verify new PID file exists with correct PID
        expect(file_exists('.fuel/consume.pid'))->toBeTrue();

        $newPidData = json_decode(file_get_contents('.fuel/consume.pid'), true);
        expect($newPidData['pid'])->toBe(getmypid());
        expect($newPidData['instance_id'])->not->toBe('old-instance');

        // Clean up
        $ipcServer->stop();
    });

    test('concurrent PID file access is protected by flock', function (): void {
        // This test verifies that the flock-based locking prevents race conditions
        // when multiple processes try to access the PID file simultaneously

        // Create a PID file
        $initialPidData = [
            'pid' => 12345,
            'started_at' => '2024-01-01T00:00:00+00:00',
            'instance_id' => 'initial-instance',
            'port' => 9981,
        ];
        file_put_contents('.fuel/consume.pid', json_encode($initialPidData));

        // Fork a child process to simulate concurrent access
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required for concurrency test');
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Failed to fork process');
        } elseif ($pid === 0) {
            // Child process: try to write to PID file
            $fuelContext = new FuelContext($this->testDir.'/.fuel');
            $lifecycleManager = new LifecycleManager($fuelContext);

            // Attempt to write (should use lock)
            try {
                // Use reflection to access private method
                $reflection = new ReflectionClass($lifecycleManager);
                $method = $reflection->getMethod('writePidFile');
                $method->invoke($lifecycleManager, 9982);

                // Child exits successfully
                exit(0);
            } catch (\Exception) {
                // Child exits with error
                exit(1);
            }
        } else {
            // Parent process: also try to write to PID file
            $fuelContext = new FuelContext($this->testDir.'/.fuel');
            $lifecycleManager = new LifecycleManager($fuelContext);

            // Give child a moment to start
            usleep(10000); // 10ms

            // Parent also attempts to write
            try {
                $reflection = new ReflectionClass($lifecycleManager);
                $method = $reflection->getMethod('writePidFile');
                $method->invoke($lifecycleManager, 9983);
            } catch (\Exception $e) {
                $this->fail('Parent failed to write PID file: '.$e->getMessage());
            }

            // Wait for child to complete
            $status = 0;
            pcntl_waitpid($pid, $status);

            // Verify child exited successfully (0 = success)
            expect(pcntl_wexitstatus($status))->toBe(0);

            // Verify PID file exists and contains valid data
            expect(file_exists('.fuel/consume.pid'))->toBeTrue();

            // One of the processes should have won the race
            $finalPidData = json_decode(file_get_contents('.fuel/consume.pid'), true);
            expect($finalPidData)->toBeArray();
            expect($finalPidData)->toHaveKey('pid');
            expect($finalPidData)->toHaveKey('port');

            // Port should be either from parent or child
            expect(in_array($finalPidData['port'], [9982, 9983]))->toBeTrue();

            // Clean up lock file if it exists
            @unlink('.fuel/consume.pid.lock');
        }
    });

    test('lock file is cleaned up after PID file deletion', function (): void {
        // Create dependencies
        $fuelContext = new FuelContext($this->testDir.'/.fuel');
        $lifecycleManager = new LifecycleManager($fuelContext);

        // Start lifecycle (creates PID file and lock file)
        $lifecycleManager->start(9999);

        // Verify both PID file and lock file exist
        expect(file_exists('.fuel/consume.pid'))->toBeTrue();

        // Clean up (deletes PID file and should clean up lock file)
        $lifecycleManager->stop(true);
        $lifecycleManager->cleanup();

        // Verify both PID file and lock file are deleted
        expect(file_exists('.fuel/consume.pid'))->toBeFalse();
        expect(file_exists('.fuel/consume.pid.lock'))->toBeFalse();
    });
});
