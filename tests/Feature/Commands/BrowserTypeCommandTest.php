<?php

declare(strict_types=1);

use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;

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

it('sends type command to daemon with selector', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserTypeCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBe('input#search');
        expect($cmd->text)->toBe('Hello World');
        expect($cmd->ref)->toBeNull();
        expect($cmd->delay)->toBe(0);
        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['message' => 'Typed successfully'],
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
    $this->artisan('browser:type', [
        'page_id' => 'test-page',
        'selector' => 'input#search',
        '--text' => 'Hello World',
    ])
        ->expectsOutputToContain('Typed into input#search: Hello World')
        ->assertExitCode(0);
});

it('sends type command to daemon with element ref', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserTypeCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBeNull();
        expect($cmd->text)->toBe('Search query');
        expect($cmd->ref)->toBe('@e5');
        expect($cmd->delay)->toBe(0);
        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['message' => 'Typed successfully'],
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
    $this->artisan('browser:type', [
        'page_id' => 'test-page',
        '--text' => 'Search query',
        '--ref' => '@e5',
    ])
        ->expectsOutputToContain('Typed into @e5: Search query')
        ->assertExitCode(0);
});

it('supports delay option for typing', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserTypeCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBe('textarea');
        expect($cmd->text)->toBe('Slow typing');
        expect($cmd->delay)->toBe(100);
        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['message' => 'Typed successfully'],
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

    // Execute command with delay
    $this->artisan('browser:type', [
        'page_id' => 'test-page',
        'selector' => 'textarea',
        '--text' => 'Slow typing',
        '--delay' => '100',
    ])
        ->expectsOutputToContain('Typed into textarea: Slow typing')
        ->assertExitCode(0);
});

it('truncates long text in output', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $longText = str_repeat('a', 60);
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch, $longText) {
        expect($cmd->text)->toBe($longText);
        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['message' => 'Typed successfully'],
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

    // Execute command with long text
    $this->artisan('browser:type', [
        'page_id' => 'test-page',
        'selector' => 'input',
        '--text' => $longText,
    ])
        ->expectsOutputToContain(str_repeat('a', 47).'...')
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
                    result: ['message' => 'Typed successfully'],
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
    $this->artisan('browser:type', [
        'page_id' => 'test-page',
        'selector' => 'input',
        '--text' => 'test text',
        '--json' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutput(json_encode([
            'success' => true,
            'message' => 'Typed into input: test text',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
});

it('shows error when daemon is not running', function () {
    // Create mock IPC client that simulates daemon not running
    $ipcClient = Mockery::mock(ConsumeIpcClient::class, function (Mockery\MockInterface $mock) {
        $mock->shouldReceive('isRunnerAlive')->andReturn(false);
    });

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command
    $this->artisan('browser:type', [
        'page_id' => 'test-page',
        'selector' => 'input',
        '--text' => 'test',
    ])
        ->expectsOutputToContain('Consume daemon is not running')
        ->assertExitCode(1);
});

it('requires either selector or ref option', function () {
    // Create mock IPC client
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command without selector or ref - should fail validation
    $this->artisan('browser:type', [
        'page_id' => 'test-page',
        '--text' => 'test',
    ])
        ->expectsOutputToContain('Must provide either a selector or --ref option')
        ->assertExitCode(1);
});

it('cannot provide both selector and ref', function () {
    // Create mock IPC client
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command with both selector and ref - should fail validation
    $this->artisan('browser:type', [
        'page_id' => 'test-page',
        'selector' => 'input',
        '--text' => 'test',
        '--ref' => '@e1',
    ])
        ->expectsOutputToContain('Cannot provide both selector and --ref option')
        ->assertExitCode(1);
});
