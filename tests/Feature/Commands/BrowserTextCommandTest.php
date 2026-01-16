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

it('sends text command with selector to daemon', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $ipcClient->shouldReceive('isRunnerAlive')->once()->with($pidFile)->andReturn(true);
    $ipcClient->shouldReceive('connect')->once()->with(9876);
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserTextCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBe('h1');
        expect($cmd->ref)->toBeNull();
        $requestIdToMatch = $cmd->getRequestId();

        return true;
    });
    $pollCount = 0;
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$pollCount) {
        $pollCount++;
        if ($pollCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['text' => 'Welcome to Example'],
                    error: null,
                    errorCode: null,
                    timestamp: new \DateTimeImmutable,
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

    $this->artisan('browser:text', [
        'page_id' => 'test-page',
        'selector' => 'h1',
    ])
        ->expectsOutputToContain('Welcome to Example')
        ->assertExitCode(0);
});

it('sends text command with element ref to daemon', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $ipcClient->shouldReceive('isRunnerAlive')->once()->with($pidFile)->andReturn(true);
    $ipcClient->shouldReceive('connect')->once()->with(9876);
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserTextCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBeNull();
        expect($cmd->ref)->toBe('@e42');
        $requestIdToMatch = $cmd->getRequestId();

        return true;
    });
    $pollCount = 0;
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$pollCount) {
        $pollCount++;
        if ($pollCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['text' => 'Button Text'],
                    error: null,
                    errorCode: null,
                    timestamp: new \DateTimeImmutable,
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

    $this->artisan('browser:text', [
        'page_id' => 'test-page',
        '--ref' => '@e42',
    ])
        ->expectsOutputToContain('Button Text')
        ->assertExitCode(0);
});

it('outputs JSON when json flag is provided', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $ipcClient->shouldReceive('isRunnerAlive')->once()->with($pidFile)->andReturn(true);
    $ipcClient->shouldReceive('connect')->once()->with(9876);
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        $requestIdToMatch = $cmd->getRequestId();

        return true;
    });
    $pollCount = 0;
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$pollCount) {
        $pollCount++;
        if ($pollCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['text' => 'Test Content'],
                    error: null,
                    errorCode: null,
                    timestamp: new \DateTimeImmutable,
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

    $this->artisan('browser:text', [
        'page_id' => 'test-page',
        'selector' => '.content',
        '--json' => true,
    ])
        ->expectsOutput(json_encode([
            'success' => true,
            'data' => ['text' => 'Test Content'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);
});

it('handles error responses from daemon', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $ipcClient->shouldReceive('isRunnerAlive')->once()->with($pidFile)->andReturn(true);
    $ipcClient->shouldReceive('connect')->once()->with(9876);
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        $requestIdToMatch = $cmd->getRequestId();

        return true;
    });
    $pollCount = 0;
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$pollCount) {
        $pollCount++;
        if ($pollCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: false,
                    result: null,
                    error: 'Element not found: .nonexistent',
                    errorCode: 'ELEMENT_NOT_FOUND',
                    timestamp: new \DateTimeImmutable,
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

    $this->artisan('browser:text', [
        'page_id' => 'test-page',
        'selector' => '.nonexistent',
    ])
        ->expectsOutputToContain('Element not found: .nonexistent')
        ->assertExitCode(1);
});

it('fails when daemon is not running', function () {
    // Remove PID file to simulate daemon not running
    if (file_exists($this->pidFilePath)) {
        unlink($this->pidFilePath);
    }

    $this->artisan('browser:text', [
        'page_id' => 'test-page',
        'selector' => 'h1',
    ])
        ->expectsOutputToContain('Consume daemon is not running')
        ->assertExitCode(1);
});

it('requires either selector or ref option', function () {
    // No need to mock IPC client since validation happens before connection

    $this->artisan('browser:text', [
        'page_id' => 'test-page',
    ])
        ->expectsOutputToContain('Either selector or --ref must be provided')
        ->assertExitCode(1);
});
