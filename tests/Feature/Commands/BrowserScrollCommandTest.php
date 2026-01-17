<?php

declare(strict_types=1);

use App\Ipc\Commands\BrowserScrollCommand;
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

it('scrolls down with default amount', function (): void {
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        expect($cmd)->toBeInstanceOf(BrowserScrollCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->direction)->toBe('down');
        expect($cmd->amount)->toBe(100); // Default amount

        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['scrolled' => true, 'direction' => 'down', 'amount' => 100],
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

    $this->artisan('browser:scroll', [
        'page_id' => 'test-page',
        'direction' => 'down',
    ])
        ->expectsOutputToContain('Scrolled down 100px')
        ->assertExitCode(0);
});

it('scrolls up with custom amount', function (): void {
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        expect($cmd)->toBeInstanceOf(BrowserScrollCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->direction)->toBe('up');
        expect($cmd->amount)->toBe(500);

        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['scrolled' => true, 'direction' => 'up', 'amount' => 500],
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

    $this->artisan('browser:scroll', [
        'page_id' => 'test-page',
        'direction' => 'up',
        'amount' => 500,
    ])
        ->expectsOutputToContain('Scrolled up 500px')
        ->assertExitCode(0);
});

it('scrolls left', function (): void {
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        expect($cmd)->toBeInstanceOf(BrowserScrollCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->direction)->toBe('left');
        expect($cmd->amount)->toBe(200);

        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['scrolled' => true, 'direction' => 'left', 'amount' => 200],
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

    $this->artisan('browser:scroll', [
        'page_id' => 'test-page',
        'direction' => 'left',
        'amount' => 200,
    ])
        ->expectsOutputToContain('Scrolled left 200px')
        ->assertExitCode(0);
});

it('scrolls right', function (): void {
    $requestIdToMatch = null;
    $callCount = 0;
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    $ipcClient->shouldReceive('connect')->once();
    $ipcClient->shouldReceive('attach')->once();
    $ipcClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
    $ipcClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): true {
        expect($cmd)->toBeInstanceOf(BrowserScrollCommand::class);
        expect($cmd->pageId)->toBe('test-page');
        expect($cmd->direction)->toBe('right');
        expect($cmd->amount)->toBe(300);

        $requestIdToMatch = $cmd->requestId();

        return true;
    });
    $ipcClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
        $callCount++;
        if ($callCount === 1) {
            return [
                new BrowserResponseEvent(
                    success: true,
                    result: ['scrolled' => true, 'direction' => 'right', 'amount' => 300],
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

    $this->artisan('browser:scroll', [
        'page_id' => 'test-page',
        'direction' => 'right',
        'amount' => 300,
    ])
        ->expectsOutputToContain('Scrolled right 300px')
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
                    result: ['scrolled' => true, 'direction' => 'down', 'amount' => 100],
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

    $this->artisan('browser:scroll', [
        'page_id' => 'test-page',
        'direction' => 'down',
        '--json' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutput(json_encode([
            'success' => true,
            'message' => 'Scrolled down 100px',
            'direction' => 'down',
            'amount' => 100,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
});

it('validates direction parameter', function (): void {
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    // No other expectations should be called because validation fails early

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:scroll', [
        'page_id' => 'test-page',
        'direction' => 'diagonal', // Invalid direction
    ])
        ->expectsOutputToContain('Invalid direction. Must be one of: up, down, left, right')
        ->assertExitCode(1);
});

it('validates amount parameter', function (): void {
    $ipcClient = Mockery::mock(ConsumeIpcClient::class);
    $ipcClient->shouldReceive('isRunnerAlive')->andReturn(true);
    // No other expectations should be called because validation fails early

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:scroll', [
        'page_id' => 'test-page',
        'direction' => 'down',
        'amount' => -50, // Negative amount
    ])
        ->expectsOutputToContain('Amount must be a positive number')
        ->assertExitCode(1);
});

it('shows error when daemon is not running', function (): void {
    $ipcClient = Mockery::mock(ConsumeIpcClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('isRunnerAlive')->andReturn(false);
    });

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    $this->artisan('browser:scroll', [
        'page_id' => 'test-page',
        'direction' => 'down',
    ])
        ->expectsOutputToContain('Consume daemon is not running')
        ->assertExitCode(1);
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
                    error: 'Page not found',
                    errorCode: 'NO_PAGE',
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

    $this->artisan('browser:scroll', [
        'page_id' => 'nonexistent-page',
        'direction' => 'down',
    ])
        ->expectsOutputToContain('Page not found')
        ->assertExitCode(1);
});
