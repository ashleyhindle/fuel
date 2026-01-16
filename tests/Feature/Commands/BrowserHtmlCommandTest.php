<?php

declare(strict_types=1);

use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use App\Services\FuelContext;
use DateTimeImmutable;
use Mockery;

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

it('sends html command with selector to daemon for outerHTML', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $pollCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $ipcClient->shouldReceive('isRunnerAlive')->once()->with($pidFile)->andReturn(true);
    $ipcClient->shouldReceive('connect')->once()->with(9876);
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserHtmlCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBe('#content');
        expect($cmd->ref)->toBeNull();
        expect($cmd->inner)->toBeFalse();
        $requestIdToMatch = $cmd->getRequestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$pollCount) {
        $pollCount++;
        if ($pollCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['html' => '<div id="content">Hello World</div>'],
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

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
        'selector' => '#content',
    ])
        ->expectsOutputToContain('<div id="content">Hello World</div>')
        ->assertExitCode(0);
});

it('sends html command with inner flag for innerHTML', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $ipcClient->shouldReceive('isRunnerAlive')->once()->with($pidFile)->andReturn(true);
    $ipcClient->shouldReceive('connect')->once()->with(9876);
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserHtmlCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBe('#content');
        expect($cmd->ref)->toBeNull();
        expect($cmd->inner)->toBeTrue();
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
                    result: ['html' => 'Hello World'],
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

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
        'selector' => '#content',
        '--inner' => true,
    ])
        ->expectsOutputToContain('Hello World')
        ->assertExitCode(0);
});

it('sends html command with element ref to daemon', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $pollCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $ipcClient->shouldReceive('isRunnerAlive')->once()->with($pidFile)->andReturn(true);
    $ipcClient->shouldReceive('connect')->once()->with(9876);
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserHtmlCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBeNull();
        expect($cmd->ref)->toBe('@e10');
        expect($cmd->inner)->toBeFalse();
        $requestIdToMatch = $cmd->getRequestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$pollCount) {
        $pollCount++;
        if ($pollCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['html' => '<button>Click Me</button>'],
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

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
        '--ref' => '@e10',
    ])
        ->expectsOutputToContain('<button>Click Me</button>')
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
    $ipcClient->shouldReceive('pollEvents')->once()->andReturn([
        new BrowserResponseEvent(
            success: true,
            result: ['html' => '<p>Test paragraph</p>'],
            error: null,
            errorCode: null,
            timestamp: new DateTimeImmutable,
            instanceId: 'test-instance-id',
            requestId: $requestIdToMatch
        ),
    ]);
    $ipcClient->shouldReceive('detach')->once();
    $ipcClient->shouldReceive('disconnect')->once();

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
        'selector' => 'p',
        '--json' => true,
    ])
        ->expectsOutput(json_encode([
            'success' => true,
            'data' => ['html' => '<p>Test paragraph</p>'],
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
    $ipcClient->shouldReceive('pollEvents')->once()->andReturn([
        new BrowserResponseEvent(
            success: false,
            result: null,
            error: 'Element not found: .missing',
            errorCode: 'ELEMENT_NOT_FOUND',
            timestamp: new DateTimeImmutable,
            instanceId: 'test-instance-id',
            requestId: $requestIdToMatch
        ),
    ]);
    $ipcClient->shouldReceive('detach')->once();
    $ipcClient->shouldReceive('disconnect')->once();

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
        'selector' => '.missing',
    ])
        ->expectsOutputToContain('Element not found: .missing')
        ->assertExitCode(1);
});

it('fails when daemon is not running', function () {
    // Remove PID file to simulate daemon not running
    if (file_exists($this->pidFilePath)) {
        unlink($this->pidFilePath);
    }

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
        'selector' => 'body',
    ])
        ->expectsOutputToContain('Consume daemon is not running')
        ->assertExitCode(1);
});

it('requires either selector or ref option', function () {
    // No need to mock IPC client since validation happens before connection

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
    ])
        ->expectsOutputToContain('Either selector or --ref must be provided')
        ->assertExitCode(1);
});

it('handles inner flag with element ref', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $ipcClient->shouldReceive('isRunnerAlive')->once()->with($pidFile)->andReturn(true);
    $ipcClient->shouldReceive('connect')->once()->with(9876);
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserHtmlCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBeNull();
        expect($cmd->ref)->toBe('@e5');
        expect($cmd->inner)->toBeTrue();
        $requestIdToMatch = $cmd->getRequestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->once()->andReturn([
        new BrowserResponseEvent(
            success: true,
            result: ['html' => 'Inner content only'],
            error: null,
            errorCode: null,
            timestamp: new DateTimeImmutable,
            instanceId: 'test-instance-id',
            requestId: $requestIdToMatch
        ),
    ]);
    $ipcClient->shouldReceive('detach')->once();
    $ipcClient->shouldReceive('disconnect')->once();

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
        '--ref' => '@e5',
        '--inner' => true,
    ])
        ->expectsOutputToContain('Inner content only')
        ->assertExitCode(0);
});
