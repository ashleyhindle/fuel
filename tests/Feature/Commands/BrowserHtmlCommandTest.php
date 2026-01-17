<?php

declare(strict_types=1);

use App\Ipc\Commands\BrowserHtmlCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use App\Services\FuelContext;

beforeEach(function (): void {
    // Create a temporary PID file for testing
    $pidFilePath = sys_get_temp_dir().'/fuel-test-'.uniqid().'.pid';
    $pidData = [
        'pid' => 12345,
        'port' => 9876,
        'started_at' => time(),
    ];
    file_put_contents($pidFilePath, json_encode($pidData));

    // Mock FuelContext to return our test PID file path
    $fuelContext = Mockery::mock(FuelContext::class);
    $fuelContext->shouldReceive('getPidFilePath')->andReturn($pidFilePath);
    app()->instance(FuelContext::class, $fuelContext);

    $this->pidFilePath = $pidFilePath;
});

afterEach(function (): void {
    // Clean up test PID file
    if (property_exists($this, 'pidFilePath') && $this->pidFilePath !== null && file_exists($this->pidFilePath)) {
        unlink($this->pidFilePath);
    }

    Mockery::close();
});

it('sends html command with selector to daemon for outerHTML', function (): void {
    // Create mock IPC client
    $requestIdToMatch = null;
    $pollCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $ipcClient->shouldReceive('isRunnerAlive')->once()->with($pidFile)->andReturn(true);
    $ipcClient->shouldReceive('connect')->once()->with(9876);
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        expect($cmd)->toBeInstanceOf(BrowserHtmlCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBe('#content');
        expect($cmd->ref)->toBeNull();
        expect($cmd->inner)->toBeFalse();

        $requestIdToMatch = $cmd->getRequestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$pollCount): array {
        $pollCount++;
        if ($pollCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['html' => '<div id="content">Hello World</div>'],
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

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
        'target' => '#content',
    ])
        ->expectsOutputToContain('<div id="content">Hello World</div>')
        ->assertExitCode(0);
});

it('sends html command with inner flag for innerHTML', function (): void {
    // Create mock IPC client
    $requestIdToMatch = null;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $ipcClient->shouldReceive('isRunnerAlive')->once()->with($pidFile)->andReturn(true);
    $ipcClient->shouldReceive('connect')->once()->with(9876);
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        expect($cmd)->toBeInstanceOf(BrowserHtmlCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBe('#content');
        expect($cmd->ref)->toBeNull();
        expect($cmd->inner)->toBeTrue();

        $requestIdToMatch = $cmd->getRequestId();

        return true;
    });
    $pollCount = 0;
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$pollCount): array {
        $pollCount++;
        if ($pollCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['html' => 'Hello World'],
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

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
        'target' => '#content',
        '--inner' => true,
    ])
        ->expectsOutputToContain('Hello World')
        ->assertExitCode(0);
});

it('sends html command with element ref to daemon', function (): void {
    // Create mock IPC client
    $requestIdToMatch = null;
    $pollCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $ipcClient->shouldReceive('isRunnerAlive')->once()->with($pidFile)->andReturn(true);
    $ipcClient->shouldReceive('connect')->once()->with(9876);
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        expect($cmd)->toBeInstanceOf(BrowserHtmlCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBeNull();
        expect($cmd->ref)->toBe('@e10');
        expect($cmd->inner)->toBeFalse();

        $requestIdToMatch = $cmd->getRequestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$pollCount): array {
        $pollCount++;
        if ($pollCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['html' => '<button>Click Me</button>'],
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

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
        'target' => '@e10',
    ])
        ->expectsOutputToContain('<button>Click Me</button>')
        ->assertExitCode(0);
});

it('outputs JSON when json flag is provided', function (): void {
    // Create mock IPC client
    $requestIdToMatch = null;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $ipcClient->shouldReceive('isRunnerAlive')->once()->with($pidFile)->andReturn(true);
    $ipcClient->shouldReceive('connect')->once()->with(9876);
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        $requestIdToMatch = $cmd->getRequestId();

        return true;
    });
    $pollCount = 0;
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$pollCount): array {
        $pollCount++;
        if ($pollCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['html' => '<p>Test paragraph</p>'],
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

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
        'target' => 'p',
        '--json' => true,
    ])
        ->expectsOutput(json_encode([
            'success' => true,
            'data' => ['html' => '<p>Test paragraph</p>'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);
});

it('handles error responses from daemon', function (): void {
    // Create mock IPC client
    $requestIdToMatch = null;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $ipcClient->shouldReceive('isRunnerAlive')->once()->with($pidFile)->andReturn(true);
    $ipcClient->shouldReceive('connect')->once()->with(9876);
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        $requestIdToMatch = $cmd->getRequestId();

        return true;
    });
    $pollCount = 0;
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$pollCount): array {
        $pollCount++;
        if ($pollCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: false,
                    result: null,
                    error: 'Element not found: .missing',
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

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
        'target' => '.missing',
    ])
        ->expectsOutputToContain('Element not found: .missing')
        ->assertExitCode(1);
});

it('fails when daemon is not running', function (): void {
    // Remove PID file to simulate daemon not running
    if (file_exists($this->pidFilePath)) {
        unlink($this->pidFilePath);
    }

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
        'target' => 'body',
    ])
        ->expectsOutputToContain('Consume daemon is not running')
        ->assertExitCode(1);
});

it('handles inner flag with element ref', function (): void {
    // Create mock IPC client
    $requestIdToMatch = null;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $ipcClient->shouldReceive('isRunnerAlive')->once()->with($pidFile)->andReturn(true);
    $ipcClient->shouldReceive('connect')->once()->with(9876);
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        expect($cmd)->toBeInstanceOf(BrowserHtmlCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBeNull();
        expect($cmd->ref)->toBe('@e5');
        expect($cmd->inner)->toBeTrue();

        $requestIdToMatch = $cmd->getRequestId();

        return true;
    });
    $pollCount = 0;
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$pollCount): array {
        $pollCount++;
        if ($pollCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['html' => 'Inner content only'],
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

    $this->artisan('browser:html', [
        'page_id' => 'test-page',
        'target' => '@e5',
        '--inner' => true,
    ])
        ->expectsOutputToContain('Inner content only')
        ->assertExitCode(0);
});
