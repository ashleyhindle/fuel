<?php

declare(strict_types=1);

use App\DTO\ConsumeSnapshot;
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

    // Create real instances for final classes (cannot be mocked)
    $this->ipcServer = new ConsumeIpcServer;
    $this->protocol = new ConsumeIpcProtocol;

    // Mock all other dependencies to avoid side effects
    $this->processManager = Mockery::mock(ProcessManager::class);
    $this->taskService = Mockery::mock(TaskService::class);
    $this->configService = Mockery::mock(ConfigService::class);
    $this->runService = Mockery::mock(RunService::class);
    $this->backoffStrategy = Mockery::mock(BackoffStrategy::class);
    $this->promptBuilder = Mockery::mock(TaskPromptBuilder::class);
    $this->fuelContext = Mockery::mock(FuelContext::class);

    // Create ConsumeRunner with all dependencies
    $this->runner = new ConsumeRunner(
        $this->ipcServer,
        $this->processManager,
        $this->protocol,
        $this->taskService,
        $this->configService,
        $this->runService,
        $this->backoffStrategy,
        $this->promptBuilder,
        $this->fuelContext
    );
});

afterEach(function () {
    // Close Mockery
    Mockery::close();

    // Stop IPC server if it was started
    try {
        $this->ipcServer->stop();
    } catch (Exception $e) {
        // Ignore errors from stopping if it wasn't started
    }

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

describe('pause and resume', function () {
    test('pause() sets paused to true', function () {
        expect($this->runner->isPaused())->toBeTrue();

        $this->runner->pause();

        expect($this->runner->isPaused())->toBeTrue();
    });

    test('isPaused() returns true after pause', function () {
        $this->runner->pause();

        expect($this->runner->isPaused())->toBeTrue();
    });

    test('resume() sets paused to false', function () {
        $this->runner->pause();
        expect($this->runner->isPaused())->toBeTrue();

        $this->runner->resume();

        expect($this->runner->isPaused())->toBeFalse();
    });

    test('isPaused() returns false after resume', function () {
        $this->runner->pause();
        $this->runner->resume();

        expect($this->runner->isPaused())->toBeFalse();
    });

    test('isPaused() returns true initially', function () {
        expect($this->runner->isPaused())->toBeTrue();
    });
});

describe('stop', function () {
    test('stop(graceful=true) sets shuttingDown to true', function () {
        expect($this->runner->isShuttingDown())->toBeFalse();

        // stop() should only set the flag, not call shutdown (that happens in cleanup())
        $this->runner->stop(graceful: true);

        expect($this->runner->isShuttingDown())->toBeTrue();
    });

    test('stop(graceful=false) sets shuttingDown to true and kills processes', function () {
        expect($this->runner->isShuttingDown())->toBeFalse();

        // Mock ProcessManager expectations for force shutdown
        // Force shutdown kills processes immediately in stop()
        $this->processManager->shouldReceive('getActiveProcesses')->once()->andReturn([]);

        $this->runner->stop(graceful: false);

        expect($this->runner->isShuttingDown())->toBeTrue();
    });

    test('isShuttingDown() returns false initially', function () {
        expect($this->runner->isShuttingDown())->toBeFalse();
    });
});

describe('getSnapshot', function () {
    test('returns ConsumeSnapshot with required keys', function () {
        $snapshot = $this->runner->getSnapshot();

        expect($snapshot)->toBeInstanceOf(ConsumeSnapshot::class);
        expect($snapshot->boardState)->toBeArray();
        expect($snapshot->activeProcesses)->toBeArray();
        expect($snapshot->healthSummary)->toBeArray();
        expect($snapshot->runnerState)->toBeArray();
        expect($snapshot->config)->toBeArray();
    });

    test('snapshot board_state has all required status keys', function () {
        $snapshot = $this->runner->getSnapshot();

        expect($snapshot->boardState)->toHaveKeys([
            'ready',
            'in_progress',
            'review',
            'blocked',
            'human',
            'done',
        ]);
    });

    test('snapshot board_state values are Collections', function () {
        $snapshot = $this->runner->getSnapshot();

        expect($snapshot->boardState['ready'])->toBeInstanceOf(\Illuminate\Support\Collection::class);
        expect($snapshot->boardState['in_progress'])->toBeInstanceOf(\Illuminate\Support\Collection::class);
        expect($snapshot->boardState['review'])->toBeInstanceOf(\Illuminate\Support\Collection::class);
        expect($snapshot->boardState['blocked'])->toBeInstanceOf(\Illuminate\Support\Collection::class);
        expect($snapshot->boardState['human'])->toBeInstanceOf(\Illuminate\Support\Collection::class);
        expect($snapshot->boardState['done'])->toBeInstanceOf(\Illuminate\Support\Collection::class);
    });

    test('snapshot runner_state includes paused', function () {
        $snapshot = $this->runner->getSnapshot();

        expect($snapshot->runnerState)->toHaveKey('paused');
        expect($snapshot->runnerState['paused'])->toBeBool();
    });

    test('snapshot runner_state includes instance_id', function () {
        $snapshot = $this->runner->getSnapshot();

        expect($snapshot->runnerState)->toHaveKey('instance_id');
        expect($snapshot->runnerState['instance_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
    });

    test('snapshot runner_state includes started_at', function () {
        $snapshot = $this->runner->getSnapshot();

        expect($snapshot->runnerState)->toHaveKey('started_at');
        expect($snapshot->runnerState['started_at'])->toBeInt();
        expect($snapshot->runnerState['started_at'])->toBeGreaterThan(0);
    });

    test('snapshot runner_state reflects current paused state', function () {
        // Initially paused (runner starts paused)
        $snapshot1 = $this->runner->getSnapshot();
        expect($snapshot1->runnerState['paused'])->toBeTrue();

        // Resume and check again
        $this->runner->resume();
        $snapshot2 = $this->runner->getSnapshot();
        expect($snapshot2->runnerState['paused'])->toBeFalse();

        // Pause and check again
        $this->runner->pause();
        $snapshot3 = $this->runner->getSnapshot();
        expect($snapshot3->runnerState['paused'])->toBeTrue();
    });
});

describe('getInstanceId', function () {
    test('returns instance ID in UUID format', function () {
        $id = $this->runner->getInstanceId();

        expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
    });

    test('instance ID is consistent across calls', function () {
        $id1 = $this->runner->getInstanceId();
        $id2 = $this->runner->getInstanceId();

        expect($id1)->toBe($id2);
    });
});
