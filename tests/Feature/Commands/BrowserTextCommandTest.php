<?php

declare(strict_types=1);

use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;

beforeEach(function () {
    // Create a temporary PID file for testing
    $pidFilePath = sys_get_temp_dir() . '/fuel-test-' . uniqid() . '.pid';
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

    // Mock ConsumeProcessManager
    $processManager = Mockery::mock(\App\Services\ConsumeProcessManager::class);
    $processManager->shouldReceive('isRunning')->andReturn(true);
    app()->instance(\App\Services\ConsumeProcessManager::class, $processManager);

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
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('send')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserTextCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBe('h1');
        expect($cmd->ref)->toBeNull();
        $requestIdToMatch = $cmd->getRequestId();
        return true;
    });
    $ipcClient->shouldReceive('receive')->once()->andReturn([
        new BrowserResponseEvent(
            success: true,
            result: null,
            error: null,
            errorCode: null,
            timestamp: new DateTimeImmutable,
            instanceId: 'test-instance-id',
            requestId: $requestIdToMatch
        ),
    ]);
    $ipcClient->shouldReceive('receive')->once()->andReturn([
        new class($requestIdToMatch) {
            public $requestId;
            public $error = null;
            public $data = ['text' => 'Welcome to Example'];

            public function __construct($requestId) {
                $this->requestId = $requestId;
            }
        },
    ]);
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
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('send')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserTextCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBeNull();
        expect($cmd->ref)->toBe('@e42');
        $requestIdToMatch = $cmd->getRequestId();
        return true;
    });
    $ipcClient->shouldReceive('receive')->once()->andReturn([
        new class($requestIdToMatch) {
            public $requestId;
            public $error = null;
            public $data = ['text' => 'Button Text'];

            public function __construct($requestId) {
                $this->requestId = $requestId;
            }
        },
    ]);
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
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('send')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        $requestIdToMatch = $cmd->getRequestId();
        return true;
    });
    $ipcClient->shouldReceive('receive')->once()->andReturn([
        new class($requestIdToMatch) {
            public $requestId;
            public $error = null;
            public $data = ['text' => 'Test Content'];

            public function __construct($requestId) {
                $this->requestId = $requestId;
            }
        },
    ]);
    $ipcClient->shouldReceive('disconnect')->once();

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:text', [
        'page_id' => 'test-page',
        'selector' => '.content',
        '--json' => true,
    ])
        ->expectsOutput(json_encode([
            'text' => 'Test Content',
        ], JSON_PRETTY_PRINT))
        ->assertExitCode(0);
});

it('handles error responses from daemon', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('send')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        $requestIdToMatch = $cmd->getRequestId();
        return true;
    });
    $ipcClient->shouldReceive('receive')->once()->andReturn([
        new class($requestIdToMatch) {
            public $requestId;
            public $error = 'Element not found: .nonexistent';
            public $data = null;

            public function __construct($requestId) {
                $this->requestId = $requestId;
            }
        },
    ]);
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
    // Mock ConsumeProcessManager to return not running
    $processManager = Mockery::mock(\App\Services\ConsumeProcessManager::class);
    $processManager->shouldReceive('isRunning')->andReturn(false);
    app()->instance(\App\Services\ConsumeProcessManager::class, $processManager);

    // Create mock IPC client that simulates daemon not running
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:text', [
        'page_id' => 'test-page',
        'selector' => 'h1',
    ])
        ->expectsOutputToContain('Fuel consume is not running')
        ->assertExitCode(1);
});

it('requires either selector or ref option', function () {
    // Create mock IPC client
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:text', [
        'page_id' => 'test-page',
    ])
        ->expectsOutputToContain('Either selector or --ref must be provided')
        ->assertExitCode(1);
});