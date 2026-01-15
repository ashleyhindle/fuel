<?php

declare(strict_types=1);

use App\Events\BrowserResponseEvent;
use App\Services\ConsumeIpcClient;
use App\Services\ProcessManager;
use DateTimeImmutable;
use Mockery\MockInterface;
use function Pest\Laravel\artisan;

it('sends wait command with selector to daemon', function () {
    // Create mock IPC client
    $ipcClient = Mockery::mock(ConsumeIpcClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('isDaemonRunning')->andReturn(true);
        $mock->shouldReceive('sendCommandAndWait')->andReturnUsing(function ($command) {
            expect($command)->toBeInstanceOf(App\Ipc\Commands\BrowserWaitCommand::class);
            expect($command->pageId)->toBe('test-page');
            expect($command->selector)->toBe('.submit-button');
            expect($command->url)->toBeNull();
            expect($command->text)->toBeNull();
            expect($command->state)->toBe('visible');
            expect($command->timeout)->toBe(30000);

            return new BrowserResponseEvent(
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
                requestId: 'test-request'
            );
        });
    });

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command
    artisan('browser:wait', [
        'page_id' => 'test-page',
        '--selector' => '.submit-button',
    ])
        ->expectsOutputToContain('Wait completed successfully')
        ->expectsOutputToContain('Type: selector')
        ->expectsOutputToContain('Found selector: .submit-button')
        ->assertExitCode(0);
});

it('sends wait command with URL to daemon', function () {
    // Create mock IPC client
    $ipcClient = Mockery::mock(ConsumeIpcClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('isDaemonRunning')->andReturn(true);
        $mock->shouldReceive('sendCommandAndWait')->andReturnUsing(function ($command) {
            expect($command)->toBeInstanceOf(App\Ipc\Commands\BrowserWaitCommand::class);
            expect($command->pageId)->toBe('test-page');
            expect($command->selector)->toBeNull();
            expect($command->url)->toBe('https://example.com/success');
            expect($command->text)->toBeNull();

            return new BrowserResponseEvent(
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
                requestId: 'test-request'
            );
        });
    });

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command
    artisan('browser:wait', [
        'page_id' => 'test-page',
        '--url' => 'https://example.com/success',
    ])
        ->expectsOutputToContain('Wait completed successfully')
        ->expectsOutputToContain('Type: url')
        ->expectsOutputToContain('Navigated to: https://example.com/success')
        ->assertExitCode(0);
});

it('sends wait command with text to daemon', function () {
    // Create mock IPC client
    $ipcClient = Mockery::mock(ConsumeIpcClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('isDaemonRunning')->andReturn(true);
        $mock->shouldReceive('sendCommandAndWait')->andReturnUsing(function ($command) {
            expect($command)->toBeInstanceOf(App\Ipc\Commands\BrowserWaitCommand::class);
            expect($command->pageId)->toBe('test-page');
            expect($command->selector)->toBeNull();
            expect($command->url)->toBeNull();
            expect($command->text)->toBe('Welcome to the site');
            expect($command->state)->toBe('visible');
            expect($command->timeout)->toBe(5000);

            return new BrowserResponseEvent(
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
                requestId: 'test-request'
            );
        });
    });

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command with custom timeout
    artisan('browser:wait', [
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
    artisan('browser:wait', [
        'page_id' => 'test-page',
    ])
        ->expectsOutputToContain('Must provide exactly one of: --selector, --url, or --text')
        ->assertExitCode(1);
});

it('fails when multiple wait conditions are provided', function () {
    artisan('browser:wait', [
        'page_id' => 'test-page',
        '--selector' => '.button',
        '--url' => 'https://example.com',
    ])
        ->expectsOutputToContain('Must provide exactly one of: --selector, --url, or --text')
        ->assertExitCode(1);
});

it('outputs JSON when --json flag is provided', function () {
    // Create mock IPC client
    $ipcClient = Mockery::mock(ConsumeIpcClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('isDaemonRunning')->andReturn(true);
        $mock->shouldReceive('sendCommandAndWait')->andReturn(
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
                requestId: 'test-request'
            )
        );
    });

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command with JSON output
    artisan('browser:wait', [
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
        ]))
        ->assertExitCode(0);
});

it('handles timeout errors gracefully', function () {
    // Create mock IPC client
    $ipcClient = Mockery::mock(ConsumeIpcClient::class, function (MockInterface $mock) {
        $mock->shouldReceive('isDaemonRunning')->andReturn(true);
        $mock->shouldReceive('sendCommandAndWait')->andReturn(
            new BrowserResponseEvent(
                success: false,
                result: null,
                error: 'Wait timeout after 5000ms',
                errorCode: 'TIMEOUT',
                timestamp: new DateTimeImmutable,
                instanceId: 'test-instance',
                requestId: 'test-request'
            )
        );
    });

    app()->instance(ConsumeIpcClient::class, $ipcClient);

    // Execute command that times out
    artisan('browser:wait', [
        'page_id' => 'test-page',
        '--selector' => '.not-found',
        '--timeout' => '5000',
    ])
        ->expectsOutputToContain('Wait failed: Wait timeout after 5000ms')
        ->expectsOutputToContain('Code: TIMEOUT')
        ->assertExitCode(1);
});