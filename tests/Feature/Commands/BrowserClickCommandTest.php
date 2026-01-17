<?php

declare(strict_types=1);

use App\Ipc\Commands\BrowserClickCommand;
use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use App\Services\FuelContext;
use Mockery\MockInterface;

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

it('sends click command to daemon with selector', function (): void {
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        expect($cmd)->toBeInstanceOf(BrowserClickCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBe('button#submit');
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
                    result: ['message' => 'Clicked successfully'],
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

    // Execute command with selector (auto-detected)
    $this->artisan('browser:click', [
        'page_id' => 'test-page',
        'target' => 'button#submit',
    ])
        ->expectsOutputToContain('Clicked on: button#submit')
        ->assertExitCode(0);
});

it('sends click command to daemon with element ref', function (): void {
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        expect($cmd)->toBeInstanceOf(BrowserClickCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->selector)->toBeNull();
        expect($cmd->ref)->toBe('@e2');

        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['message' => 'Clicked successfully'],
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

    // Execute command with ref (auto-detected via @ prefix)
    $this->artisan('browser:click', [
        'page_id' => 'test-page',
        'target' => '@e2',
    ])
        ->expectsOutputToContain('Clicked on: @e2')
        ->assertExitCode(0);
});

it('outputs JSON when --json flag is provided', function (): void {
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['message' => 'Clicked successfully'],
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

    // Execute command with JSON output
    $this->artisan('browser:click', [
        'page_id' => 'test-page',
        'target' => 'button',
        '--json' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutput(json_encode([
            'success' => true,
            'message' => 'Clicked on: button',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
});

it('handles daemon errors gracefully', function (): void {
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: false,
                    result: null,
                    error: 'Element not found: button#nonexistent',
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

    // Execute command that fails
    $this->artisan('browser:click', [
        'page_id' => 'test-page',
        'target' => 'button#nonexistent',
    ])
        ->expectsOutputToContain('Element not found: button#nonexistent')
        ->assertExitCode(1);
});

it('shows error when daemon is not running', function (): void {
    $ipcClient = Mockery::mock(ConsumeIpcClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('isRunnerAlive')->andReturn(false);
    });

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command
    $this->artisan('browser:click', [
        'page_id' => 'test-page',
        'target' => 'button',
    ])
        ->expectsOutputToContain('Consume daemon is not running')
        ->assertExitCode(1);
});

it('auto-detects ref when target starts with @', function (): void {
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        expect($cmd)->toBeInstanceOf(BrowserClickCommand::class);
        // When target starts with @, it should be detected as ref
        expect($cmd->ref)->toBe('@e123');
        expect($cmd->selector)->toBeNull();

        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['message' => 'Clicked successfully'],
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

    $this->artisan('browser:click', [
        'page_id' => 'test-page',
        'target' => '@e123',
    ])
        ->assertExitCode(0);
});

it('auto-detects selector when target does not start with @', function (): void {
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        expect($cmd)->toBeInstanceOf(BrowserClickCommand::class);
        // When target doesn't start with @, it should be detected as selector
        expect($cmd->selector)->toBe('div.container > button');
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
                    result: ['message' => 'Clicked successfully'],
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

    $this->artisan('browser:click', [
        'page_id' => 'test-page',
        'target' => 'div.container > button',
    ])
        ->assertExitCode(0);
});
