<?php

declare(strict_types=1);

use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;

beforeEach(function () {
    $pidFilePath = sys_get_temp_dir().'/fuel-test-'.uniqid().'.pid';
    $pidData = ['pid' => 12345, 'port' => 9876, 'started_at' => time()];
    file_put_contents($pidFilePath, json_encode($pidData));

    $fuelContext = Mockery::mock(\App\Services\FuelContext::class);
    $fuelContext->shouldReceive('getPidFilePath')->andReturn($pidFilePath);
    app()->instance(\App\Services\FuelContext::class, $fuelContext);

    $this->pidFilePath = $pidFilePath;
});

afterEach(function () {
    if (isset($this->pidFilePath) && file_exists($this->pidFilePath)) {
        unlink($this->pidFilePath);
    }
    Mockery::close();
});

it('sends snapshot command to daemon', function () {
    $capturedRequestId = ['id' => null];
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$capturedRequestId) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserSnapshotCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->interactiveOnly)->toBe(false);
        $capturedRequestId['id'] = $cmd->requestId();
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$capturedRequestId, &$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: [
                        'snapshot' => [
                            'text' => "- document [ref=@e1]:\n  - button \"Submit\" [ref=@e2]\n  - textbox \"Email\" [ref=@e3]",
                            'refCount' => 3,
                        ],
                    ],
                    error: null,
                    errorCode: null,
                    timestamp: new DateTimeImmutable,
                    instanceId: 'test-instance-id',
                    requestId: $capturedRequestId['id']
                ),
            ];
        }
        return [];
    });
    $ipcClient->shouldReceive('detach')->once();
    $ipcClient->shouldReceive('disconnect')->once();

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:snapshot', ['page_id' => 'test-page'])
        ->expectsOutputToContain('Page Accessibility Snapshot')
        ->expectsOutputToContain('@e1')
        ->expectsOutputToContain('document')
        ->expectsOutputToContain('button "Submit"')
        ->expectsOutputToContain('textbox "Email"')
        ->expectsOutputToContain('Found 3 elements')
        ->assertExitCode(0);
});

it('sends snapshot command with interactive-only flag', function () {
    $capturedRequestId = ['id' => null];
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$capturedRequestId) {
        expect($cmd)->toBeInstanceOf(App\Ipc\Commands\BrowserSnapshotCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->interactiveOnly)->toBe(true);
        $capturedRequestId['id'] = $cmd->requestId();
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$capturedRequestId, &$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: [
                        'snapshot' => [
                            'text' => "- button \"Submit\" [ref=@e1]",
                            'refCount' => 1,
                        ],
                    ],
                    error: null,
                    errorCode: null,
                    timestamp: new DateTimeImmutable,
                    instanceId: 'test-instance-id',
                    requestId: $capturedRequestId['id']
                ),
            ];
        }
        return [];
    });
    $ipcClient->shouldReceive('detach')->once();
    $ipcClient->shouldReceive('disconnect')->once();

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:snapshot', ['page_id' => 'test-page', '--interactive' => true])
        ->expectsOutputToContain('Page Accessibility Snapshot')
        ->expectsOutputToContain('@e1')
        ->expectsOutputToContain('button "Submit"')
        ->assertExitCode(0);
});

it('outputs JSON when --json flag is provided', function () {
    $capturedRequestId = ['id' => null];
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$capturedRequestId) {
        $capturedRequestId['id'] = $cmd->requestId();
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$capturedRequestId, &$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: [
                        'snapshot' => [
                            'text' => "- document [ref=@e1]:\n  - button \"Submit\" [ref=@e2]",
                            'refCount' => 2,
                        ],
                    ],
                    error: null,
                    errorCode: null,
                    timestamp: new DateTimeImmutable,
                    instanceId: 'test-instance-id',
                    requestId: $capturedRequestId['id']
                ),
            ];
        }
        return [];
    });
    $ipcClient->shouldReceive('detach')->once();
    $ipcClient->shouldReceive('disconnect')->once();

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:snapshot', ['page_id' => 'test-page', '--json' => true])
        ->expectsOutputToContain('"success":true')
        ->expectsOutputToContain('"text"')
        ->expectsOutputToContain('"refCount":2')
        ->assertExitCode(0);
});

it('handles empty snapshot gracefully', function () {
    $capturedRequestId = ['id' => null];
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$capturedRequestId) {
        $capturedRequestId['id'] = $cmd->requestId();
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$capturedRequestId, &$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['snapshot' => null],
                    error: null,
                    errorCode: null,
                    timestamp: new DateTimeImmutable,
                    instanceId: 'test-instance-id',
                    requestId: $capturedRequestId['id']
                ),
            ];
        }
        return [];
    });
    $ipcClient->shouldReceive('detach')->once();
    $ipcClient->shouldReceive('disconnect')->once();

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:snapshot', ['page_id' => 'test-page'])
        ->expectsOutputToContain('no accessibility tree available')
        ->assertExitCode(0);
});

it('handles daemon errors gracefully', function () {
    $capturedRequestId = ['id' => null];
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$capturedRequestId) {
        $capturedRequestId['id'] = $cmd->requestId();
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$capturedRequestId, &$callCount) {
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
                    requestId: $capturedRequestId['id']
                ),
            ];
        }
        return [];
    });
    $ipcClient->shouldReceive('detach')->once();
    $ipcClient->shouldReceive('disconnect')->once();

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:snapshot', ['page_id' => 'nonexistent-page'])
        ->expectsOutputToContain('Page not found')
        ->assertExitCode(1);
});

it('shows error when daemon is not running', function () {
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(false);

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:snapshot', ['page_id' => 'test-page'])
        ->expectsOutputToContain('Consume daemon is not running')
        ->assertExitCode(1);
});
