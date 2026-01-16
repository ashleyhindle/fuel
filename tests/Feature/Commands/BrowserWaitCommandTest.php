<?php

declare(strict_types=1);

use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use App\Services\FuelContext;
use DateTimeImmutable;
use Mockery;

it('sends wait command with selector to daemon', function () {
    // Create PID file for the test
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $pidDir = dirname($pidFile);
    if (! is_dir($pidDir)) {
        mkdir($pidDir, 0755, true);
    }
    file_put_contents($pidFile, json_encode(['pid' => getmypid(), 'port' => 9999]));

    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($command) use (&$requestIdToMatch) {
        expect($command)->toBeInstanceOf(App\Ipc\Commands\BrowserWaitCommand::class);
        expect($command->pageId)->toBe('test-page');
        expect($command->selector)->toBe('.submit-button');
        expect($command->url)->toBeNull();
        expect($command->text)->toBeNull();
        expect($command->state)->toBe('visible');
        expect($command->timeout)->toBe(30000);
        $requestIdToMatch = $command->requestId();
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: [
                        'waited' => true,
                        'type' => 'selector',
                        'selector' => '.submit-button',
                    ],
                    error: null,
                    errorCode: null,
                    timestamp: new DateTimeImmutable,
                    instanceId: 'test-instance',
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
    $this->artisan('browser:wait', [
        'page_id' => 'test-page',
        '--selector' => '.submit-button',
    ])
        ->expectsOutputToContain('Wait completed successfully')
        ->expectsOutputToContain('Type: selector')
        ->expectsOutputToContain('Found selector: .submit-button')
        ->assertExitCode(0);
});

it('sends wait command with URL to daemon', function () {
    // Create PID file for the test
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $pidDir = dirname($pidFile);
    if (! is_dir($pidDir)) {
        mkdir($pidDir, 0755, true);
    }
    file_put_contents($pidFile, json_encode(['pid' => getmypid(), 'port' => 9999]));

    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($command) use (&$requestIdToMatch) {
        expect($command)->toBeInstanceOf(App\Ipc\Commands\BrowserWaitCommand::class);
        expect($command->pageId)->toBe('test-page');
        expect($command->selector)->toBeNull();
        expect($command->url)->toBe('https://example.com/success');
        expect($command->text)->toBeNull();
        $requestIdToMatch = $command->requestId();
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: [
                        'waited' => true,
                        'type' => 'url',
                        'url' => 'https://example.com/success',
                    ],
                    error: null,
                    errorCode: null,
                    timestamp: new DateTimeImmutable,
                    instanceId: 'test-instance',
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
    $this->artisan('browser:wait', [
        'page_id' => 'test-page',
        '--url' => 'https://example.com/success',
    ])
        ->expectsOutputToContain('Wait completed successfully')
        ->expectsOutputToContain('Type: url')
        ->expectsOutputToContain('Navigated to: https://example.com/success')
        ->assertExitCode(0);
});

it('sends wait command with text to daemon', function () {
    // Create PID file for the test
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $pidDir = dirname($pidFile);
    if (! is_dir($pidDir)) {
        mkdir($pidDir, 0755, true);
    }
    file_put_contents($pidFile, json_encode(['pid' => getmypid(), 'port' => 9999]));

    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($command) use (&$requestIdToMatch) {
        expect($command)->toBeInstanceOf(App\Ipc\Commands\BrowserWaitCommand::class);
        expect($command->pageId)->toBe('test-page');
        expect($command->selector)->toBeNull();
        expect($command->url)->toBeNull();
        expect($command->text)->toBe('Welcome to the site');
        expect($command->state)->toBe('visible');
        expect($command->timeout)->toBe(5000);
        $requestIdToMatch = $command->requestId();
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: [
                        'waited' => true,
                        'type' => 'text',
                        'text' => 'Welcome to the site',
                    ],
                    error: null,
                    errorCode: null,
                    timestamp: new DateTimeImmutable,
                    instanceId: 'test-instance',
                    requestId: $requestIdToMatch
                ),
            ];
        }

        return [];
    });
    $ipcClient->shouldReceive('detach')->once();
    $ipcClient->shouldReceive('disconnect')->once();

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command with custom timeout
    $this->artisan('browser:wait', [
        'page_id' => 'test-page',
        '--text' => 'Welcome to the site',
        '--timeout' => '5000',
    ])
        ->expectsOutputToContain('Wait completed successfully')
        ->expectsOutputToContain('Type: text')
        ->expectsOutputToContain('Found text: Welcome to the site')
        ->assertExitCode(0);
});

