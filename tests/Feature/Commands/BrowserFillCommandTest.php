<?php

declare(strict_types=1);

use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use App\Services\FuelContext;

beforeEach(function () {
    // Create a temporary PID file for testing
    $pidFilePath = sys_get_temp_dir().'/fuel-test-'.uniqid().'.pid';
    $pidData = [
        'pid' => 12345,
        'port' => 9876,
        'started_at' => time(),
    ];
    file_put_contents($pidFilePath, json_encode($pidData));

    // Mock FuelContext to return our test PID file path
    $fuelContext = Mockery::mock(\App\Services\FuelContext::class);
    $fuelContext->shouldReceive('getPidFilePath')->andReturn($pidFilePath);
    app()->instance(\App\Services\FuelContext::class, $fuelContext);

    $this->pidFilePath = $pidFilePath;
});

afterEach(function () {
    // Clean up test PID file
    if (isset($this->pidFilePath) && file_exists($this->pidFilePath)) {
        unlink($this->pidFilePath);
    }
    Mockery::close();
});

it('sends fill command to daemon with selector', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserFillCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBe('input#email');
        expect($cmd->value)->toBe('test@example.com');
        expect($cmd->ref)->toBeNull();
        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['message' => 'Filled successfully'],
                    error: null,
                    errorCode: null,
                    timestamp: new DateTimeImmutable,
                    instanceId: 'test-instance-id',
                    requestId: $requestIdToMatch
                ),
            ];
        }

        return [];
    });
    $ipcClient->shouldReceive('detach')->once();
    $ipcClient->shouldReceive('disconnect')->once();

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command
    $this->artisan('browser:fill', [
        'page_id' => 'test-page',
        'selector' => 'input#email',
        '--value' => 'test@example.com',
    ])
        ->expectsOutputToContain('Filled input#email with: test@example.com')
        ->assertExitCode(0);
});

it('sends fill command to daemon with element ref', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserFillCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBeNull();
        expect($cmd->value)->toBe('test@example.com');
        expect($cmd->ref)->toBe('@e3');
        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['message' => 'Filled successfully'],
                    error: null,
                    errorCode: null,
                    timestamp: new DateTimeImmutable,
                    instanceId: 'test-instance-id',
                    requestId: $requestIdToMatch
                ),
            ];
        }

        return [];
    });
    $ipcClient->shouldReceive('detach')->once();
    $ipcClient->shouldReceive('disconnect')->once();

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command with ref
    $this->artisan('browser:fill', [
        'page_id' => 'test-page',
        '--value' => 'test@example.com',
        '--ref' => '@e3',
    ])
        ->expectsOutputToContain('Filled @e3 with: test@example.com')
        ->assertExitCode(0);
});

it('outputs JSON when --json flag is provided', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['message' => 'Filled successfully'],
                    error: null,
                    errorCode: null,
                    timestamp: new DateTimeImmutable,
                    instanceId: 'test-instance-id',
                    requestId: $requestIdToMatch
                ),
            ];
        }

        return [];
    });
    $ipcClient->shouldReceive('detach')->once();
    $ipcClient->shouldReceive('disconnect')->once();

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command with JSON output
    $this->artisan('browser:fill', [
        'page_id' => 'test-page',
        'selector' => 'input',
        '--value' => 'test value',
        '--json' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutput(json_encode([
            'success' => true,
            'message' => 'Filled input with: test value',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
});

it('shows error when daemon is not running', function () {
    // Create mock IPC client that simulates daemon not running
    $ipcClient = Mockery::mock(ConsumeIpcClient::class, function (Mockery\MockInterface $mock) {
        $mock->shouldReceive('isRunnerAlive')->andReturn(false);
    });

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command
    $this->artisan('browser:fill', [
        'page_id' => 'test-page',
        'selector' => 'input',
        '--value' => 'test',
    ])
        ->expectsOutputToContain('Consume daemon is not running')
        ->assertExitCode(1);
});

it('requires either selector or ref option', function () {
    // Create PID file for the test
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $pidDir = dirname($pidFile);
    if (! is_dir($pidDir)) {
        mkdir($pidDir, 0755, true);
    }
    file_put_contents($pidFile, json_encode(['pid' => getmypid(), 'port' => 9999]));

    // Create mock IPC client
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('detach')->once();
    $ipcClient->shouldReceive('disconnect')->once();

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command without selector or ref - should fail validation
    $this->artisan('browser:fill', [
        'page_id' => 'test-page',
        '--value' => 'test',
    ])
        ->expectsOutputToContain('Must provide either a selector or --ref option')
        ->assertExitCode(1);

    // Clean up
    @unlink($pidFile);
});
