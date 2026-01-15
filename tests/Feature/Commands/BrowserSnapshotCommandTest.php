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

it('sends snapshot command to daemon', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserSnapshotCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->interactiveOnly)->toBe(false);
        $requestIdToMatch = $cmd->requestId;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: [
                        'snapshot' => [
                            'ref' => '@e1',
                            'role' => 'WebArea',
                            'name' => 'Test Page',
                            'children' => [
                                [
                                    'ref' => '@e2',
                                    'role' => 'button',
                                    'name' => 'Submit',
                                ],
                                [
                                    'ref' => '@e3',
                                    'role' => 'textbox',
                                    'name' => 'Email',
                                ],
                            ],
                        ],
                    ],
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
    $this->artisan('browser:snapshot', [
        'page_id' => 'test-page',
    ])
        ->expectsOutputToContain('Page Accessibility Snapshot')
        ->expectsOutputToContain('@e1')
        ->expectsOutputToContain('[WebArea] "Test Page"')
        ->expectsOutputToContain('@e2')
        ->expectsOutputToContain('[button] "Submit"')
        ->expectsOutputToContain('@e3')
        ->expectsOutputToContain('[textbox] "Email"')
        ->assertExitCode(0);
});

it('sends snapshot command with interactive-only flag', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserSnapshotCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->interactiveOnly)->toBe(true);
        $requestIdToMatch = $cmd->requestId;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: [
                        'snapshot' => [
                            'ref' => '@e1',
                            'role' => 'button',
                            'name' => 'Submit',
                        ],
                    ],
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

    // Execute command with interactive flag
    $this->artisan('browser:snapshot', [
        'page_id' => 'test-page',
        '--interactive' => true,
    ])
        ->expectsOutputToContain('Page Accessibility Snapshot (interactive only)')
        ->expectsOutputToContain('@e1')
        ->expectsOutputToContain('[button] "Submit"')
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
        $requestIdToMatch = $cmd->requestId;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: [
                        'snapshot' => [
                            'ref' => '@e1',
                            'role' => 'WebArea',
                            'name' => 'Test Page',
                            'children' => [
                                [
                                    'ref' => '@e2',
                                    'role' => 'button',
                                    'name' => 'Submit',
                                ],
                            ],
                        ],
                    ],
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
    $this->artisan('browser:snapshot', [
        'page_id' => 'test-page',
        '--json' => true,
    ])
        ->expectsOutputToContain(json_encode([
            'success' => true,
            'message' => 'Snapshot captured successfully',
            'data' => [
                'snapshot' => [
                    'ref' => '@e1',
                    'role' => 'WebArea',
                    'name' => 'Test Page',
                    'children' => [
                        [
                            'ref' => '@e2',
                            'role' => 'button',
                            'name' => 'Submit',
                        ],
                    ],
                ],
            ],
        ]))
        ->assertExitCode(0);
});

it('handles empty snapshot gracefully', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        $requestIdToMatch = $cmd->requestId;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: [
                        'snapshot' => null,
                    ],
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
    $this->artisan('browser:snapshot', [
        'page_id' => 'test-page',
    ])
        ->expectsOutputToContain('Page Accessibility Snapshot')
        ->expectsOutputToContain('(no accessible elements found)')
        ->assertExitCode(0);
});

it('handles daemon errors gracefully', function () {
    // Create mock IPC client
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
        $requestIdToMatch = $cmd->requestId;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: false,
                    result: null,
                    error: 'Page not found',
                    errorCode: 'PAGE_NOT_FOUND',
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

    // Execute command that fails
    $this->artisan('browser:snapshot', [
        'page_id' => 'nonexistent-page',
    ])
        ->expectsOutputToContain('Page not found')
        ->assertExitCode(1);
});

it('shows error when daemon is not running', function () {
    // Create mock IPC client that simulates daemon not running
    $ipcClient = Mockery::mock(ConsumeIpcClient::class, function (Mockery\MockInterface $mock) {
        $mock->shouldReceive('isRunnerAlive')->andReturn(false);
    });

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command
    $this->artisan('browser:snapshot', [
        'page_id' => 'test-page',
    ])
        ->expectsOutputToContain('Consume daemon is not running')
        ->assertExitCode(1);
});