it('fails when no wait condition is provided', function () {
    $this->artisan('browser:wait', [
        'page_id' => 'test-page',
    ])
        ->expectsOutputToContain('Must provide exactly one of: --selector, --url, or --text')
        ->assertExitCode(1);
});

it('fails when multiple wait conditions are provided', function () {
    $this->artisan('browser:wait', [
        'page_id' => 'test-page',
        '--selector' => '.button',
        '--url' => 'https://example.com',
    ])
        ->expectsOutputToContain('Must provide exactly one of: --selector, --url, or --text')
        ->assertExitCode(1);
});

it('outputs JSON when --json flag is provided', function () {
    // Create PID file for the test
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $pidDir = dirname($pidFile);
    if (! is_dir($pidDir)) {
        mkdir($pidDir, 0755, true);
    }
    file_put_contents($pidFile, json_encode(['pid' => getmypid(), 'port' => 9999]));

    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($command) use (&$requestIdToMatch) {
        $requestIdToMatch = $command->requestId();
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: [
                        'waited' => true,
                        'type' => 'selector',
                        'selector' => '.submit-button',
                    ],
                    error: null,
                    errorCode: null,
                    timestamp: new DateTimeImmutable,
                    instanceId: 'test-instance',
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
    $this->artisan('browser:wait', [
        'page_id' => 'test-page',
        '--selector' => '.submit-button',
        '--json' => true,
    ])
        ->expectsOutputToContain(json_encode([
            'success' => true,
            'message' => 'Wait completed successfully',
            'data' => [
                'waited' => true,
                'type' => 'selector',
                'selector' => '.submit-button',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);
});

it('handles timeout errors gracefully', function () {
    // Create PID file for the test
    $pidFile = app(FuelContext::class)->getPidFilePath();
    $pidDir = dirname($pidFile);
    if (! is_dir($pidDir)) {
        mkdir($pidDir, 0755, true);
    }
    file_put_contents($pidFile, json_encode(['pid' => getmypid(), 'port' => 9999]));

    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($command) use (&$requestIdToMatch) {
        $requestIdToMatch = $command->requestId();
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: false,
                    result: null,
                    error: 'Timeout waiting for selector .not-found',
                    errorCode: 'TIMEOUT',
                    timestamp: new DateTimeImmutable,
                    instanceId: 'test-instance',
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
    $this->artisan('browser:wait', [
        'page_id' => 'test-page',
        '--selector' => '.not-found',
        '--timeout' => '2000',
    ])
        ->expectsOutputToContain('Timeout waiting for selector .not-found')
        ->assertExitCode(1);
});

it('shows error when daemon is not running', function () {
    // Get the PID file path from test context
    $pidFile = app(FuelContext::class)->getPidFilePath();

    // Mock ConsumeIpcClient to report daemon not running
    $mockClient = Mockery::mock(ConsumeIpcClient::class);
    $mockClient->shouldReceive('isRunnerAlive')
        ->once()
        ->with($pidFile)
        ->andReturn(false);

    $this->app->instance(ConsumeIpcClient::class, $mockClient);

    $this->artisan('browser:wait', ['page_id' => 'test-page', '--selector' => '.button'])
        ->expectsOutputToContain('Consume daemon is not running')
        ->assertExitCode(1);
});
