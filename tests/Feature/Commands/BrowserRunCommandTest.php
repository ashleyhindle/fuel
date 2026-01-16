<?php

declare(strict_types=1);

use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use App\Services\FuelContext;
use Mockery as m;

describe('browser:run command', function (): void {
    afterEach(function (): void {
        m::close();
    });

    it('shows error when daemon is not running', function (): void {
        // Get the PID file path from test context
        $pidFile = app(FuelContext::class)->getPidFilePath();

        // Mock ConsumeIpcClient to report daemon not running
        $mockClient = m::mock(ConsumeIpcClient::class);
        $mockClient->shouldReceive('isRunnerAlive')
            ->once()
            ->with($pidFile)
            ->andReturn(false);

        $this->app->instance(ConsumeIpcClient::class, $mockClient);

        $this->artisan('browser:run', ['page_id' => 'test-page', 'code' => 'return await page.title()'])
            ->expectsOutputToContain('Consume daemon is not running')
            ->assertExitCode(1);
    });

    it('runs Playwright code successfully', function (): void {
        // Create PID file at the expected location
        $pidFile = app(FuelContext::class)->getPidFilePath();
        $pidDir = dirname($pidFile);
        if (! is_dir($pidDir)) {
            mkdir($pidDir, 0755, true);
        }

        file_put_contents($pidFile, json_encode(['pid' => getmypid(), 'port' => 9999]));

        // Mock ConsumeIpcClient
        $requestIdToMatch = null;
        $callCount = 0;
        $mockClient = m::mock(ConsumeIpcClient::class);
        $mockClient->shouldReceive('isRunnerAlive')->andReturn(true);
        $mockClient->shouldReceive('connect')->once();
        $mockClient->shouldReceive('attach')->once();
        $mockClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
        $mockClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): void {
            $requestIdToMatch = $cmd->requestId();
        });
        $mockClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
            $callCount++;
            if ($callCount === 1) {
                return [
                    new BrowserResponseEvent(
                        success: true,
                        result: ['value' => 'Example Domain'],
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
        $mockClient->shouldReceive('detach')->once();
        $mockClient->shouldReceive('disconnect')->once();

        $this->app->instance(ConsumeIpcClient::class, $mockClient);

        $this->artisan('browser:run', ['page_id' => 'test-page', 'code' => 'return await page.title()'])
            ->expectsOutputToContain('Code executed successfully')
            ->assertExitCode(0);

        // Cleanup
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
    });

    it('handles browser operation failure', function (): void {
        // Create PID file at the expected location
        $pidFile = app(FuelContext::class)->getPidFilePath();
        $pidDir = dirname($pidFile);
        if (! is_dir($pidDir)) {
            mkdir($pidDir, 0755, true);
        }

        file_put_contents($pidFile, json_encode(['pid' => getmypid(), 'port' => 9999]));

        // Mock ConsumeIpcClient
        $requestIdToMatch = null;
        $callCount = 0;
        $mockClient = m::mock(ConsumeIpcClient::class);
        $mockClient->shouldReceive('isRunnerAlive')->andReturn(true);
        $mockClient->shouldReceive('connect')->once();
        $mockClient->shouldReceive('attach')->once();
        $mockClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
        $mockClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): void {
            $requestIdToMatch = $cmd->requestId();
        });
        $mockClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
            $callCount++;
            if ($callCount === 1) {
                return [
                    new BrowserResponseEvent(
                        success: false,
                        result: null,
                        error: 'Code execution failed',
                        errorCode: 'RUN_ERROR',
                        timestamp: new \DateTimeImmutable,
                        instanceId: 'test-instance-id',
                        requestId: $requestIdToMatch
                    ),
                ];
            }

            return [];
        });
        $mockClient->shouldReceive('detach')->once();
        $mockClient->shouldReceive('disconnect')->once();

        $this->app->instance(ConsumeIpcClient::class, $mockClient);

        $this->artisan('browser:run', ['page_id' => 'test-page', 'code' => 'throw new Error("test")'])
            ->expectsOutputToContain('Code execution failed')
            ->assertExitCode(1);

        // Cleanup
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
    });

    it('outputs JSON when --json flag is used', function (): void {
        // Create PID file at the expected location
        $pidFile = app(FuelContext::class)->getPidFilePath();
        $pidDir = dirname($pidFile);
        if (! is_dir($pidDir)) {
            mkdir($pidDir, 0755, true);
        }

        file_put_contents($pidFile, json_encode(['pid' => getmypid(), 'port' => 9999]));

        // Mock ConsumeIpcClient
        $requestIdToMatch = null;
        $callCount = 0;
        $mockClient = m::mock(ConsumeIpcClient::class);
        $mockClient->shouldReceive('isRunnerAlive')->andReturn(true);
        $mockClient->shouldReceive('connect')->once();
        $mockClient->shouldReceive('attach')->once();
        $mockClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
        $mockClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch): void {
            $requestIdToMatch = $cmd->requestId();
        });
        $mockClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount): array {
            $callCount++;
            if ($callCount === 1) {
                return [
                    new BrowserResponseEvent(
                        success: true,
                        result: ['value' => 'Example Domain'],
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
        $mockClient->shouldReceive('detach')->once();
        $mockClient->shouldReceive('disconnect')->once();

        $this->app->instance(ConsumeIpcClient::class, $mockClient);

        Artisan::call('browser:run', ['page_id' => 'test-page', 'code' => 'return await page.title()', '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveKey('success');
        expect($result)->toHaveKey('page_id');
        expect($result)->toHaveKey('result');
        expect($result['success'])->toBe(true);
        expect($result['page_id'])->toBe('test-page');
        expect($result['result'])->toBe(['value' => 'Example Domain']);

        // Cleanup
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
    });
});
