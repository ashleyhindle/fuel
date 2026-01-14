<?php

declare(strict_types=1);

use App\Ipc\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use Illuminate\Support\Facades\Artisan;
use Mockery as m;

describe('browser:create command', function (): void {
    afterEach(function (): void {
        m::close();
    });

    it('shows error when daemon is not running', function (): void {
        // Mock ConsumeIpcClient to report daemon not running
        $mockClient = m::mock(ConsumeIpcClient::class);
        $mockClient->shouldReceive('isRunnerAlive')
            ->once()
            ->with(base_path('.fuel/consume-runner.pid'))
            ->andReturn(false);

        $this->app->instance(ConsumeIpcClient::class, $mockClient);

        $this->artisan('browser:create', ['context_id' => 'test-ctx'])
            ->expectsOutputToContain('Consume daemon is not running')
            ->assertExitCode(1);
    });

    it('creates browser context successfully', function (): void {
        // Create PID file in actual location
        $pidFile = base_path('.fuel/consume-runner.pid');
        $pidExisted = file_exists($pidFile);
        $originalContent = $pidExisted ? file_get_contents($pidFile) : null;

        file_put_contents($pidFile, json_encode(['pid' => getmypid(), 'port' => 9999]));

        // Mock ConsumeIpcClient
        $requestIdToMatch = null;
        $callCount = 0;
        $mockClient = m::mock(ConsumeIpcClient::class);
        $mockClient->shouldReceive('isRunnerAlive')
            ->once()
            ->with(base_path('.fuel/consume-runner.pid'))
            ->andReturn(true);
        $mockClient->shouldReceive('connect')->once()->with(9999);
        $mockClient->shouldReceive('attach')->once();
        $mockClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
        $mockClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
            $requestIdToMatch = $cmd->requestId();
        });
        $mockClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return [
                    new BrowserResponseEvent(
                        success: true,
                        result: ['contextId' => 'test-ctx'],
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
        $mockClient->shouldReceive('detach')->once();
        $mockClient->shouldReceive('disconnect')->once();

        $this->app->instance(ConsumeIpcClient::class, $mockClient);

        $exitCode = Artisan::call('browser:create', ['context_id' => 'test-ctx']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('created successfully');

        // Cleanup - restore original state
        if ($pidExisted) {
            file_put_contents($pidFile, $originalContent);
        } else {
            unlink($pidFile);
        }
    });

    it('creates browser context with viewport option', function (): void {
        // Create PID file in actual location
        $pidFile = base_path('.fuel/consume-runner.pid');
        $pidExisted = file_exists($pidFile);
        $originalContent = $pidExisted ? file_get_contents($pidFile) : null;

        file_put_contents($pidFile, json_encode(['pid' => getmypid(), 'port' => 9999]));

        // Mock ConsumeIpcClient
        $requestIdToMatch = null;
        $callCount = 0;
        $mockClient = m::mock(ConsumeIpcClient::class);
        $mockClient->shouldReceive('isRunnerAlive')->andReturn(true);
        $mockClient->shouldReceive('connect')->once();
        $mockClient->shouldReceive('attach')->once();
        $mockClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
        $mockClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
            $requestIdToMatch = $cmd->requestId();
        });
        $mockClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return [
                    new BrowserResponseEvent(
                        success: true,
                        result: ['contextId' => 'test-ctx', 'viewport' => ['width' => 1920, 'height' => 1080]],
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
        $mockClient->shouldReceive('detach')->once();
        $mockClient->shouldReceive('disconnect')->once();

        $this->app->instance(ConsumeIpcClient::class, $mockClient);

        $this->artisan('browser:create', [
            'context_id' => 'test-ctx',
            '--viewport' => '{"width":1920,"height":1080}',
        ])
            ->expectsOutputToContain('created successfully')
            ->assertExitCode(0);

        // Cleanup - restore original state
        if ($pidExisted) {
            file_put_contents($pidFile, $originalContent);
        } else {
            unlink($pidFile);
        }
    });

    it('shows error on invalid viewport JSON', function (): void {
        $this->artisan('browser:create', [
            'context_id' => 'test-ctx',
            '--viewport' => 'invalid-json',
        ])
            ->expectsOutputToContain('Invalid viewport JSON')
            ->assertExitCode(1);
    });

    it('handles browser operation failure', function (): void {
        // Create PID file in actual location
        $pidFile = base_path('.fuel/consume-runner.pid');
        $pidExisted = file_exists($pidFile);
        $originalContent = $pidExisted ? file_get_contents($pidFile) : null;

        file_put_contents($pidFile, json_encode(['pid' => getmypid(), 'port' => 9999]));

        // Mock ConsumeIpcClient
        $requestIdToMatch = null;
        $callCount = 0;
        $mockClient = m::mock(ConsumeIpcClient::class);
        $mockClient->shouldReceive('isRunnerAlive')->andReturn(true);
        $mockClient->shouldReceive('connect')->once();
        $mockClient->shouldReceive('attach')->once();
        $mockClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
        $mockClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
            $requestIdToMatch = $cmd->requestId();
        });
        $mockClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return [
                    new BrowserResponseEvent(
                        success: false,
                        result: null,
                        error: 'Browser context creation failed',
                        errorCode: 'CREATE_FAILED',
                        timestamp: new DateTimeImmutable,
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

        $this->artisan('browser:create', ['context_id' => 'test-ctx'])
            ->expectsOutputToContain('Browser context creation failed')
            ->assertExitCode(1);

        // Cleanup - restore original state
        if ($pidExisted) {
            file_put_contents($pidFile, $originalContent);
        } else {
            unlink($pidFile);
        }
    });

    it('outputs JSON when --json flag is used', function (): void {
        // Create PID file in actual location
        $pidFile = base_path('.fuel/consume-runner.pid');
        $pidExisted = file_exists($pidFile);
        $originalContent = $pidExisted ? file_get_contents($pidFile) : null;

        file_put_contents($pidFile, json_encode(['pid' => getmypid(), 'port' => 9999]));

        // Mock ConsumeIpcClient
        $requestIdToMatch = null;
        $callCount = 0;
        $mockClient = m::mock(ConsumeIpcClient::class);
        $mockClient->shouldReceive('isRunnerAlive')->andReturn(true);
        $mockClient->shouldReceive('connect')->once();
        $mockClient->shouldReceive('attach')->once();
        $mockClient->shouldReceive('getInstanceId')->andReturn('test-instance-id');
        $mockClient->shouldReceive('sendCommand')->once()->andReturnUsing(function ($cmd) use (&$requestIdToMatch) {
            $requestIdToMatch = $cmd->requestId();
        });
        $mockClient->shouldReceive('pollEvents')->andReturnUsing(function () use (&$requestIdToMatch, &$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return [
                    new BrowserResponseEvent(
                        success: true,
                        result: ['contextId' => 'test-ctx'],
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
        $mockClient->shouldReceive('detach')->once();
        $mockClient->shouldReceive('disconnect')->once();

        $this->app->instance(ConsumeIpcClient::class, $mockClient);

        Artisan::call('browser:create', ['context_id' => 'test-ctx', '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        expect($result)->toBeArray();
        expect($result)->toHaveKey('success');
        expect($result)->toHaveKey('context_id');
        expect($result['success'])->toBe(true);
        expect($result['context_id'])->toBe('test-ctx');

        // Cleanup - restore original state
        if ($pidExisted) {
            file_put_contents($pidFile, $originalContent);
        } else {
            unlink($pidFile);
        }
    });
});
